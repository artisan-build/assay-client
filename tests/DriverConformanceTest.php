<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Records\AttemptInput;
use ArtisanBuild\AssayClient\Records\OperationStartInput;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\Records\ToolCallInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Testing\ConformanceViolation;
use ArtisanBuild\AssayClient\Testing\DriverConformance;
use ArtisanBuild\AssayClient\Testing\DriverScenario;
use ArtisanBuild\AssayClient\Testing\FakeDriver;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\FailureCapture;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\ReplayInputOmission;

function conformanceRecords(?int $manufacturedOutput = null, string $failureClass = RuntimeException::class): array
{
    $at = new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00');
    $parent = new ParentLink('parent-invocation', 'parent-tool');
    $model = new ModelInfo(requested: 'requested-model', responded: 'responded-model', provider: 'provider-a');
    $requestedModel = new ModelInfo(requested: 'requested-model', provider: 'provider-a');

    return [
        new RunInput(RecordType::RunStart, 'run-1', 1, $at, parent: $parent, subject: 'subject-1', model: $model, agent: 'App\\Ai\\SupportAgent'),
        new StepInput(RecordType::StepEnd, 'run-1', 1, 0, $at, parent: $parent, subject: 'subject-1', usage: new Usage(
            inputTokens: 11,
            outputTokens: 12,
            cacheReadInputTokens: 3,
            cacheWriteInputTokens: 4,
            reasoningTokens: 5,
        ), model: $model, agent: 'App\\Ai\\SupportAgent', durationMs: 42.5, finishReason: FinishReason::ToolCalls),
        new ToolCallInput(RecordType::ToolEnd, 'run-1', 1, 'tool-1', $at, step: 0, parent: $parent, subject: 'subject-1', agent: 'App\\Ai\\SupportAgent', tool: 'lookup_order', durationMs: 7.25, outcome: Outcome::Completed),
        new ToolCallInput(RecordType::ToolApproval, 'run-1', 1, 'tool-2', $at, step: 0, parent: $parent, subject: 'subject-1', agent: 'App\\Ai\\SupportAgent', tool: 'refund_order', approval: Approval::Requested),
        new AttemptInput('run-1', 1, $at, operation: Operation::Agent, parent: $parent, subject: 'subject-1', model: $model, agent: 'App\\Ai\\SupportAgent', failureClass: $failureClass),
        new RunInput(RecordType::RunStart, 'run-1', 2, $at, parent: $parent, subject: 'subject-1', model: $model),
        new RunInput(RecordType::RunEnd, 'run-1', 2, $at, capture: CaptureMode::Usage, parent: $parent, subject: 'subject-1', usage: new Usage(inputTokens: 2), model: $model, agent: 'App\\Ai\\SupportAgent', finishReason: FinishReason::Error, outcome: Outcome::Failed, failureClass: RuntimeException::class, failureCapture: FailureCapture::Complete, replayInputsOmitted: [ReplayInputOmission::Attachments]),
        new OperationStartInput(Operation::Embeddings, 'embeddings-1', $at, $parent, CaptureMode::Usage, false, null, $requestedModel),
        new SingleOperationInput(Operation::Embeddings, 'embeddings-1', $at, parent: $parent, usage: new Usage(inputTokens: 8), durationMs: 1.5, finishReason: FinishReason::Unknown, outcome: Outcome::Completed),
        new OperationStartInput(Operation::Image, 'image-a', $at, $parent, CaptureMode::Usage, false, null, $requestedModel),
        new AttemptInput('image-a', null, $at, operation: Operation::Image, parent: $parent, model: $model, failureClass: $failureClass),
        new OperationStartInput(Operation::Image, 'image-b', $at, $parent, CaptureMode::Usage, false, null, $requestedModel),
        new SingleOperationInput(Operation::Image, 'image-b', $at, parent: $parent, usage: new Usage(imageOutputTokens: 9), outcome: Outcome::Completed),
        new OperationStartInput(Operation::Audio, 'audio-1', $at, $parent, CaptureMode::Usage, false, null, $requestedModel),
        new SingleOperationInput(Operation::Audio, 'audio-1', $at, parent: $parent, usage: new Usage(outputTokens: 10), outcome: Outcome::Completed),
        new OperationStartInput(Operation::Transcription, 'transcription-1', $at, $parent, CaptureMode::Usage, false, null, $requestedModel),
        new SingleOperationInput(Operation::Transcription, 'transcription-1', $at, parent: $parent, usage: new Usage(audioSeconds: 3.5), outcome: Outcome::Completed),
        new OperationStartInput(Operation::Reranking, 'reranking-1', $at, $parent, CaptureMode::Usage, false, null, $requestedModel),
        new SingleOperationInput(Operation::Reranking, 'reranking-1', $at, parent: $parent, usage: new Usage(outputTokens: $manufacturedOutput, searchUnits: 4.125), outcome: Outcome::Completed),
        new OperationStartInput(Operation::Classification, 'classification-1', $at, $parent, CaptureMode::Usage, false, null, $requestedModel),
        new SingleOperationInput(Operation::Classification, 'classification-1', $at, parent: $parent, usage: new Usage(inputTokens: 13, outputTokens: 14), outcome: Outcome::Completed),
        new AttemptInput(null, null, $at, model: new ModelInfo(requested: 'unmatched-model', provider: 'provider-b'), failureClass: $failureClass),
    ];
}

