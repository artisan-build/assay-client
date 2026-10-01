<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\Records\OperationStartInput;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\Sampler;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Tests\Support\CollectingDispatcher;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\FailureCapture;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadFilter;
use Illuminate\Container\Container;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;

final class RecordingSampler implements Sampler
{
    /** @var list<float> */
    public array $rates = [];

    public function sample(float $rate): bool
    {
        $this->rates[] = $rate;

        return $rate >= 0.5;
    }
}

function samplingRecorder(
    CollectingDispatcher $dispatcher,
    ?RecordingSampler $sampler = null,
    float $sampleRate = 1.0,
    array $agentSampleRates = [],
    bool $alwaysOnFailure = true,
    int $failureBufferBytes = 524_288,
    ?PayloadFilter $filter = null,
    int $maxRetainedRoots = 128,
    int $maxRetainedBufferBytes = 67_108_864,
    int $retainedStateTtlSeconds = 60,
    ?Closure $clock = null,
    ?DropCounter $drops = null,
): BufferedRecorder {
    $container = new Container;
    $container->instance(PayloadFilter::class, $filter ?? new class implements PayloadFilter
    {
        public function filter(OutboundPayload $payload): OutboundPayload
        {
            return $payload;
        }
    });

    return new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: null,
        batchSize: 500,
        retryForSeconds: 3600,
        drops: $drops ?? new InMemoryDropCounter,
        dispatcher: $dispatcher,
        app: $container,
        sampler: $sampler,
        sampleRate: $sampleRate,
        agentSampleRates: $agentSampleRates,
        alwaysOnFailure: $alwaysOnFailure,
        failureBufferBytes: $failureBufferBytes,
        maxRetainedRoots: $maxRetainedRoots,
        maxRetainedBufferBytes: $maxRetainedBufferBytes,
        retainedStateTtlSeconds: $retainedStateTtlSeconds,
        clock: $clock,
    );
}

/** @return list<RecordV1> */
function dispatchedSamplingRecords(CollectingDispatcher $dispatcher): array
{
    $records = [];

    foreach ($dispatcher->dispatched as $dispatch) {
        array_push($records, ...EnvelopeCodec::decode($dispatch['json'])->records);
    }

    return $records;
}

function fullRunStart(string $invocationId, string $agent, ?ParentLink $parent = null, ?Content $content = null): RunInput
{
    return new RunInput(
        RecordType::RunStart,
        $invocationId,
        1,
        new DateTimeImmutable,
        capture: CaptureMode::Full,
        parent: $parent,
        agent: $agent,
        content: $content,
    );
}

function fullRunEnd(string $invocationId, Outcome $outcome, ?ParentLink $parent = null, ?Content $content = null): RunInput
{
    return new RunInput(
        RecordType::RunEnd,
        $invocationId,
        1,
        new DateTimeImmutable,
        capture: CaptureMode::Full,
        parent: $parent,
        agent: 'App\\Ai\\Agent',
        outcome: $outcome,
        failureClass: $outcome === Outcome::Failed ? RuntimeException::class : null,
        content: $content,
    );
}

function retainSamplingRoot(BufferedRecorder $recorder, string $invocationId, string $content, Closure $onForcedRelease): void
{
    $recorder->retainTreeContext($invocationId, $onForcedRelease);
    $recorder->record(fullRunStart(
        $invocationId,
        'App\\Ai\\Agent',
        content: new Content(['instructions' => $content]),
    ));
    $recorder->record(fullRunEnd($invocationId, Outcome::Completed));
}

beforeEach(function (): void {
    Context::flush();
});

afterEach(function (): void {
    Context::flush();
});

