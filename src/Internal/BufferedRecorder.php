<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Sampler;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\FailureCapture;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadDisposition;
use ArtisanBuild\BuiltForCloudContracts\PayloadFilter;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use stdClass;
use Throwable;

final class BufferedRecorder implements Recorder, TreeLifecycleRecorder
{
    /** @var list<RecordV1> */
    private array $records = [];

    /** @var array<string, array<string, true>> */
    private array $sentMessageHashes = [];

    /** @var array<string, string> */
    private array $invocationRoots = [];

    /** @var array<string, array{sampled: bool, subject: string, agent_tree: bool, failed: bool, truncated: bool, bytes: int, buffer: array<string, array{target_record_id: UuidV7, invocation_id: string, content: Content, bytes: int}>}> */
    private array $rootStates = [];

    /** @var array<string, Closure|null> */
    private array $retainedTreeContexts = [];

    /** @var array<string, array{terminal_at: DateTimeImmutable, invocations: array<string, true>}> */
    private array $retainedRoots = [];

    private readonly Sampler $sampler;

    private readonly Closure $clock;

    /** @var array<string, float> */
    private readonly array $agentSampleRates;

    /** @param array<string, int|float> $agentSampleRates */
    public function __construct(
        private readonly string $driver,
        private readonly SourceInfo $source,
        private readonly Client $client,
        private readonly string $environment,
        private readonly ?string $deploy,
        private readonly int $batchSize,
        private readonly int $retryForSeconds,
        private readonly DropCounter $drops,
        private readonly EnvelopeDispatcher $dispatcher,
        private readonly Container $app,
        private readonly RecordInputProjector $projector = new RecordInputProjector,
        private readonly int $maxBatchBytes = 4_194_304,
        ?Sampler $sampler = null,
        private readonly float $sampleRate = 1.0,
        array $agentSampleRates = [],
        private readonly bool $alwaysOnFailure = true,
        private readonly int $failureBufferBytes = 524_288,
        private readonly string $subjectContextKey = 'assay.subject',
        private readonly int $maxRetainedRoots = 128,
        private readonly int $maxRetainedBufferBytes = 67_108_864,
        private readonly int $retainedStateTtlSeconds = 60,
        ?Closure $clock = null,
    ) {
        $this->sampler = $sampler ?? new RandomSampler;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;
        $normalizedAgentRates = [];

        foreach ($agentSampleRates as $agent => $rate) {
            $normalizedAgentRates[$agent] = (float) $rate;
        }

        $this->agentSampleRates = $normalizedAgentRates;

        if ($this->driver === '' || $this->environment === '') {
            throw new InvalidArgumentException('Driver and environment must be non-empty.');
        }

        if ($this->batchSize < 1
            || $this->retryForSeconds < 1
            || $this->maxBatchBytes < 1
            || $this->failureBufferBytes < 1
            || $this->maxRetainedRoots < 1
            || $this->maxRetainedBufferBytes < 1
            || $this->retainedStateTtlSeconds < 1) {
            throw new InvalidArgumentException('Batch size, retry bound, buffer bounds, and retained lifecycle bounds must be positive.');
        }

        if ($this->batchSize > 500) {
            throw new InvalidArgumentException('Assay batch_size must not exceed 500.');
        }

        if (! is_finite($this->sampleRate) || $this->sampleRate < 0.0 || $this->sampleRate > 1.0) {
            throw new InvalidArgumentException('Assay sample_rate must be between 0 and 1.');
        }

        foreach ($this->agentSampleRates as $rate) {
            if (! is_finite($rate) || $rate < 0.0 || $rate > 1.0) {
                throw new InvalidArgumentException('Assay agent sample rates must map non-empty class names to floats between 0 and 1.');
            }
        }

        if ($this->subjectContextKey === '') {
            throw new InvalidArgumentException('Assay subject context key must be non-empty.');
        }
    }