it('passes a source independent fake with all operations metrics failover and linkage', function (): void {
    $source = new SourceInfo('vendor/fake-source', '1.2.3');
    $records = conformanceRecords();
    $receivedCanary = null;
    $driver = new FakeDriver('fake', $source, static function (Throwable $failure) use (&$receivedCanary): array {
        $receivedCanary = $failure->getMessage();

        return conformanceRecords(failureClass: $failure::class);
    });
    $canary = 'content-canary-must-not-ship';

    DriverConformance::assert($driver, new DriverScenario(
        driverName: 'fake',
        source: $source,
        exercise: static function (Throwable $failure) use ($driver): void {
            $driver->capture($failure);
        },
        expectedRecords: $records,
        canary: $canary,
        supportsFailover: true,
    ));

    expect($receivedCanary)->toBe($canary);
});

it('fails a deliberately broken driver that leaks a source object', function (): void {
    $source = new SourceInfo('vendor/broken-source', '1.0.0');
    $sourceEvent = new stdClass;
    $leaky = new class($sourceEvent) implements RecordInput
    {
        public function __construct(public object $sourceEvent) {}
    };
    $driver = new FakeDriver('broken-leak', $source, static fn (Throwable $failure): array => [$leaky]);

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'broken-leak',
        source: $source,
        exercise: static function (Throwable $failure) use ($driver): void {
            $driver->capture($failure);
        },
        expectedRecords: [$leaky],
        canary: 'leak-canary',
    )))->toThrow(ConformanceViolation::class, 'source object');
});

it('fails a deliberately broken driver that reports an inapplicable metric', function (): void {
    $source = new SourceInfo('vendor/broken-source', '1.0.0');
    $expected = conformanceRecords();
    $driver = new FakeDriver('broken-metric', $source, static fn (Throwable $failure): array => conformanceRecords(0));

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'broken-metric',
        source: $source,
        exercise: static function (Throwable $failure) use ($driver): void {
            $driver->capture($failure);
        },
        expectedRecords: $expected,
        canary: 'zero-canary',
        supportsFailover: true,
    )))->toThrow(ConformanceViolation::class, 'not applicable to reranking');
});

it('checks source independent sampling and subject inheritance non-vacuously', function (bool $broken): void {
    $source = new SourceInfo('vendor/tree-source', '1.0.0');
    $at = new DateTimeImmutable;
    $subject = 'user:tree';
    $records = [
        new RunInput(RecordType::RunStart, 'root', 1, $at, CaptureMode::Full, true, subject: $subject, agent: 'RootAgent'),
        new RunInput(RecordType::RunStart, 'child', 1, $at, CaptureMode::Full, $broken ? false : true, new ParentLink('root', 'tool'), $broken ? 'changed' : $subject, agent: 'ChildAgent'),
        new OperationStartInput(Operation::Embeddings, 'embedding', $at, new ParentLink('child', 'child-tool'), CaptureMode::Full, true, $subject, new ModelInfo(requested: 'model', provider: 'provider')),
    ];
    $driver = new FakeDriver('tree', $source, static fn (Throwable $failure): array => $records);
    $assert = fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'tree',
        source: $source,
        exercise: static function (Throwable $failure) use ($driver): void {
            $driver->capture($failure);
        },
        expectedRecords: $records,
        canary: 'tree-canary',
    ));

    $broken
        ? expect($assert)->toThrow(ConformanceViolation::class, 'inherit the root sampled decision and frozen subject')
        : expect($assert)->not->toThrow(Throwable::class);
})->with([false, true]);

it('fails a deliberately broken driver that omits operation from an attributed failover', function (): void {
    $source = new SourceInfo('vendor/broken-source', '1.0.0');
    $driver = new FakeDriver('broken-failover', $source, static fn (Throwable $failure): array => [
        new AttemptInput('failed-invocation-a', null, new DateTimeImmutable, model: new ModelInfo(requested: 'model-a', provider: 'provider-a')),
    ]);

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'broken-failover',
        source: $source,
        exercise: static function (Throwable $failure) use ($driver): void {
            $driver->capture($failure);
        },
        expectedRecords: conformanceRecords(),
        canary: 'failover-canary',
        supportsFailover: true,
    )))->toThrow(ConformanceViolation::class, 'Attributed failover requires an operation');
});

it('fails a deliberately broken projector that leaks an exception message', function (): void {
    $source = new SourceInfo('vendor/broken-source', '1.0.0');
    $at = new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00');
    $expected = new RunInput(
        type: RecordType::RunStart,
        invocationId: 'run-1',
        attempt: 1,
        at: $at,
        agent: RuntimeException::class,
    );
    $driver = new FakeDriver('broken-canary', $source, static fn (Throwable $failure): array => [
        new RunInput(
            type: RecordType::RunStart,
            invocationId: 'run-1',
            attempt: 1,
            at: $at,
            agent: $failure->getMessage(),
        ),
    ]);

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'broken-canary',
        source: $source,
        exercise: static function (Throwable $failure) use ($driver): void {
            $driver->capture($failure);
        },
        expectedRecords: [$expected],
        canary: 'projected-source-canary',
    )))->toThrow(ConformanceViolation::class, 'canary reached recorder input');
});

it('keeps unknown source absence inert', function (): void {
    $registered = false;
    $driver = new class($registered) implements CaptureDriver
    {
        public function __construct(private bool &$registered) {}

        public function name(): string
        {
            return 'absent';
        }

        public function source(): ?SourceInfo
        {
            return null;
        }

        public function register(Recorder $recorder): void
        {
            $this->registered = true;
        }
    };

    expect($driver->source())->toBeNull()
        ->and($registered)->toBeFalse();
});