it('decides once at the root and inherits sampling and frozen subject through a complete tree', function (): void {
    $dispatcher = new CollectingDispatcher;
    $sampler = new RecordingSampler;
    $recorder = samplingRecorder(
        $dispatcher,
        $sampler,
        sampleRate: 0.1,
        agentSampleRates: ['App\\Ai\\RootAgent' => 0.9],
    );
    Context::add('assay.subject', 'queued:user-1');
    $dehydrated = resolve(ContextRepository::class)->dehydrate();
    Context::flush();
    resolve(ContextRepository::class)->hydrate($dehydrated);

    $recorder->record(fullRunStart('root', 'App\\Ai\\RootAgent'));
    Context::add('assay.subject', 'changed-mid-tree');
    $recorder->record(new RunInput(RecordType::RunStart, 'root', 2, new DateTimeImmutable, capture: CaptureMode::Full, agent: 'App\\Ai\\RootAgent'));
    $recorder->record(fullRunStart('middle', 'App\\Ai\\MiddleAgent', new ParentLink('root', 'root-tool')));
    $recorder->record(fullRunStart('leaf', 'App\\Ai\\LeafAgent', new ParentLink('middle', 'middle-tool')));
    $recorder->record(new OperationStartInput(
        Operation::Embeddings,
        'embedding',
        new DateTimeImmutable,
        new ParentLink('leaf', 'leaf-tool'),
        CaptureMode::Full,
        false,
        null,
        new ModelInfo(requested: 'embed', provider: 'provider'),
        new Content(['inputs' => ['private input']]),
    ));
    $recorder->record(new SingleOperationInput(
        Operation::Embeddings,
        'embedding',
        new DateTimeImmutable,
        parent: new ParentLink('leaf', 'leaf-tool'),
        usage: new Usage(inputTokens: 3),
        outcome: Outcome::Completed,
        capture: CaptureMode::Full,
    ));
    $recorder->record(fullRunEnd('leaf', Outcome::Completed, new ParentLink('middle', 'middle-tool')));
    $recorder->record(fullRunEnd('middle', Outcome::Completed, new ParentLink('root', 'root-tool')));
    $recorder->record(fullRunEnd('root', Outcome::Completed));
    $recorder->flush();

    $records = dispatchedSamplingRecords($dispatcher);
    expect($sampler->rates)->toBe([0.9])
        ->and(collect($records)->pluck('sampled')->unique()->all())->toBe([true])
        ->and(collect($records)->pluck('subject')->unique()->all())->toBe(['queued:user-1'])
        ->and(collect($records)->firstWhere('invocationId', 'embedding')->content?->toArray())->toBe(['inputs' => ['private input']]);
});

it('uses the global fallback and rate boundaries and never loses unsampled usage', function (): void {
    $dispatcher = new CollectingDispatcher;
    $sampler = new RecordingSampler;
    $recorder = samplingRecorder($dispatcher, $sampler, sampleRate: 0.0, agentSampleRates: ['OverrideAgent' => 1.0]);

    $recorder->record(fullRunStart('fallback', 'FallbackAgent', content: new Content(['instructions' => 'private'])));
    $recorder->record(new StepInput(
        RecordType::StepEnd,
        'fallback',
        1,
        0,
        new DateTimeImmutable,
        capture: CaptureMode::Full,
        usage: new Usage(inputTokens: 9),
        content: new Content(['output_text' => 'private output']),
    ));
    $recorder->record(fullRunEnd('fallback', Outcome::Completed));
    $recorder->record(fullRunStart('override', 'OverrideAgent', content: new Content(['instructions' => 'kept'])));
    $recorder->record(fullRunEnd('override', Outcome::Completed));
    $recorder->flush();

    $records = dispatchedSamplingRecords($dispatcher);
    $fallback = collect($records)->where('invocationId', 'fallback');
    $override = collect($records)->where('invocationId', 'override');
    expect($sampler->rates)->toBe([0.0, 1.0])
        ->and($fallback->pluck('sampled')->unique()->all())->toBe([false])
        ->and($fallback->pluck('capture')->unique()->all())->toBe([CaptureMode::Usage])
        ->and($fallback->pluck('subject')->unique()->all())->toBe(['unknown'])
        ->and($fallback->filter(fn (RecordV1 $record): bool => $record->content !== null))->toBeEmpty()
        ->and($fallback->filter(fn (RecordV1 $record): bool => $record->type === RecordType::ContentAttach))->toBeEmpty()
        ->and($fallback->first(fn (RecordV1 $record): bool => $record->usage !== null)?->usage?->toArray())->toBe(['input_tokens' => 9])
        ->and($override->pluck('sampled')->unique()->all())->toBe([true])
        ->and($override->first()?->content?->toArray())->toBe(['instructions' => 'kept']);
});

