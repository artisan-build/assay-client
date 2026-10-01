<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Testing;

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\Internal\RecordInputProjector;
use ArtisanBuild\AssayClient\Jobs\ShipEnvelope;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Records\AttemptInput;
use ArtisanBuild\AssayClient\Records\OperationStartInput;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\Records\ToolCallInput;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\RecordType;
use ReflectionObject;
use Throwable;

final class DriverConformance
{
    /** @var list<class-string<RecordInput>> */
    private const array ALLOWED_INPUTS = [
        RunInput::class,
        AttemptInput::class,
        OperationStartInput::class,
        StepInput::class,
        ToolCallInput::class,
        SingleOperationInput::class,
    ];

    public static function assert(CaptureDriver $driver, DriverScenario $scenario): void
    {
        try {
            self::assertDriverIdentity($driver, $scenario);

            $collector = new ConformanceRecorder;
            $driver->register($collector);
            $scenario->exercise();
            $collector->flush();

            self::assertInputs($collector->records, $scenario);
            self::assertProjectedState($collector->records, $scenario);
        } catch (ConformanceViolation $violation) {
            throw $violation;
        } catch (Throwable $exception) {
            throw new ConformanceViolation('Driver conformance failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    private static function assertDriverIdentity(CaptureDriver $driver, DriverScenario $scenario): void
    {
        if ($driver->name() !== $scenario->driverName) {
            throw new ConformanceViolation('Driver name does not match the scenario.');
        }

        if ($driver->source() != $scenario->source) {
            throw new ConformanceViolation('Driver source metadata does not match the scenario.');
        }
    }

    /** @param list<RecordInput> $records */
    private static function assertInputs(array $records, DriverScenario $scenario): void
    {
        foreach ($records as $record) {
            $seen = [];

            if (self::containsCanary($record, $scenario->canary, $seen)) {
                throw new ConformanceViolation('The caller-supplied canary reached recorder input.');
            }

            if (! in_array($record::class, self::ALLOWED_INPUTS, true)) {
                throw new ConformanceViolation('A source object or unsupported record input reached the recorder: '.$record::class.'.');
            }

            self::assertRequiredMetadata($record);
        }

        self::assertTreeInheritance($records);

        if (serialize($records) !== serialize($scenario->expectedRecords)) {
            throw new ConformanceViolation('Reported records differ from the scenario; check omitted usage metrics, zero manufacturing, and linkage.');
        }

        if (! $scenario->supportsFailover) {
            return;
        }

        $attempts = [];
        $hasFailover = false;

        foreach ($records as $record) {
            if (($record instanceof RunInput || $record instanceof AttemptInput || $record instanceof StepInput || $record instanceof ToolCallInput)
                && $record->attempt !== null) {
                $attempts[$record->attempt] = true;
            }

            $hasFailover = $hasFailover || $record instanceof AttemptInput;
        }

        if (! $hasFailover || count($attempts) < 2) {
            throw new ConformanceViolation('The failover scenario must report a failover and at least two attempt ordinals.');
        }
    }

    /** @param list<RecordInput> $records */
    private static function assertTreeInheritance(array $records): void
    {
        /** @var array<string, array{sampled: bool, subject: string|null}> $contexts */
        $contexts = [];

        foreach ($records as $record) {
            if (! $record instanceof RunInput
                && ! $record instanceof AttemptInput
                && ! $record instanceof OperationStartInput
                && ! $record instanceof StepInput
                && ! $record instanceof ToolCallInput
                && ! $record instanceof SingleOperationInput) {
                continue;
            }

            $invocationId = $record->invocationId;

            if ($invocationId === null) {
                continue;
            }

            $parentInvocationId = $record->parent?->invocationId;
            $context = $contexts[$invocationId] ?? ($parentInvocationId === null ? null : ($contexts[$parentInvocationId] ?? null));

            if ($context === null
                && $record->capture === CaptureMode::Full
                && (($record instanceof RunInput && $record->type === RecordType::RunStart) || $record instanceof OperationStartInput)) {
                $context = ['sampled' => $record->sampled, 'subject' => $record->subject];
            }

            if ($context === null) {
                continue;
            }

            if ($record->sampled !== $context['sampled'] || $record->subject !== $context['subject']) {
                throw new ConformanceViolation('Every descendant must inherit the root sampled decision and frozen subject.');
            }

            $contexts[$invocationId] = $context;
        }
    }

    private static function assertRequiredMetadata(RecordInput $record): void
    {
        if (($record instanceof RunInput && $record->type === RecordType::RunEnd) || $record instanceof SingleOperationInput) {
            if ($record->outcome === null) {
                throw new ConformanceViolation('Every run.end must report an outcome.');
            }
        }

        if ($record instanceof ToolCallInput && $record->type === RecordType::ToolEnd && $record->outcome === null) {
            throw new ConformanceViolation('Every tool.end must report an outcome.');
        }

        if ($record instanceof ToolCallInput && $record->type === RecordType::ToolApproval && $record->approval === null) {
            throw new ConformanceViolation('Every tool.approval must report an approval.');
        }
    }

    /** @param array<int, true> $seen */
    private static function containsCanary(mixed $value, string $canary, array &$seen): bool
    {
        if (is_string($value)) {
            return str_contains($value, $canary);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsCanary($item, $canary, $seen)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_object($value)) {
            return false;
        }

        $objectId = spl_object_id($value);

        if (isset($seen[$objectId])) {
            return false;
        }

        $seen[$objectId] = true;

        foreach ((array) $value as $property) {
            if (self::containsCanary($property, $canary, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<RecordInput> $records */
    private static function assertProjectedState(array $records, DriverScenario $scenario): void
    {
        $dispatcher = new ConformanceDispatcher;
        $drops = new ConformanceDropCounter;
        $recorder = new BufferedRecorder(
            driver: $scenario->driverName,
            source: $scenario->source,
            client: new Client('artisan-build/assay-client', 'conformance'),
            environment: 'conformance',
            deploy: null,
            batchSize: max(1, count($records)),
            retryForSeconds: 86400,
            drops: $drops,
            dispatcher: $dispatcher,
            app: app(),
        );

        foreach ($records as $record) {
            $recorder->record($record);
        }

        $recorder->flush();

        if ($dispatcher->envelopes === [] || $drops->transportTotal() !== 0) {
            throw new ConformanceViolation('The supplied records could not be projected into a queued envelope.');
        }

        $actual = [];

        foreach ($dispatcher->envelopes as $json) {
            if (str_contains($json, $scenario->canary)) {
                throw new ConformanceViolation('The caller-supplied canary reached the projected envelope.');
            }

            $serializedJob = serialize(new ShipEnvelope($json, time() + 86400));

            if (str_contains($serializedJob, $scenario->canary)) {
                throw new ConformanceViolation('The caller-supplied canary reached queued job state.');
            }

            $job = unserialize($serializedJob, ['allowed_classes' => [ShipEnvelope::class]]);

            if (! $job instanceof ShipEnvelope) {
                throw new ConformanceViolation('The queued job could not be reconstructed safely.');
            }

            self::assertPrimitiveJobState($job);

            foreach (EnvelopeCodec::decode($json)->records as $record) {
                $array = $record->toArray();
                unset($array['record_id'], $array['sampled'], $array['subject']);
                $actual[] = $array;
            }
        }

        $projector = new RecordInputProjector;
        $expected = [];

        foreach ($scenario->expectedRecords as $record) {
            $array = $projector->project($record, $scenario->driverName)->toArray();
            unset($array['record_id'], $array['sampled'], $array['subject']);
            $expected[] = $array;
        }

        if ($actual !== $expected) {
            throw new ConformanceViolation('Usage metadata or run/attempt/step/tool/parent linkage did not survive projection.');
        }
    }

    private static function assertPrimitiveJobState(ShipEnvelope $job): void
    {
        $reflection = new ReflectionObject($job);

        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($job);

            if (! is_scalar($value) && $value !== null) {
                throw new ConformanceViolation('Queue job state contains a non-primitive value in '.$property->getName().'.');
            }
        }
    }
}

/** @internal */
final class ConformanceRecorder implements Recorder
{
    /** @var list<RecordInput> */
    public array $records = [];

    public function record(RecordInput $input): void
    {
        $this->records[] = $input;
    }

    public function flush(): void {}
}

/** @internal */
final class ConformanceDispatcher implements EnvelopeDispatcher
{
    /** @var list<string> */
    public array $envelopes = [];

    public function dispatch(string $envelopeJson, int $retryUntilUnix): void
    {
        $this->envelopes[] = $envelopeJson;
    }
}

/** @internal */
final class ConformanceDropCounter implements DropCounter
{
    private int $transport = 0;

    private int $hook = 0;

    public function transportTotal(): int
    {
        return $this->transport;
    }

    public function hookTotal(): int
    {
        return $this->hook;
    }

    public function incrementTransport(): int
    {
        return ++$this->transport;
    }

    public function incrementHook(): int
    {
        return ++$this->hook;
    }
}