    public function record(RecordInput $input): void
    {
        $root = null;
        $retainContext = false;

        try {
            $this->sweepTreeContexts();
            $projected = $this->projector->project($input, $this->driver);
            $retainContext = $projected->invocationId !== null && array_key_exists($projected->invocationId, $this->retainedTreeContexts);
            [$projected, $root] = $this->applyTreeContext($projected);
            $failure = $this->isFailureTrigger($projected);

            if ($root !== null && $failure && $this->usesFailureBuffer($projected, $root)) {
                $state = $this->rootStates[$root];
                $state['failed'] = true;
                $this->rootStates[$root] = $state;

                if ($projected->type === RecordType::RunEnd) {
                    $projected = $this->copy(
                        $projected,
                        failureCapture: $state['truncated']
                            ? FailureCapture::Truncated
                            : FailureCapture::Complete,
                    );
                }
            }

            try {
                $record = $this->filter($projected);
            } catch (Throwable) {
                $this->incrementHookSafely();

                if ($root !== null && $failure && $this->usesFailureBuffer($projected, $root)) {
                    $this->flushFailureBuffer($root);
                }

                return;
            }

            if ($record === null) {
                $this->incrementHookSafely();

                if ($root !== null && $failure && $this->usesFailureBuffer($projected, $root)) {
                    $this->flushFailureBuffer($root);
                }

                return;
            }

            $record = $this->deduplicateMessages($record);

            if ($record->capture === CaptureMode::Full && ! $record->sampled) {
                if ($root !== null && $this->usesFailureBuffer($record, $root)) {
                    $this->buffer($root, $record);

                    if ($failure && $record->type === RecordType::RunEnd) {
                        $record = $this->copy(
                            $record,
                            failureCapture: $this->rootStates[$root]['truncated']
                                ? FailureCapture::Truncated
                                : FailureCapture::Complete,
                        );
                    }

                    $this->enqueue($this->copy($record, capture: CaptureMode::Usage, content: null));

                    if ($this->rootStates[$root]['failed']) {
                        $this->flushFailureBuffer($root);
                    }
                } else {
                    $this->enqueue($this->copy($record, capture: CaptureMode::Usage, content: null));
                }
            } else {
                $this->enqueue($record);
            }
        } catch (Throwable) {
            $this->incrementTransportSafely();
        } finally {
            if (isset($projected)
                && $projected->type === RecordType::RunEnd
                && $projected->operation === Operation::Agent
                && $projected->invocationId !== null) {
                unset($this->sentMessageHashes[$projected->invocationId]);
            }

            if (isset($projected)) {
                $this->finishTreeContext($projected, $root, $retainContext);
            }
        }
    }

    public function attach(ContentAttachInput $input): void
    {
        try {
            $this->enqueue($this->projector->projectAttach($input));
        } catch (Throwable) {
            $this->incrementTransportSafely();
        }
    }

    public function retainTreeContext(string $invocationId, ?Closure $onForcedRelease = null): void
    {
        $this->sweepTreeContexts();
        $this->retainedTreeContexts[$invocationId] = $onForcedRelease;
    }

    public function releaseTreeContext(string $invocationId): void
    {
        $this->sweepTreeContexts();
        unset($this->retainedTreeContexts[$invocationId]);
        $root = $this->invocationRoots[$invocationId] ?? null;

        if ($root === null) {
            return;
        }

        if (isset($this->retainedRoots[$root])) {
            unset($this->retainedRoots[$root]['invocations'][$invocationId]);

            if ($this->retainedRoots[$root]['invocations'] === []) {
                unset($this->retainedRoots[$root]);
            }
        }

        if ($invocationId === $root) {
            $this->pruneRoot($root);
        } else {
            unset($this->invocationRoots[$invocationId], $this->sentMessageHashes[$invocationId]);
        }
    }

    public function sweepTreeContexts(): void
    {
        $expiresAt = $this->now()->getTimestamp() - $this->retainedStateTtlSeconds;

        foreach ($this->retainedRoots as $root => $retained) {
            if ($retained['terminal_at']->getTimestamp() <= $expiresAt) {
                $this->evictRetainedRoot($root);
            }
        }
    }