it('sends usage immediately and flushes post-hook content attaches once on step failure', function (): void {
    $dispatcher = new CollectingDispatcher;
    $filter = new class implements PayloadFilter
    {
        public int $calls = 0;

        public function filter(OutboundPayload $payload): OutboundPayload
        {
            $this->calls++;
            $data = $payload->data;

            if (($data['type'] ?? null) === 'run.start' && isset($data['content']->instructions)) {
                $data['content']->instructions = 'masked instructions';
            }

            if (($data['type'] ?? null) === 'step.end' && isset($data['content']->output_text)) {
                $data['content']->output_text = 'masked output';
            }

            return new OutboundPayload($payload->product, $payload->kind, $payload->schemaVersion, $payload->disposition, $data, $payload->attributes);
        }
    };
    $recorder = samplingRecorder($dispatcher, sampleRate: 0.0, filter: $filter);
    $recorder->record(fullRunStart('failed', 'App\\Ai\\Agent', content: new Content(['instructions' => 'raw instructions'])));
    $recorder->record(new StepInput(
        RecordType::StepEnd,
        'failed',
        1,
        0,
        new DateTimeImmutable,
        capture: CaptureMode::Full,
        usage: new Usage(inputTokens: 4),
        durationMs: 12.5,
        content: new Content(['output_text' => 'raw output']),
    ));
    $recorder->record(new OperationStartInput(
        Operation::Embeddings,
        'linked-embedding',
        new DateTimeImmutable,
        new ParentLink('failed', 'embedding-tool'),
        CaptureMode::Full,
        false,
        null,
        new ModelInfo(requested: 'embed', provider: 'provider'),
        new Content(['inputs' => ['linked private input']]),
    ));
    $recorder->record(new SingleOperationInput(
        Operation::Embeddings,
        'linked-embedding',
        new DateTimeImmutable,
        parent: new ParentLink('failed', 'embedding-tool'),
        usage: new Usage(inputTokens: 2),
        outcome: Outcome::Completed,
        capture: CaptureMode::Full,
    ));
    $recorder->flush();

    $beforeFailure = collect(dispatchedSamplingRecords($dispatcher));
    expect($beforeFailure)->toHaveCount(4)
        ->and($beforeFailure->pluck('capture')->unique()->all())->toBe([CaptureMode::Usage])
        ->and($beforeFailure->pluck('sampled')->unique()->all())->toBe([false])
        ->and($beforeFailure->filter(fn (RecordV1 $record): bool => $record->content !== null))->toBeEmpty()
        ->and($beforeFailure->filter(fn (RecordV1 $record): bool => $record->type === RecordType::ContentAttach))->toBeEmpty()
        ->and($beforeFailure->first(fn (RecordV1 $record): bool => $record->type === RecordType::RunStart))->not->toBeNull()
        ->and($beforeFailure->first(fn (RecordV1 $record): bool => $record->type === RecordType::StepEnd)?->usage?->inputTokens)->toBe(4)
        ->and(implode('', array_column($dispatcher->dispatched, 'json')))
        ->not->toContain('raw instructions', 'raw output', 'masked instructions', 'masked output', 'linked private input');

    $recorder->record(new StepInput(
        RecordType::StepFail,
        'failed',
        1,
        1,
        new DateTimeImmutable,
        capture: CaptureMode::Full,
        failureClass: RuntimeException::class,
        content: new Content(['exception_message' => 'step failed']),
    ));
    $recorder->record(fullRunEnd('failed', Outcome::Failed, content: new Content(['exception_message' => 'agent failed'])));
    $recorder->flush();

    $records = collect(dispatchedSamplingRecords($dispatcher));
    $wire = implode('', array_column($dispatcher->dispatched, 'json'));
    $failedEnd = $records->first(fn (RecordV1 $record): bool => $record->type === RecordType::RunEnd && $record->outcome === Outcome::Failed);
    $originals = $records->reject(fn (RecordV1 $record): bool => $record->type === RecordType::ContentAttach);
    $attaches = $records->filter(fn (RecordV1 $record): bool => $record->type === RecordType::ContentAttach);
    $originalIds = $originals->pluck('recordId')->map(fn ($id): string => (string) $id);
    $attachIds = $attaches->pluck('recordId')->map(fn ($id): string => (string) $id);
    $targetIds = $attaches->pluck('targetRecordId')->map(fn ($id): string => (string) $id);
    expect($wire)->toContain('masked instructions', 'masked output', 'linked private input', 'step failed', 'agent failed')
        ->and($wire)->not->toContain('raw instructions', 'raw output')
        ->and($filter->calls)->toBe(6)
        ->and($originals)->toHaveCount(6)
        ->and($originals->pluck('capture')->unique()->all())->toBe([CaptureMode::Usage])
        ->and($originals->filter(fn (RecordV1 $record): bool => $record->content !== null))->toBeEmpty()
        ->and($attaches)->toHaveCount(5)
        ->and($attaches->pluck('capture')->unique()->all())->toBe([CaptureMode::Full])
        ->and($attaches->pluck('sampled')->unique()->all())->toBe([null])
        ->and($targetIds->diff($originalIds))->toBeEmpty()
        ->and($attachIds->intersect($originalIds))->toBeEmpty()
        ->and($records->filter(fn (RecordV1 $record): bool => $record->usage !== null))->toHaveCount(2)
        ->and($records->pluck('recordId')->map(fn ($id): string => (string) $id)->unique())->toHaveCount($records->count())
        ->and($records->filter(fn (RecordV1 $record): bool => in_array($record->type, [RecordType::StepEnd, RecordType::StepFail], true)))->toHaveCount(2)
        ->and($failedEnd?->failureCapture)->toBe(FailureCapture::Complete);
});

it('counts encoded multibyte content bytes and evicts the oldest content first', function (): void {
    $oldest = 'é-one';
    $newest = 'é-two';
    $limit = strlen(json_encode(
        ['content' => ['output_text' => $oldest]],
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
    ));
    expect($limit)->toBe(strlen(json_encode(
        ['content' => ['output_text' => $newest]],
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
    )));

    $dispatcher = new CollectingDispatcher;
    $recorder = samplingRecorder($dispatcher, sampleRate: 0.0, failureBufferBytes: $limit);
    $recorder->record(fullRunStart('truncated', 'App\\Ai\\Agent'));

    foreach ([$oldest, $newest] as $step => $text) {
        $recorder->record(new StepInput(
            RecordType::StepEnd,
            'truncated',
            1,
            $step,
            new DateTimeImmutable,
            capture: CaptureMode::Full,
            content: new Content(['output_text' => $text]),
        ));
    }

    $recorder->flush();
    expect(implode('', array_column($dispatcher->dispatched, 'json')))->not->toContain($oldest, $newest);
    $recorder->record(new StepInput(
        RecordType::StepFail,
        'truncated',
        1,
        2,
        new DateTimeImmutable,
        capture: CaptureMode::Full,
        failureClass: RuntimeException::class,
    ));
    $recorder->record(fullRunEnd('truncated', Outcome::Failed));
    $recorder->flush();

    $records = collect(dispatchedSamplingRecords($dispatcher));
    $failedEnd = $records->first(fn (RecordV1 $record): bool => $record->type === RecordType::RunEnd && $record->outcome === Outcome::Failed);
    $capturedOutputs = $records
        ->map(fn (RecordV1 $record): mixed => $record->content?->toArray()['output_text'] ?? null)
        ->filter()
        ->values()
        ->all();
    expect($capturedOutputs)->not->toContain($oldest)
        ->and($capturedOutputs)->toContain($newest)
        ->and($failedEnd?->failureCapture)->toBe(FailureCapture::Truncated);
});

it('reports truncated when failed terminal content first exceeds the failure buffer', function (): void {
    $dispatcher = new CollectingDispatcher;
    $recorder = samplingRecorder($dispatcher, sampleRate: 0.0, failureBufferBytes: 1);
    $recorder->record(fullRunStart('terminal-overflow', 'App\\Ai\\Agent'));
    $recorder->record(fullRunEnd(
        'terminal-overflow',
        Outcome::Failed,
        content: new Content(['exception_message' => 'first oversized content']),
    ));
    $recorder->flush();

    $records = collect(dispatchedSamplingRecords($dispatcher));
    $failedEnd = $records->first(fn (RecordV1 $record): bool => $record->type === RecordType::RunEnd);
    expect($records->filter(fn (RecordV1 $record): bool => $record->type === RecordType::ContentAttach))->toBeEmpty()
        ->and($records->filter(fn (RecordV1 $record): bool => $record->content !== null))->toBeEmpty()
        ->and($failedEnd?->failureCapture)->toBe(FailureCapture::Truncated);
});