    /** @return array{RecordV1, string|null} */
    private function applyTreeContext(RecordV1 $record): array
    {
        if ($record->invocationId === null) {
            return [$record, null];
        }

        $root = $this->invocationRoots[$record->invocationId] ?? null;
        $parentRoot = $record->parentInvocationId === null
            ? null
            : ($this->invocationRoots[$record->parentInvocationId] ?? null);

        if ($root === null && $parentRoot !== null) {
            $root = $parentRoot;
            $this->invocationRoots[$record->invocationId] = $root;
        }

        if ($root === null && $record->type === RecordType::RunStart) {
            $root = $record->invocationId;
            $rate = $record->operation === Operation::Agent && $record->agent !== null
                ? ($this->agentSampleRates[$record->agent] ?? $this->sampleRate)
                : $this->sampleRate;
            $subject = $this->contextSubject() ?? $record->subject ?? 'unknown';
            $this->rootStates[$root] = [
                'sampled' => $record->capture === CaptureMode::Full && $this->sampler->sample($rate),
                'subject' => $subject,
                'agent_tree' => $record->operation === Operation::Agent,
                'failed' => false,
                'truncated' => false,
                'bytes' => 0,
                'buffer' => [],
            ];
            $this->invocationRoots[$record->invocationId] = $root;
        }

        if ($root === null || ! isset($this->rootStates[$root])) {
            return [$record, null];
        }

        $state = $this->rootStates[$root];

        return [
            $this->copy(
                $record,
                sampled: $record->capture === CaptureMode::Full && $state['sampled'],
                subject: $state['subject'],
            ),
            $root,
        ];
    }

    private function contextSubject(): ?string
    {
        try {
            $subject = Context::get($this->subjectContextKey);

            return is_string($subject) && $subject !== '' ? $subject : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isFailureTrigger(RecordV1 $record): bool
    {
        return $record->type === RecordType::StepFail
            || ($record->type === RecordType::RunEnd
                && $record->operation === Operation::Agent
                && $record->outcome === Outcome::Failed);
    }

    private function usesFailureBuffer(RecordV1 $record, string $root): bool
    {
        return $this->alwaysOnFailure
            && $record->capture === CaptureMode::Full
            && ! $record->sampled
            && ($this->rootStates[$root]['agent_tree'] ?? false);
    }

    private function buffer(string $root, RecordV1 $record): void
    {
        if ($record->content === null || $record->invocationId === null) {
            return;
        }

        $bytes = strlen(json_encode(
            ['content' => $record->content->jsonSerialize()],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
        ));
        $state = $this->rootStates[$root];
        $state['buffer'][(string) $record->recordId] = [
            'target_record_id' => $record->recordId,
            'invocation_id' => $record->invocationId,
            'content' => $record->content,
            'bytes' => $bytes,
        ];
        $state['bytes'] += $bytes;

        while ($state['bytes'] > $this->failureBufferBytes) {
            $evictedId = array_key_first($state['buffer']);

            if ($evictedId === null) {
                break;
            }

            $state['bytes'] -= $state['buffer'][$evictedId]['bytes'];
            unset($state['buffer'][$evictedId]);
            $state['truncated'] = true;
        }

        $this->rootStates[$root] = $state;

        if (isset($this->retainedRoots[$root])) {
            $this->enforceRetainedBounds();
        }
    }

    private function flushFailureBuffer(string $root): void
    {
        $state = $this->rootStates[$root];

        foreach ($state['buffer'] as $content) {
            $this->attach(new ContentAttachInput(
                targetRecordId: $content['target_record_id'],
                invocationId: $content['invocation_id'],
                at: new DateTimeImmutable,
                content: $content['content'],
            ));
        }

        $state['buffer'] = [];
        $state['bytes'] = 0;
        $this->rootStates[$root] = $state;
    }

    private function enqueue(RecordV1 $record): void
    {
        if (! $this->fits([$record])) {
            $this->incrementTransportSafely();

            return;
        }

        if ($this->records !== [] && ! $this->fits([...$this->records, $record])) {
            $this->flush();
        }

        $this->records[] = $record;

        if (count($this->records) >= $this->batchSize) {
            $this->flush();
        }
    }

    private function finishTreeContext(RecordV1 $record, ?string $root, bool $retainContext): void
    {
        if ($root === null || $record->invocationId === null) {
            return;
        }

        if ($record->type !== RecordType::RunEnd) {
            return;
        }

        if ($retainContext) {
            $this->retainRoot($root, $record->invocationId);

            return;
        }

        if ($record->invocationId === $root) {
            $this->pruneRoot($root);
        } else {
            unset($this->invocationRoots[$record->invocationId]);
        }
    }

    private function pruneRoot(string $root): void
    {
        foreach ($this->invocationRoots as $invocationId => $candidate) {
            if ($candidate === $root) {
                unset(
                    $this->invocationRoots[$invocationId],
                    $this->sentMessageHashes[$invocationId],
                    $this->retainedTreeContexts[$invocationId],
                );
            }
        }

        foreach ($this->retainedRoots[$root]['invocations'] ?? [] as $invocationId => $_) {
            unset($this->retainedTreeContexts[$invocationId]);
        }

        unset($this->rootStates[$root], $this->retainedTreeContexts[$root], $this->retainedRoots[$root]);
    }

    private function retainRoot(string $root, string $invocationId): void
    {
        $this->retainedRoots[$root] ??= [
            'terminal_at' => $this->now(),
            'invocations' => [],
        ];

        $this->retainedRoots[$root]['invocations'][$invocationId] = true;
        $this->enforceRetainedBounds();
    }

    private function enforceRetainedBounds(): void
    {
        while (count($this->retainedRoots) > $this->maxRetainedRoots
            || $this->retainedBufferBytes() > $this->maxRetainedBufferBytes) {
            $oldest = array_key_first($this->retainedRoots);

            if ($oldest === null) {
                return;
            }

            $this->evictRetainedRoot($oldest);
        }
    }

    private function retainedBufferBytes(): int
    {
        $bytes = 0;

        foreach ($this->retainedRoots as $root => $_) {
            $bytes += $this->rootStates[$root]['bytes'] ?? 0;
        }

        return $bytes;
    }

    private function evictRetainedRoot(string $root): void
    {
        foreach ($this->retainedRoots[$root]['invocations'] ?? [] as $invocationId => $_) {
            try {
                ($this->retainedTreeContexts[$invocationId] ?? null)?->__invoke();
            } catch (Throwable) {
                // Lifecycle cleanup continues even when a collaborator fails.
            }
        }

        $this->pruneRoot($root);
        $this->incrementTransportSafely();
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private function copy(
        RecordV1 $record,
        CaptureMode|false $capture = false,
        ?bool $sampled = null,
        string|false|null $subject = false,
        Content|false|null $content = false,
        FailureCapture|false|null $failureCapture = false,
    ): RecordV1 {
        return new RecordV1(
            recordId: $record->recordId,
            source: $record->source,
            type: $record->type,
            operation: $record->operation,
            at: $record->at,
            capture: $capture === false ? $record->capture : $capture,
            sampled: $sampled ?? $record->sampled,
            invocationId: $record->invocationId,
            attempt: $record->attempt,
            parentInvocationId: $record->parentInvocationId,
            parentToolInvocationId: $record->parentToolInvocationId,
            step: $record->step,
            toolInvocationId: $record->toolInvocationId,
            subject: $subject === false ? $record->subject : $subject,
            usage: $record->usage,
            model: $record->model,
            content: $content === false ? $record->content : $content,
            agent: $record->agent,
            tool: $record->tool,
            durationMs: $record->durationMs,
            finishReason: $record->finishReason,
            outcome: $record->outcome,
            approval: $record->approval,
            failureClass: $record->failureClass,
            failureCapture: $failureCapture === false ? $record->failureCapture : $failureCapture,
            replayInputsOmitted: $record->replayInputsOmitted,
            targetRecordId: $record->targetRecordId,
        );
    }

    private function filter(RecordV1 $record): ?RecordV1
    {
        $payload = new OutboundPayload(
            product: 'assay',
            kind: $record->type->value,
            schemaVersion: 1,
            disposition: PayloadDisposition::Droppable,
            data: $record->toArray(),
            attributes: [
                'subject' => $record->subject,
                'operation' => $record->operation?->value,
                'agent' => $record->agent,
                'capture' => $record->capture->value,
            ],
        );
        $filtered = $this->app->make(PayloadFilter::class)->filter($payload);

        if ($filtered === null) {
            return null;
        }

        if (! is_array($filtered->data)) {
            throw new InvalidArgumentException('The Assay payload filter must return record data as an array.');
        }

        $data = $filtered->data;

        if (array_key_exists('content', $data)) {
            $data['content'] = $this->normalizeContentStrings($data['content']);
        }

        return (new HookBoundary)->restore($record, $this->rehashMessages($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function rehashMessages(array $data): array
    {
        if (($data['type'] ?? null) !== RecordType::StepStart->value || ! isset($data['content'])) {
            return $data;
        }

        $contentValue = $data['content'];
        $content = $contentValue instanceof stdClass
            ? get_object_vars($contentValue)
            : $contentValue;

        if (! is_array($content)
            || ! isset($content['message_hashes'], $content['new_messages'])
            || ! is_array($content['message_hashes'])
            || (! is_array($content['new_messages']) && ! $content['new_messages'] instanceof stdClass)) {
            return $data;
        }

        $replacements = [];
        $messages = [];
        $newMessages = $content['new_messages'] instanceof stdClass
            ? get_object_vars($content['new_messages'])
            : $content['new_messages'];

        foreach ($newMessages as $hash => $message) {
            if (! is_string($hash)
                || (! is_array($message) && ! $message instanceof stdClass)) {
                continue;
            }

            $replacement = hash('sha256', json_encode(
                $this->canonicalize($message),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $replacements[$hash] = $replacement;
            $messages[$replacement] = $message;
        }

        $content['message_hashes'] = array_map(
            static fn (mixed $hash): mixed => is_string($hash) ? ($replacements[$hash] ?? $hash) : $hash,
            $content['message_hashes'],
        );
        $content['new_messages'] = (object) $messages;
        $data['content'] = $contentValue instanceof stdClass ? (object) $content : $content;

        return $data;
    }

    private function deduplicateMessages(RecordV1 $record): RecordV1
    {
        if ($record->type !== RecordType::StepStart || $record->content === null || $record->invocationId === null) {
            return $record;
        }

        $content = $record->content->toArray();
        $hashes = $content['message_hashes'] ?? [];
        $messages = $content['new_messages'] ?? [];
        $messageMap = $messages instanceof stdClass ? get_object_vars($messages) : $messages;
        $ordered = [];
        $new = [];

        foreach ($hashes as $hash) {
            $message = is_array($messageMap) && is_string($hash) ? ($messageMap[$hash] ?? null) : null;

            if (is_array($message) || $message instanceof stdClass) {
                $hash = hash('sha256', json_encode(
                    $this->canonicalize($message),
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                if (! isset($this->sentMessageHashes[$record->invocationId][$hash])) {
                    $new[$hash] = $message;
                    $this->sentMessageHashes[$record->invocationId][$hash] = true;
                }
            }

            $ordered[] = $hash;
        }

        $content['message_hashes'] = $ordered;
        $content['new_messages'] = (object) $new;

        return (new HookBoundary)->restore($record, ['content' => $content]);
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map($this->canonicalize(...), $properties);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map($this->canonicalize(...), $value);
    }

    private function normalizeContentStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", "\u{FFFD}", $value);
        }

        if ($value instanceof stdClass) {
            return (object) array_map($this->normalizeContentStrings(...), get_object_vars($value));
        }

        return is_array($value) ? array_map($this->normalizeContentStrings(...), $value) : $value;
    }

    public function flush(): void
    {
        $this->sweepTreeContexts();

        if ($this->records === []) {
            return;
        }

        $records = $this->records;
        $this->records = [];

        try {
            $this->dispatch($records);
        } catch (Throwable) {
            $this->incrementTransportSafely();
        }
    }

    /** @param list<RecordV1> $records */
    private function dispatch(array $records): void
    {
        $json = $this->encode($records);

        if (strlen($json) <= $this->maxBatchBytes) {
            $this->dispatcher->dispatch($json, time() + $this->retryForSeconds);

            return;
        }

        if (count($records) === 1) {
            $this->incrementTransportSafely();

            return;
        }

        $middle = intdiv(count($records), 2);
        $this->dispatch(array_slice($records, 0, $middle));
        $this->dispatch(array_slice($records, $middle));
    }

    /** @param list<RecordV1> $records */
    private function fits(array $records): bool
    {
        return strlen($this->encode($records)) <= $this->maxBatchBytes;
    }

    /** @param list<RecordV1> $records */
    private function encode(array $records): string
    {
        return EnvelopeCodec::encode(new EnvelopeV1(
            envelopeId: UuidV7::generate(),
            sentAt: Timestamp::fromDateTime(new DateTimeImmutable),
            client: $this->client,
            sources: [new Source($this->driver, $this->source->package, $this->source->version)],
            environment: $this->environment,
            droppedTransportTotal: $this->drops->transportTotal(),
            droppedHookTotal: $this->drops->hookTotal(),
            records: $records,
            deploy: $this->deploy,
        ));
    }

    private function incrementTransportSafely(): void
    {
        try {
            $this->drops->incrementTransport();
        } catch (Throwable) {
            // Telemetry cannot escape into the host application.
        }
    }

    private function incrementHookSafely(): void
    {
        try {
            $this->drops->incrementHook();
        } catch (Throwable) {
            // Telemetry cannot escape into the host application.
        }
    }
}