it('never applies failure capture to standalone non-agent operations or when disabled', function (): void {
    $dispatcher = new CollectingDispatcher;
    $recorder = samplingRecorder($dispatcher, sampleRate: 0.0);
    $recorder->record(new OperationStartInput(
        Operation::Embeddings,
        'lost-operation',
        new DateTimeImmutable,
        null,
        capture: CaptureMode::Full,
        sampled: false,
        subject: null,
        model: new ModelInfo(requested: 'embed', provider: 'provider'),
        content: new Content(['inputs' => ['never buffered']]),
    ));
    $recorder->flush();

    $states = (new ReflectionProperty($recorder, 'rootStates'))->getValue($recorder);
    expect($dispatcher->dispatched[0]['json'])->not->toContain('never buffered')
        ->and($states['lost-operation']['buffer'])->toBe([])
        ->and(dispatchedSamplingRecords($dispatcher)[0]->capture)->toBe(CaptureMode::Usage);
    $recorder->record(new SingleOperationInput(
        Operation::Embeddings,
        'lost-operation',
        new DateTimeImmutable,
        outcome: Outcome::Completed,
        capture: CaptureMode::Full,
    ));
    expect((new ReflectionProperty($recorder, 'rootStates'))->getValue($recorder))->toBe([])
        ->and((new ReflectionProperty($recorder, 'invocationRoots'))->getValue($recorder))->toBe([]);

    $disabledDispatcher = new CollectingDispatcher;
    $disabled = samplingRecorder($disabledDispatcher, sampleRate: 0.0, alwaysOnFailure: false);
    $disabled->record(fullRunStart('disabled', 'App\\Ai\\Agent', content: new Content(['instructions' => 'discarded'])));
    $disabled->record(fullRunEnd('disabled', Outcome::Failed, content: new Content(['exception_message' => 'discarded failure'])));
    $disabled->flush();
    $disabledRecords = collect(dispatchedSamplingRecords($disabledDispatcher));
    expect(implode('', array_column($disabledDispatcher->dispatched, 'json')))->not->toContain('discarded')
        ->and($disabledRecords->filter(fn (RecordV1 $record): bool => $record->failureCapture !== null))->toBeEmpty();
});

it('prunes all root state across successful failed and reused invocations in a long-lived recorder', function (): void {
    $dispatcher = new CollectingDispatcher;
    $sampler = new RecordingSampler;
    $recorder = samplingRecorder($dispatcher, $sampler, sampleRate: 0.0);

    foreach (range(1, 100) as $index) {
        Context::add('assay.subject', 'subject-'.$index);
        $recorder->record(fullRunStart('reused', 'App\\Ai\\Agent', content: new Content(['instructions' => 'value-'.$index])));
        $recorder->record(fullRunEnd(
            'reused',
            $index % 2 === 0 ? Outcome::Completed : Outcome::Failed,
            content: $index % 2 === 0 ? null : new Content(['exception_message' => 'failure-'.$index]),
        ));
    }

    expect((new ReflectionProperty($recorder, 'rootStates'))->getValue($recorder))->toBe([])
        ->and((new ReflectionProperty($recorder, 'invocationRoots'))->getValue($recorder))->toBe([])
        ->and((new ReflectionProperty($recorder, 'sentMessageHashes'))->getValue($recorder))->toBe([])
        ->and($sampler->rates)->toHaveCount(100);
});

it('prunes root state when the payload hook throws on a terminal record', function (): void {
    $dispatcher = new CollectingDispatcher;
    $recorder = samplingRecorder($dispatcher, sampleRate: 0.0, filter: new class implements PayloadFilter
    {
        public function filter(OutboundPayload $payload): OutboundPayload
        {
            if (($payload->data['type'] ?? null) === RecordType::RunEnd->value) {
                throw new RuntimeException('Injected hook failure.');
            }

            return $payload;
        }
    });
    $recorder->record(fullRunStart('hook-failure', 'App\\Ai\\Agent', content: new Content(['instructions' => 'discarded'])));
    $recorder->record(fullRunEnd('hook-failure', Outcome::Completed));

    expect((new ReflectionProperty($recorder, 'rootStates'))->getValue($recorder))->toBe([])
        ->and((new ReflectionProperty($recorder, 'invocationRoots'))->getValue($recorder))->toBe([])
        ->and((new ReflectionProperty($recorder, 'retainedTreeContexts'))->getValue($recorder))->toBe([]);
});

it('evicts retained buffered content oldest first until the aggregate byte bound holds', function (): void {
    $bytes = strlen(json_encode(
        ['content' => ['instructions' => 'aaa']],
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
    ));
    $dispatcher = new CollectingDispatcher;
    $drops = new InMemoryDropCounter;
    $released = [];
    $recorder = samplingRecorder(
        $dispatcher,
        sampleRate: 0.0,
        maxRetainedBufferBytes: $bytes * 2,
        drops: $drops,
    );

    foreach (['oldest' => 'aaa', 'middle' => 'bbb', 'newest' => 'ccc'] as $invocationId => $content) {
        retainSamplingRoot(
            $recorder,
            $invocationId,
            $content,
            function () use (&$released, $invocationId): void {
                $released[] = $invocationId;
            },
        );
    }

    $states = (new ReflectionProperty($recorder, 'rootStates'))->getValue($recorder);
    $roots = (new ReflectionProperty($recorder, 'invocationRoots'))->getValue($recorder);
    $retained = (new ReflectionProperty($recorder, 'retainedTreeContexts'))->getValue($recorder);
    $retainedRoots = (new ReflectionProperty($recorder, 'retainedRoots'))->getValue($recorder);

    expect($released)->toBe(['oldest'])
        ->and($drops->transportTotal())->toBe(1)
        ->and(array_keys($states))->toBe(['middle', 'newest'])
        ->and(array_sum(array_column($states, 'bytes')))->toBeLessThanOrEqual($bytes * 2)
        ->and(array_keys($roots))->toBe(['middle', 'newest'])
        ->and(array_keys($retained))->toBe(['middle', 'newest'])
        ->and(array_keys($retainedRoots))->toBe(['middle', 'newest']);

    $recorder->flush();
    expect(collect(dispatchedSamplingRecords($dispatcher))
        ->where('type', RecordType::ContentAttach))->toBeEmpty();
});

it('contains drop counter failures during retained count eviction and ttl expiry', function (): void {
    $drops = new class implements DropCounter
    {
        public function transportTotal(): int
        {
            return 0;
        }

        public function hookTotal(): int
        {
            return 0;
        }

        public function incrementTransport(): int
        {
            throw new RuntimeException('Injected counter failure.');
        }

        public function incrementHook(): int
        {
            return 0;
        }
    };
    $released = [];
    $countRecorder = samplingRecorder(
        new CollectingDispatcher,
        sampleRate: 0.0,
        maxRetainedRoots: 1,
        drops: $drops,
    );
    retainSamplingRoot($countRecorder, 'count-oldest', 'aaa', function () use (&$released): void {
        $released[] = 'count-oldest';
    });
    retainSamplingRoot($countRecorder, 'count-newest', 'bbb', static function (): void {});

    $now = new DateTimeImmutable('2026-10-01T12:00:00+00:00');
    $clock = static function () use (&$now): DateTimeImmutable {
        return $now;
    };
    $ttlRecorder = samplingRecorder(
        new CollectingDispatcher,
        sampleRate: 0.0,
        retainedStateTtlSeconds: 60,
        clock: $clock,
        drops: $drops,
    );
    retainSamplingRoot($ttlRecorder, 'expired', 'ccc', function () use (&$released): void {
        $released[] = 'expired';
    });
    $now = $now->modify('+60 seconds');
    $ttlRecorder->sweepTreeContexts();

    expect($released)->toBe(['count-oldest', 'expired'])
        ->and(array_keys((new ReflectionProperty($countRecorder, 'rootStates'))->getValue($countRecorder)))->toBe(['count-newest'])
        ->and((new ReflectionProperty($ttlRecorder, 'rootStates'))->getValue($ttlRecorder))->toBe([])
        ->and((new ReflectionProperty($ttlRecorder, 'retainedRoots'))->getValue($ttlRecorder))->toBe([]);
});

it('rejects non-positive retained lifecycle bounds at construction', function (Closure $construct): void {
    expect(fn () => $construct(new CollectingDispatcher))
        ->toThrow(InvalidArgumentException::class, 'must be positive');
})->with([
    'maximum retained roots' => fn (CollectingDispatcher $dispatcher): BufferedRecorder => samplingRecorder($dispatcher, maxRetainedRoots: 0),
    'maximum retained buffer bytes' => fn (CollectingDispatcher $dispatcher): BufferedRecorder => samplingRecorder($dispatcher, maxRetainedBufferBytes: 0),
    'retained state ttl' => fn (CollectingDispatcher $dispatcher): BufferedRecorder => samplingRecorder($dispatcher, retainedStateTtlSeconds: 0),
]);
