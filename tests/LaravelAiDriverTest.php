<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\Internal\DroppedRecordInput;
use ArtisanBuild\AssayClient\Jobs\ShipEnvelope;
use ArtisanBuild\AssayClient\LaravelAiDriver;
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
use ArtisanBuild\AssayClient\Testing\DriverConformance;
use ArtisanBuild\AssayClient\Testing\DriverScenario;
use ArtisanBuild\AssayClient\Tests\Support\CollectingDispatcher;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;
use ArtisanBuild\AssayClient\Transport\HttpTransport;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\FinishReason as ContractFinishReason;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\ReplayInputOmission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Agents\SummarizeAgent;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Classification\Boolean as BooleanQuestion;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage as SourceUsage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Tools\Request;
use Symfony\Component\Process\Process;

final class LaravelAiCollectingRecorder implements Recorder
{
    /** @var list<RecordInput> */
    public array $records = [];

    public function record(RecordInput $input): void
    {
        $this->records[] = $input;
    }

    public function flush(): void {}
}

final class LaravelAiNamedTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Safe description';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'safe';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class LaravelAiReplayAgent implements Agent, HasProviderOptions, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Safe instructions';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['secret' => 'provider-option-canary'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function tools(): iterable
    {
        return [new LaravelAiNamedTool];
    }
}

final class LaravelAiFailingRecorder implements Recorder
{
    /** @var list<RecordInput> */
    public array $records = [];

    public bool $failRunEnd = false;

    public bool $failApproval = false;

    public function record(RecordInput $input): void
    {
        if (($this->failRunEnd && $input instanceof RunInput && $input->type === RecordType::RunEnd)
            || ($this->failApproval && $input instanceof ToolCallInput && $input->type === RecordType::ToolApproval)) {
            throw new RuntimeException('Injected recorder failure.');
        }

        $this->records[] = $input;
    }

    public function flush(): void {}
}

function laravelAiProvider(Dispatcher $events, string $name): TextProvider
{
    $manager = new AiManager(app());
    app()['config']->set("ai.providers.{$name}", [
        'driver' => 'openai',
        'name' => $name,
        'key' => 'provider-secret-must-not-ship',
        'url' => 'https://secret-endpoint.invalid',
    ]);

    return $manager->textProvider($name);
}

it('passes DriverConformance with projected v1 agent events', function (): void {
    $events = new Dispatcher;
    $at = new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00');
    $driver = new LaravelAiDriver($events, static fn (): DateTimeImmutable => $at);
    $source = $driver->source();
    expect($source)->toBeInstanceOf(SourceInfo::class);
    assert($source instanceof SourceInfo);
    expect($source->package)->toBe('laravel/ai')
        ->and($source->version)->not->toBeEmpty();
    $provider = laravelAiProvider($events, 'primary');
    $agent = new SummarizeAgent;
    $parent = new ParentLink('parent-invocation', 'parent-tool');
    $secondParent = new ParentLink('parent-invocation', 'parent-tool');
    $canary = 'excluded-source-canary';
    $firstPrompt = new AgentPrompt(
        $agent,
        $canary,
        [$canary],
        $provider,
        'model-a',
        invocationId: 'run-1',
        parentInvocationId: $parent->invocationId,
        parentToolInvocationId: $parent->toolInvocationId,
        isFinalAttempt: false,
    );
    $secondPrompt = new AgentPrompt(
        $agent,
        $canary,
        [],
        $provider,
        'model-b',
        invocationId: 'run-1',
        parentInvocationId: $parent->invocationId,
        parentToolInvocationId: $parent->toolInvocationId,
    );
    $usage = new TextUsage(11, 12, 0, null, 5);
    $stepResponse = new StepResponse(
        $canary,
        [],
        FinishReason::Stop,
        $usage,
        new Meta('primary', 'responded-model'),
        continuationToken: $canary,
        replayBlocks: [['secret' => $canary]],
    );
    $step = new Step($canary, [], [], FinishReason::Stop, $usage, new Meta('primary', 'responded-model'), $canary, [['secret' => $canary]]);
    $response = (new AgentResponse('run-1', $canary, $usage, new Meta('primary', 'responded-model')))
        ->withSteps(collect([$step]));
    $modelA = new ModelInfo(requested: 'model-a', provider: 'primary');
    $modelB = new ModelInfo(requested: 'model-b', provider: 'primary');

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'laravel-ai',
        source: new SourceInfo('laravel/ai', $source->version),
        exercise: static function (Throwable $failure) use ($events, $firstPrompt, $secondPrompt, $provider, $agent, $stepResponse, $response): void {
            $events->dispatch(new PromptingAgent('run-1', $firstPrompt));
            $events->dispatch(new StepCompleted('run-1', 0, $agent, $provider, 'model-a', false, $stepResponse, 42.5));
            $events->dispatch(new AgentFailedOver('run-1', $agent, $provider, 'model-a', new ProviderConnectionException($failure->getMessage())));
            $events->dispatch(new PromptingAgent('run-1', $secondPrompt));
            $events->dispatch(new AgentPrompted('run-1', $secondPrompt, $response));
        },
        expectedRecords: [
            new RunInput(RecordType::RunStart, 'run-1', 1, $at, parent: $parent, model: $modelA, agent: $agent::class),
            new StepInput(RecordType::StepEnd, 'run-1', 1, 0, $at, parent: $parent, usage: new Usage(inputTokens: 11, outputTokens: 12, cacheReadInputTokens: 0, reasoningTokens: 5), model: new ModelInfo(requested: 'model-a', responded: 'responded-model', provider: 'primary'), agent: $agent::class, durationMs: 42.5, finishReason: ContractFinishReason::Stop),
            new AttemptInput('run-1', 1, $at, operation: Operation::Agent, parent: $parent, model: new ModelInfo(requested: 'model-a', provider: 'primary'), agent: $agent::class, failureClass: ProviderConnectionException::class),
            new RunInput(RecordType::RunStart, 'run-1', 2, $at, parent: $secondParent, model: $modelB, agent: $agent::class),
            new RunInput(RecordType::RunEnd, 'run-1', 2, $at, parent: $secondParent, usage: new Usage(inputTokens: 11, outputTokens: 12, cacheReadInputTokens: 0, reasoningTokens: 5), model: new ModelInfo(requested: 'model-b', responded: 'responded-model', provider: 'primary'), agent: $agent::class, finishReason: ContractFinishReason::Stop, outcome: Outcome::Completed),
        ],
        canary: $canary,
        supportsFailover: true,
    )))->not->toThrow(Throwable::class);
});

it('captures real prompt and drained stream failover with concrete starts and prunes attempts', function (): void {
    $events = resolve(Dispatcher::class);
    $recorder = new LaravelAiCollectingRecorder;
    $driver = new LaravelAiDriver($events);
    $driver->register($recorder);

    app()['config']->set('ai.providers.primary', ['driver' => 'openai', 'key' => 'secret-a']);
    app()['config']->set('ai.providers.secondary', ['driver' => 'openai', 'key' => 'secret-b']);
    $manager = new AiManager(app());
    app()->instance(AiManager::class, $manager);
    Ai::clearResolvedInstance(AiManager::class);
    $manager->fakeAgent(SummarizeAgent::class, static function (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse {
        if ($provider->name() === 'primary') {
            throw ProviderConnectionException::forProvider('primary');
        }

        return new TextResponse('safe', new TextUsage(3, 4), new Meta($provider->name(), $model));
    });

    $agent = new SummarizeAgent;
    $agent->prompt('prompt secret', provider: ['primary' => 'model-a', 'secondary' => 'model-b']);
    foreach ($agent->stream('stream secret', provider: ['primary' => 'stream-a', 'secondary' => 'stream-b']) as $_) {
        // Draining is what causes the lazy streaming lifecycle to dispatch.
    }

    $starts = array_values(array_filter($recorder->records, static fn (RecordInput $record): bool => $record instanceof RunInput && $record->type === RecordType::RunStart));
    expect($starts)->toHaveCount(4)
        ->and($starts[0]->invocationId)->toBe($starts[1]->invocationId)
        ->and($starts[0]->attempt)->toBe(1)
        ->and($starts[1]->attempt)->toBe(2)
        ->and($starts[2]->invocationId)->toBe($starts[3]->invocationId)
        ->and($starts[2]->attempt)->toBe(1)
        ->and($starts[3]->attempt)->toBe(2)
        ->and($starts[0]->model?->provider)->toBe('primary')
        ->and($starts[1]->model?->provider)->toBe('secondary')
        ->and($starts[2]->model?->requested)->toBe('stream-a')
        ->and($starts[3]->model?->requested)->toBe('stream-b');

    expect(array_filter($recorder->records, static fn (RecordInput $record): bool => $record instanceof StepInput && $record->usage !== null))->toHaveCount(2);

    $firstPrompt = new AgentPrompt($agent, 'reuse', [], laravelAiProvider($events, 'primary'), 'model-a', invocationId: $starts[0]->invocationId);
    $events->dispatch(new PromptingAgent($starts[0]->invocationId, $firstPrompt));
    $last = $recorder->records[array_key_last($recorder->records)];
    expect($last)->toBeInstanceOf(RunInput::class)->and($last->attempt)->toBe(1);
    $events->dispatch(new AgentFailed($starts[0]->invocationId, $firstPrompt, new RuntimeException('secret failure')));
});

it('projects every non-agent usage shape with synchronous parents and unattributed failovers', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    $driver = new LaravelAiDriver($events);
    $driver->register($recorder);
    $manager = new AiManager(app());
    app()['config']->set('ai.providers.operations', ['driver' => 'openai', 'key' => 'credential-canary', 'url' => 'https://endpoint-canary.invalid']);
    app()['config']->set('ai.providers.rerank', ['driver' => 'cohere', 'key' => 'rerank-canary']);
    app()['config']->set('ai.providers.classify', ['driver' => 'typesafe', 'key' => 'classify-canary']);
    $provider = $manager->instance('operations');
    $rerankingProvider = $manager->instance('rerank');
    $classificationProvider = $manager->instance('classify');
    $canary = 'excluded-operation-canary';
    $meta = new Meta('operations', 'responded-model');
    $embeddingsPrompt = new EmbeddingsPrompt([$canary], 3, $provider, 'embedding-model', providerOptions: ['secret' => $canary]);
    $imagePrompt = new ImagePrompt($canary, [], null, null, $provider, 'image-model', providerOptions: ['secret' => $canary]);
    $audioPrompt = new AudioPrompt($canary, 'voice', $canary, $provider, 'audio-model', providerOptions: ['secret' => $canary]);
    $transcriptionPrompt = new TranscriptionPrompt(new Base64Audio(base64_encode($canary)), null, false, $provider, 'transcription-model', providerOptions: ['secret' => $canary]);
    $rerankingPrompt = new RerankingPrompt([$canary], $canary, null, $rerankingProvider, 'reranking-model', providerOptions: ['secret' => $canary]);
    $classificationPrompt = new ClassificationPrompt($canary, [], $classificationProvider, 'classification-model', providerOptions: ['secret' => $canary]);

    ParentInvocation::within('parent-run', 'parent-tool', function () use ($events, $provider, $rerankingProvider, $classificationProvider, $embeddingsPrompt, $imagePrompt, $audioPrompt, $transcriptionPrompt, $rerankingPrompt, $classificationPrompt, $meta, $canary): void {
        $events->dispatch(new GeneratingEmbeddings('emb-a', $provider, 'embedding-model', $embeddingsPrompt));
        $events->dispatch(new ProviderFailedOver($provider, 'embedding-model', new ProviderConnectionException($canary)));
        $events->dispatch(new GeneratingEmbeddings('emb-b', $provider, 'embedding-model', $embeddingsPrompt));
        $events->dispatch(new EmbeddingsGenerated('emb-b', $provider, 'embedding-model', $embeddingsPrompt, new EmbeddingsResponse([[$canary]], new SourceUsage(0, 2), $meta)));

        $events->dispatch(new GeneratingImage('image-1', $provider, 'image-model', $imagePrompt));
        $events->dispatch(new ImageGenerated('image-1', $provider, 'image-model', $imagePrompt, new ImageResponse(collect([new GeneratedImage(base64_encode($canary), 'image/png')]), new ImageUsage(3, 4, 0, null, null, 5, 0), $meta)));
        $events->dispatch(new GeneratingAudio('audio-1', $provider, 'audio-model', $audioPrompt));
        $events->dispatch(new AudioGenerated('audio-1', $provider, 'audio-model', $audioPrompt, new AudioResponse(base64_encode($canary), new SourceUsage(6, 7), $meta)));
        $events->dispatch(new GeneratingTranscription('transcription-1', $provider, 'transcription-model', $transcriptionPrompt));
        $events->dispatch(new TranscriptionGenerated('transcription-1', $provider, 'transcription-model', $transcriptionPrompt, new TranscriptionResponse($canary, collect(), new TranscriptionUsage(8, 9, null, 0, null, 1.25), $meta)));
        $events->dispatch(new Reranking('reranking-1', $rerankingProvider, 'reranking-model', $rerankingPrompt));
        $events->dispatch(new Reranked('reranking-1', $rerankingProvider, 'reranking-model', $rerankingPrompt, new RerankingResponse([], new RerankingUsage(10, 2.75), $meta)));
        $events->dispatch(new Classifying('classification-1', $classificationProvider, 'classification-model', $classificationPrompt));
        $events->dispatch(new Classified('classification-1', $classificationProvider, 'classification-model', $classificationPrompt, new ClassificationResponse([], new TextUsage(11, 12, null, null, 0), $meta)));
    });

    $events->dispatch(new ProviderFailedOver($provider, 'orphan-model', new ProviderConnectionException($canary)));

    $failovers = array_values(array_filter($recorder->records, static fn (RecordInput $record): bool => $record instanceof AttemptInput));
    $starts = array_values(array_filter($recorder->records, static fn (RecordInput $record): bool => $record instanceof OperationStartInput));
    $operations = array_values(array_filter($recorder->records, static fn (RecordInput $record): bool => $record instanceof SingleOperationInput));
    expect($starts)->toHaveCount(7)
        ->and($starts[0]->operation)->toBe(Operation::Embeddings)
        ->and($starts[0]->invocationId)->toBe('emb-a')
        ->and($starts[0]->parent)->toEqual(new ParentLink('parent-run', 'parent-tool'))
        ->and($starts[0]->model->requested)->toBe('embedding-model')
        ->and($starts[0]->model->provider)->toBe('operations')
        ->and($starts[0]->model->responded)->toBeNull()
        ->and($failovers)->toHaveCount(2)
        ->and($failovers[0]->operation)->toBeNull()
        ->and($failovers[0]->invocationId)->toBeNull()
        ->and($failovers[0]->attempt)->toBeNull()
        ->and($failovers[0]->parent)->toBeNull()
        ->and($failovers[0]->model?->requested)->toBe('embedding-model')
        ->and($failovers[1]->invocationId)->toBeNull()
        ->and($failovers[1]->attempt)->toBeNull()
        ->and($failovers[1]->operation)->toBeNull()
        ->and($failovers[1]->parent)->toBeNull()
        ->and($failovers[1]->model?->requested)->toBe('orphan-model')
        ->and($operations)->toHaveCount(6);

    $byOperation = [];
    foreach ($operations as $operation) {
        $byOperation[$operation->operation->value] = $operation;
        expect($operation->parent)->toEqual(new ParentLink('parent-run', 'parent-tool'))
            ->and($operation->durationMs)->toBeNull();
    }

    expect($byOperation['embeddings']->usage?->toArray())->toBe(['input_tokens' => 0, 'output_tokens' => 2])
        ->and($byOperation['image']->usage?->toArray())->toBe(['input_tokens' => 3, 'output_tokens' => 4, 'cache_read_input_tokens' => 0, 'image_input_tokens' => 5, 'image_output_tokens' => 0])
        ->and($byOperation['audio']->usage?->toArray())->toBe(['input_tokens' => 6, 'output_tokens' => 7])
        ->and($byOperation['transcription']->usage?->toArray())->toBe(['input_tokens' => 8, 'output_tokens' => 9, 'cache_write_input_tokens' => 0, 'audio_seconds' => 1.25])
        ->and($byOperation['reranking']->usage?->toArray())->toBe(['input_tokens' => 10, 'search_units' => 2.75])
        ->and($byOperation['classification']->usage?->toArray())->toBe(['input_tokens' => 11, 'output_tokens' => 12, 'reasoning_tokens' => 0])
        ->and(serialize($recorder->records))->not->toContain($canary)
        ->and(serialize($recorder->records))->not->toContain('credential-canary')
        ->and(serialize($recorder->records))->not->toContain('endpoint-canary');

    $envelopes = new CollectingDispatcher;
    $drops = new InMemoryDropCounter;
    $buffered = new BufferedRecorder(
        'laravel-ai',
        new SourceInfo('laravel/ai', 'test'),
        new Client('artisan-build/assay-client', 'test'),
        'testing',
        null,
        100,
        86400,
        $drops,
        $envelopes,
        app(),
    );
    foreach ($recorder->records as $record) {
        $buffered->record($record);
    }
    $buffered->flush();
    $json = $envelopes->dispatched[0]['json'];
    $queued = unserialize(serialize(new ShipEnvelope($json, time() + 86400)), ['allowed_classes' => [ShipEnvelope::class]]);
    expect($json)->not->toContain($canary)
        ->and(serialize($queued))->not->toContain($canary)
        ->and($queued)->toBeInstanceOf(ShipEnvelope::class);
});

it('reads non-agent parent linkage independently at start and completion', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    (new LaravelAiDriver($events))->register($recorder);
    $manager = new AiManager(app());
    app()['config']->set('ai.providers.nested', ['driver' => 'openai', 'key' => 'safe']);
    $provider = $manager->instance('nested');
    $imagePrompt = new ImagePrompt('safe', [], null, null, $provider, 'image-model');
    ParentInvocation::within('start-parent', 'start-tool', fn () => $events->dispatch(
        new GeneratingImage('image-1', $provider, 'image-model', $imagePrompt),
    ));
    $events->dispatch(new ProviderFailedOver($provider, 'image-model', new ProviderConnectionException('safe')));
    ParentInvocation::within('end-parent', 'end-tool', fn () => $events->dispatch(new ImageGenerated(
        'image-1',
        $provider,
        'image-model',
        $imagePrompt,
        new ImageResponse(collect(), new ImageUsage, new Meta('nested', 'image-model')),
    )));

    $failover = collect($recorder->records)->first(static fn (RecordInput $record): bool => $record instanceof AttemptInput);
    $start = collect($recorder->records)->first(static fn (RecordInput $record): bool => $record instanceof OperationStartInput);
    $end = collect($recorder->records)->first(static fn (RecordInput $record): bool => $record instanceof SingleOperationInput);
    expect($failover)->toBeInstanceOf(AttemptInput::class)
        ->and($failover->operation)->toBeNull()
        ->and($failover->invocationId)->toBeNull()
        ->and($failover->attempt)->toBeNull()
        ->and($failover->parent)->toBeNull()
        ->and($start)->toBeInstanceOf(OperationStartInput::class)
        ->and($start->parent)->toEqual(new ParentLink('start-parent', 'start-tool'))
        ->and($end)->toBeInstanceOf(SingleOperationInput::class)
        ->and($end->parent)->toEqual(new ParentLink('end-parent', 'end-tool'))
        ->and($end->durationMs)->toBeNull();
});

it('contains a non-agent terminal usage projection failure without contaminating failover', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    $driver = new LaravelAiDriver($events);
    $driver->register($recorder);
    $manager = new AiManager(app());
    app()['config']->set('ai.providers.failed-terminal', ['driver' => 'openai', 'key' => 'safe']);
    $provider = $manager->instance('failed-terminal');
    $prompt = new EmbeddingsPrompt(['safe'], 3, $provider, 'embedding-model');

    $events->dispatch(new GeneratingEmbeddings('failed-terminal', $provider, 'embedding-model', $prompt));
    expect(fn () => $events->dispatch(new EmbeddingsGenerated(
        'failed-terminal',
        $provider,
        'embedding-model',
        $prompt,
        new EmbeddingsResponse([[]], new SourceUsage(-1, 0), new Meta('failed-terminal', 'embedding-model')),
    )))->not->toThrow(Throwable::class);
    $events->dispatch(new ProviderFailedOver($provider, 'unrelated-model', new ProviderConnectionException('safe')));

    $failover = collect($recorder->records)->last(static fn (RecordInput $record): bool => $record instanceof AttemptInput);

    expect(collect($recorder->records)->filter(static fn (RecordInput $record): bool => $record instanceof DroppedRecordInput))->toHaveCount(1)
        ->and($failover)->toBeInstanceOf(AttemptInput::class)
        ->and($failover->invocationId)->toBeNull()
        ->and($failover->attempt)->toBeNull()
        ->and($failover->operation)->toBeNull()
        ->and($failover->parent)->toBeNull()
        ->and($failover->model?->provider)->toBe('failed-terminal')
        ->and($failover->model?->requested)->toBe('unrelated-model')
        ->and($failover->failureClass)->toBe(ProviderConnectionException::class);
});

it('retains no non-agent state across silent failures and keeps generic failover unattributed', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    $driver = new LaravelAiDriver($events);
    $driver->register($recorder);
    $manager = new AiManager(app());
    app()['config']->set('ai.providers.silent-failure', ['driver' => 'openai', 'key' => 'safe']);
    $provider = $manager->instance('silent-failure');
    $prompt = new EmbeddingsPrompt(['safe'], 3, $provider, 'embedding-model');
    $retainedState = static function (LaravelAiDriver $driver): string {
        $state = [];

        foreach ((new ReflectionObject($driver))->getProperties() as $property) {
            if (in_array($property->getName(), ['events', 'clock', 'recorder'], true)) {
                continue;
            }

            $state[$property->getName()] = $property->getValue($driver);
        }

        return serialize($state);
    };
    $before = $retainedState($driver);

    ParentInvocation::within('stale-parent', 'stale-tool', function () use ($events, $provider, $prompt): void {
        for ($index = 0; $index < 50; $index++) {
            $events->dispatch(new GeneratingEmbeddings('silent-'.$index, $provider, 'embedding-model', $prompt));
        }
    });
    $afterStarts = $retainedState($driver);
    $events->dispatch(new ProviderFailedOver($provider, 'orphan-model', new ProviderConnectionException('safe')));
    $afterFailover = $retainedState($driver);

    $failover = collect($recorder->records)->last(static fn (RecordInput $record): bool => $record instanceof AttemptInput);
    $propertyNames = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionObject($driver))->getProperties(),
    );

    expect($afterStarts)->toBe($before)
        ->and($afterFailover)->toBe($before)
        ->and($propertyNames)->not->toContain('operations')
        ->and($failover)->toBeInstanceOf(AttemptInput::class)
        ->and($failover->invocationId)->toBeNull()
        ->and($failover->attempt)->toBeNull()
        ->and($failover->operation)->toBeNull()
        ->and($failover->parent)->toBeNull()
        ->and($failover->model?->provider)->toBe('silent-failure')
        ->and($failover->model?->requested)->toBe('orphan-model')
        ->and($failover->failureClass)->toBe(ProviderConnectionException::class);
});

it('aggregates replay omission presence across attempts without retaining values', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    (new LaravelAiDriver($events))->register($recorder);
    $provider = laravelAiProvider($events, 'replay');
    $agent = new LaravelAiReplayAgent;
    $canary = 'replay-source-canary';
    $first = new AgentPrompt(
        $agent,
        'safe',
        [],
        $provider,
        'model-a',
        invocationId: 'replay-run',
        isFinalAttempt: false,
        messages: [new AssistantMessage('safe', replayBlocks: [['secret' => $canary]])],
    );
    $second = new AgentPrompt(
        $agent,
        'safe',
        [$canary],
        $provider,
        'model-b',
        invocationId: 'replay-run',
    );
    $response = new AgentResponse('replay-run', 'safe', new TextUsage, new Meta('replay', 'model-b'));

    $events->dispatch(new PromptingAgent('replay-run', $first));
    $events->dispatch(new AgentFailedOver('replay-run', $agent, $provider, 'model-a', new ProviderConnectionException('safe')));
    $events->dispatch(new PromptingAgent('replay-run', $second));
    $events->dispatch(new AgentPrompted('replay-run', $second, $response));

    $plainAgent = new SummarizeAgent;
    $plainPrompt = new AgentPrompt($plainAgent, 'safe', [], $provider, 'model-c', invocationId: 'complete-replay-run');
    $events->dispatch(new PromptingAgent('complete-replay-run', $plainPrompt));
    $events->dispatch(new AgentPrompted(
        'complete-replay-run',
        $plainPrompt,
        new AgentResponse('complete-replay-run', 'safe', new TextUsage, new Meta('replay', 'model-c')),
    ));

    $ends = collect($recorder->records)
        ->filter(static fn (RecordInput $record): bool => $record instanceof RunInput && $record->type === RecordType::RunEnd)
        ->keyBy(static fn (RunInput $record): string => $record->invocationId);

    expect($ends['replay-run']->replayInputsOmitted)->toBe([
        ReplayInputOmission::Attachments,
        ReplayInputOmission::OutputSchema,
        ReplayInputOmission::ProviderOptions,
        ReplayInputOmission::ProviderReplayState,
    ])->and($ends['complete-replay-run']->replayInputsOmitted)->toBeNull()
        ->and(serialize($recorder->records))->not->toContain($canary)
        ->and(serialize($recorder->records))->not->toContain('provider-option-canary');
});

it('preserves and prunes approval linkage in terminal-first production order', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    $driver = new LaravelAiDriver($events);
    $driver->register($recorder);
    $provider = laravelAiProvider($events, 'approval-order');
    $agent = new SummarizeAgent;
    $parent = new ParentLink('approval-parent', 'approval-parent-tool');
    $requestedFirst = new AgentPrompt($agent, 'safe', [], $provider, 'model-a', invocationId: 'approval-requested', parentInvocationId: $parent->invocationId, parentToolInvocationId: $parent->toolInvocationId, isFinalAttempt: false);
    $requestedSecond = new AgentPrompt($agent, 'safe', [], $provider, 'model-b', invocationId: 'approval-requested', parentInvocationId: $parent->invocationId, parentToolInvocationId: $parent->toolInvocationId);
    $requestedResponse = (new AgentResponse('approval-requested', 'safe', new TextUsage, new Meta('approval-order', 'model-b')))
        ->withPendingApprovals(collect([new PendingApproval('approval-id', 'safe_tool', [], null)]));

    $events->dispatch(new PromptingAgent('approval-requested', $requestedFirst));
    $events->dispatch(new AgentFailedOver('approval-requested', $agent, $provider, 'model-a', new ProviderConnectionException('safe')));
    $events->dispatch(new PromptingAgent('approval-requested', $requestedSecond));
    $events->dispatch(new AgentPrompted('approval-requested', $requestedSecond, $requestedResponse));
    $events->dispatch(new ToolApprovalRequested('approval-requested', $agent, $requestedResponse->pendingApprovals));

    $resolvedFirst = new AgentPrompt($agent, 'safe', [], $provider, 'model-a', invocationId: 'approval-resolved', parentInvocationId: $parent->invocationId, parentToolInvocationId: $parent->toolInvocationId, isFinalAttempt: false);
    $resolvedSecond = new AgentPrompt(
        $agent,
        'safe',
        [],
        $provider,
        'model-b',
        invocationId: 'approval-resolved',
        approvalDecisions: Decisions::from(['approval-id' => Decision::approve()]),
        parentInvocationId: $parent->invocationId,
        parentToolInvocationId: $parent->toolInvocationId,
    );

    $events->dispatch(new PromptingAgent('approval-resolved', $resolvedFirst));
    $events->dispatch(new AgentFailedOver('approval-resolved', $agent, $provider, 'model-a', new ProviderConnectionException('safe')));
    $events->dispatch(new PromptingAgent('approval-resolved', $resolvedSecond));
    $events->dispatch(new AgentPrompted(
        'approval-resolved',
        $resolvedSecond,
        new AgentResponse('approval-resolved', 'safe', new TextUsage, new Meta('approval-order', 'model-b')),
    ));
    $events->dispatch(new ToolApprovalResolved('approval-resolved', $agent, collect([
        new ToolResult('approval-id', 'safe_tool', [], 'safe'),
    ])));

    $approvals = collect($recorder->records)
        ->filter(static fn (RecordInput $record): bool => $record instanceof ToolCallInput && $record->type === RecordType::ToolApproval)
        ->values();
    $invocations = (new ReflectionProperty($driver, 'invocations'))->getValue($driver);
    $decisions = (new ReflectionProperty($driver, 'approvalDecisions'))->getValue($driver);

    expect($approvals)->toHaveCount(2)
        ->and($approvals[0]->approval?->value)->toBe('requested')
        ->and($approvals[1]->approval?->value)->toBe('approved')
        ->and($approvals[0]->attempt)->toBe(2)
        ->and($approvals[1]->attempt)->toBe(2)
        ->and($approvals[0]->parent)->toEqual($parent)
        ->and($approvals[1]->parent)->toEqual($parent)
        ->and($invocations->approvalSnapshot('approval-requested'))->toBeNull()
        ->and($invocations->approvalSnapshot('approval-resolved'))->toBeNull()
        ->and($decisions)->not->toHaveKeys(['approval-requested', 'approval-resolved']);
});

it('clears invocation and decision state after projection and recording failures', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiFailingRecorder;
    $driver = new LaravelAiDriver($events);
    $driver->register($recorder);
    $provider = laravelAiProvider($events, 'cleanup');
    $agent = new SummarizeAgent;

    $projectionPrompt = new AgentPrompt($agent, 'safe', [], $provider, 'model-a', invocationId: 'projection-failure');
    $events->dispatch(new PromptingAgent('projection-failure', $projectionPrompt));
    $events->dispatch(new AgentPrompted(
        'projection-failure',
        $projectionPrompt,
        new AgentResponse('projection-failure', 'safe', new TextUsage, new Meta('cleanup', '')),
    ));
    $events->dispatch(new PromptingAgent('projection-failure', $projectionPrompt));
    $events->dispatch(new AgentFailed('projection-failure', $projectionPrompt, new RuntimeException('safe')));

    $decisionPrompt = new AgentPrompt(
        $agent,
        'safe',
        [],
        $provider,
        'model-a',
        invocationId: 'recording-failure',
        approvalDecisions: Decisions::from(['stale-id' => Decision::approve()]),
        parentInvocationId: 'stale-parent',
        parentToolInvocationId: 'stale-tool',
    );
    $events->dispatch(new PromptingAgent('recording-failure', $decisionPrompt));
    $recorder->failRunEnd = true;
    $events->dispatch(new AgentPrompted(
        'recording-failure',
        $decisionPrompt,
        new AgentResponse('recording-failure', 'safe', new TextUsage, new Meta('cleanup', 'model-a')),
    ));
    $recorder->failRunEnd = false;

    $freshParent = new ParentLink('fresh-parent', 'fresh-tool');
    $freshPrompt = new AgentPrompt($agent, 'safe', [], $provider, 'model-b', invocationId: 'recording-failure', parentInvocationId: $freshParent->invocationId, parentToolInvocationId: $freshParent->toolInvocationId);
    $freshResponse = (new AgentResponse('recording-failure', 'safe', new TextUsage, new Meta('cleanup', 'model-b')))
        ->withPendingApprovals(collect([new PendingApproval('stale-id', 'safe_tool', [], null)]));
    $events->dispatch(new PromptingAgent('recording-failure', $freshPrompt));
    $events->dispatch(new AgentPrompted('recording-failure', $freshPrompt, $freshResponse));
    $events->dispatch(new ToolApprovalResolved('recording-failure', $agent, collect([
        new ToolResult('stale-id', 'safe_tool', [], 'safe', denied: true),
    ])));

    $approvalFailurePrompt = new AgentPrompt(
        $agent,
        'safe',
        [],
        $provider,
        'model-c',
        invocationId: 'approval-recording-failure',
        approvalDecisions: Decisions::from(['approval-failure-id' => Decision::approve()]),
    );
    $approvalFailureResponse = (new AgentResponse('approval-recording-failure', 'safe', new TextUsage, new Meta('cleanup', 'model-c')))
        ->withPendingApprovals(collect([new PendingApproval('approval-failure-id', 'safe_tool', [], null)]));
    $events->dispatch(new PromptingAgent('approval-recording-failure', $approvalFailurePrompt));
    $events->dispatch(new AgentPrompted('approval-recording-failure', $approvalFailurePrompt, $approvalFailureResponse));
    $recorder->failApproval = true;
    $events->dispatch(new ToolApprovalRequested('approval-recording-failure', $agent, $approvalFailureResponse->pendingApprovals));
    $recorder->failApproval = false;

    $starts = collect($recorder->records)
        ->filter(static fn (RecordInput $record): bool => $record instanceof RunInput && $record->type === RecordType::RunStart)
        ->groupBy(static fn (RunInput $record): string => $record->invocationId);
    $resolved = collect($recorder->records)->last(static fn (RecordInput $record): bool => $record instanceof ToolCallInput && $record->type === RecordType::ToolApproval);
    $invocations = (new ReflectionProperty($driver, 'invocations'))->getValue($driver);
    $decisions = (new ReflectionProperty($driver, 'approvalDecisions'))->getValue($driver);

    expect($starts['projection-failure']->last()->attempt)->toBe(1)
        ->and($starts['recording-failure']->last()->attempt)->toBe(1)
        ->and($resolved)->toBeInstanceOf(ToolCallInput::class)
        ->and($resolved->approval?->value)->toBe('rejected')
        ->and($resolved->attempt)->toBe(1)
        ->and($resolved->parent)->toEqual($freshParent)
        ->and($invocations->approvalSnapshot('recording-failure'))->toBeNull()
        ->and($invocations->approvalSnapshot('approval-recording-failure'))->toBeNull()
        ->and($decisions)->not->toHaveKeys(['recording-failure', 'approval-recording-failure']);
});

it('projects step tool failure and approval metadata without content', function (): void {
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    $driver = new LaravelAiDriver($events);
    $driver->register($recorder);
    $provider = laravelAiProvider($events, 'metadata');
    $agent = new SummarizeAgent;
    $tool = new LaravelAiNamedTool;
    $canary = 'agent-content-canary';
    $prompt = new AgentPrompt(
        $agent,
        $canary,
        [],
        $provider,
        'model-a',
        invocationId: 'metadata-run',
        approvalDecisions: Decisions::from([
            'approved' => Decision::approve(),
            'rejected' => Decision::reject($canary),
            'edited' => Decision::edit(['secret' => $canary]),
        ]),
        parentInvocationId: 'parent-run',
        parentToolInvocationId: 'parent-tool',
    );

    $events->dispatch(new PromptingAgent('metadata-run', $prompt));
    $events->dispatch(new StartingStep('metadata-run', 3, $agent, $provider, 'model-a', false, [], null));
    $events->dispatch(new StepFailed('metadata-run', 3, $agent, $provider, 'model-a', false, new RuntimeException($canary), 12.5));
    $events->dispatch(new InvokingTool('metadata-run', 'tool-1', $agent, $tool, ['secret' => $canary]));
    $events->dispatch(new ToolInvoked('metadata-run', 'tool-1', $agent, $tool, ['secret' => $canary], $canary, 3.25));
    $events->dispatch(new ToolFailed('metadata-run', 'tool-2', $agent, $tool, ['secret' => $canary], new LogicException($canary), 4.5));
    $events->dispatch(new ToolApprovalRequested('metadata-run', $agent, collect([
        new PendingApproval('requested', 'dangerous_tool', ['secret' => $canary], $canary),
    ]), conversationId: $canary, conversationUser: (object) ['secret' => $canary]));
    $events->dispatch(new ToolApprovalResolved('metadata-run', $agent, collect([
        new ToolResult('approved', 'approved_tool', ['secret' => $canary], $canary),
        new ToolResult('rejected', 'rejected_tool', ['secret' => $canary], $canary, denied: true),
        new ToolResult('edited', 'edited_tool', ['secret' => $canary], $canary),
    ]), conversationId: $canary, conversationUser: (object) ['secret' => $canary]));
    $events->dispatch(new AgentFailed('metadata-run', $prompt, new RuntimeException($canary)));

    $stepFailure = collect($recorder->records)->first(static fn (RecordInput $record): bool => $record instanceof StepInput && $record->type === RecordType::StepFail);
    $toolRecords = collect($recorder->records)->filter(static fn (RecordInput $record): bool => $record instanceof ToolCallInput)->values();
    expect($stepFailure)->toBeInstanceOf(StepInput::class)
        ->and($stepFailure->step)->toBe(3)
        ->and($stepFailure->durationMs)->toBe(12.5)
        ->and($stepFailure->failureClass)->toBe(RuntimeException::class)
        ->and($toolRecords)->toHaveCount(7)
        ->and($toolRecords[0]->tool)->toBe('LaravelAiNamedTool')
        ->and($toolRecords[1]->outcome)->toBe(Outcome::Completed)
        ->and($toolRecords[1]->durationMs)->toBe(3.25)
        ->and($toolRecords[2]->outcome)->toBe(Outcome::Failed)
        ->and($toolRecords[2]->failureClass)->toBe(LogicException::class)
        ->and($toolRecords[3]->approval?->value)->toBe('requested')
        ->and($toolRecords[4]->approval?->value)->toBe('approved')
        ->and($toolRecords[5]->approval?->value)->toBe('rejected')
        ->and($toolRecords[6]->approval?->value)->toBe('other')
        ->and(serialize($recorder->records))->not->toContain($canary);
});

it('loads inertly in a process where Laravel AI classes are absent', function (): void {
    $process = new Process([PHP_BINARY, __DIR__.'/fixtures/absent-source.php']);
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('inert');
});

it('silently routes listener projection failures through transport drops', function (): void {
    $events = new Dispatcher;
    $drops = new InMemoryDropCounter;
    $buffered = new BufferedRecorder(
        'laravel-ai',
        new SourceInfo('laravel/ai', 'test'),
        new Client('artisan-build/assay-client', 'test'),
        'testing',
        null,
        100,
        86400,
        $drops,
        new CollectingDispatcher,
        app(),
    );
    $driver = new LaravelAiDriver($events);
    $driver->register($buffered);
    $provider = laravelAiProvider($events, 'failure');
    $agent = new SummarizeAgent;
    $prompt = new AgentPrompt($agent, 'safe', [], $provider, 'model-a', invocationId: 'failure-run');
    $events->dispatch(new PromptingAgent('failure-run', $prompt));

    expect(fn () => $events->dispatch(new StepFailed('failure-run', 0, $agent, $provider, 'model-a', true, new RuntimeException('secret'), NAN)))
        ->not->toThrow(Throwable::class)
        ->and($drops->transportTotal())->toBe(1);
});

it('projects full agent content while excluding replay provider attachment and source-object canaries', function (): void {
    config()->set('assay.capture', 'full');
    $events = new Dispatcher;
    $collected = new LaravelAiCollectingRecorder;
    (new LaravelAiDriver($events))->register($collected);
    $provider = laravelAiProvider($events, 'full-content');
    $agent = new LaravelAiReplayAgent;
    $excluded = 'EXCLUDED-FULL-CANARY';
    $prompt = new AgentPrompt(
        $agent,
        'allowed prompt',
        [$excluded],
        $provider,
        'model-a',
        invocationId: 'full-run',
        messages: [new AssistantMessage('history', replayBlocks: [['secret' => $excluded]])],
    );
    $toolCall = new ToolCall('call-1', 'LaravelAiNamedTool', ['id' => 7], reasoningEncryptedContent: $excluded);
    $stepResponse = new StepResponse(
        'allowed output',
        [$toolCall],
        FinishReason::Stop,
        new TextUsage(1, 2),
        new Meta('full-content', 'model-a'),
        structured: ['answer' => true],
        continuationToken: $excluded,
        replayBlocks: [['secret' => $excluded]],
    );

    $events->dispatch(new PromptingAgent('full-run', $prompt));
    $events->dispatch(new StartingStep('full-run', 0, $agent, $provider, 'model-a', false, [
        new UserMessage('allowed user'),
        new AssistantMessage('allowed assistant', collect([$toolCall]), [['secret' => $excluded]]),
        new ToolResultMessage(collect([new ToolResult('call-1', 'LaravelAiNamedTool', ['id' => 7], 'allowed result')])),
    ], null));
    $events->dispatch(new StepCompleted('full-run', 0, $agent, $provider, 'model-a', true, $stepResponse, 2.5));
    $events->dispatch(new InvokingTool('full-run', 'tool-1', $agent, new LaravelAiNamedTool, ['id' => 7]));
    $events->dispatch(new ToolInvoked('full-run', 'tool-1', $agent, new LaravelAiNamedTool, ['id' => 7], ['ok' => true], 1.0));
    $events->dispatch(new ToolFailed('full-run', 'tool-2', $agent, new LaravelAiNamedTool, [], new RuntimeException('allowed exception'), 1.0));
    $events->dispatch(new AgentFailed('full-run', $prompt, new RuntimeException('allowed run exception')));

    $records = collect($collected->records);
    $runStart = $records->first(static fn (RecordInput $record): bool => $record instanceof RunInput && $record->type === RecordType::RunStart);
    $stepStart = $records->first(static fn (RecordInput $record): bool => $record instanceof StepInput && $record->type === RecordType::StepStart);
    $stepEnd = $records->first(static fn (RecordInput $record): bool => $record instanceof StepInput && $record->type === RecordType::StepEnd);
    $tools = $records->filter(static fn (RecordInput $record): bool => $record instanceof ToolCallInput)->values();
    $runEnd = $records->first(static fn (RecordInput $record): bool => $record instanceof RunInput && $record->type === RecordType::RunEnd);

    expect($runStart->capture)->toBe(CaptureMode::Full)
        ->and($runStart->content?->toArray())->toMatchArray(['instructions' => 'Safe instructions'])
        ->and($runStart->content?->toArray()['tools'][0])->toMatchArray(['name' => 'LaravelAiNamedTool', 'description' => 'Safe description'])
        ->and($stepStart->content?->toArray()['message_hashes'])->toHaveCount(3)
        ->and($stepStart->content?->toArray()['new_messages'])->toHaveCount(3)
        ->and($stepEnd->content?->toArray())->toMatchArray(['output_text' => 'allowed output', 'structured_output' => ['answer' => true]])
        ->and($tools[0]->content?->toArray())->toBe(['arguments' => ['id' => 7]])
        ->and($tools[1]->content?->toArray())->toBe(['result' => ['ok' => true]])
        ->and($tools[2]->content?->toArray())->toBe(['exception_message' => 'allowed exception'])
        ->and($runEnd->content?->toArray())->toBe(['exception_message' => 'allowed run exception'])
        ->and(serialize($collected->records))->not->toContain($excluded, 'provider-option-canary');

    $dispatcher = new CollectingDispatcher;
    $buffered = new BufferedRecorder(
        'laravel-ai',
        new SourceInfo('laravel/ai', 'test'),
        new Client('artisan-build/assay-client', 'test'),
        'testing',
        null,
        100,
        86400,
        new InMemoryDropCounter,
        $dispatcher,
        app(),
    );
    foreach ($collected->records as $record) {
        $buffered->record($record);
    }
    $buffered->flush();

    $json = $dispatcher->dispatched[0]['json'];
    expect($json)->not->toContain($excluded, 'provider-option-canary');

    config()->set('assay.url', 'https://assay.test/ingest');
    config()->set('assay.token', 'test-token');
    Http::fake(['https://assay.test/ingest' => Http::response(status: 202)]);
    resolve(HttpTransport::class)->send($json);
    Http::assertSent(static fn (HttpRequest $request): bool => $request->body() === $json
        && ! str_contains($request->body(), $excluded));
});

it('projects every supported non-agent full-content shape without media vectors or provider options', function (): void {
    config()->set('assay.capture', 'full');
    $events = new Dispatcher;
    $recorder = new LaravelAiCollectingRecorder;
    (new LaravelAiDriver($events))->register($recorder);
    $manager = new AiManager(app());
    app()['config']->set('ai.providers.full-ops', ['driver' => 'openai', 'key' => 'EXCLUDED-CREDENTIAL']);
    app()['config']->set('ai.providers.full-rerank', ['driver' => 'cohere', 'key' => 'EXCLUDED-RERANK-CREDENTIAL']);
    app()['config']->set('ai.providers.full-classify', ['driver' => 'typesafe', 'key' => 'EXCLUDED-CLASSIFY-CREDENTIAL']);
    $provider = $manager->instance('full-ops');
    $rerankProvider = $manager->instance('full-rerank');
    $classifyProvider = $manager->instance('full-classify');
    $meta = new Meta('full-ops', 'responded');
    $excluded = 'EXCLUDED-MEDIA-CANARY';
    $embeddings = new EmbeddingsPrompt(['embed one', 'embed two'], 2, $provider, 'embed', providerOptions: ['secret' => $excluded]);
    $image = new ImagePrompt('draw this', [], null, null, $provider, 'image', providerOptions: ['secret' => $excluded]);
    $audio = new AudioPrompt('say this', 'voice', null, $provider, 'audio', providerOptions: ['secret' => $excluded]);
    $transcription = new TranscriptionPrompt(new Base64Audio(base64_encode($excluded)), null, false, $provider, 'transcribe', providerOptions: ['secret' => $excluded]);
    $reranking = new RerankingPrompt(['document one', 'document two'], 'find this', null, $rerankProvider, 'rerank', providerOptions: ['secret' => $excluded]);
    $classification = new ClassificationPrompt('classify this', ['flag' => new BooleanQuestion('Is it flagged?')], $classifyProvider, 'classify', providerOptions: ['secret' => $excluded]);

    $events->dispatch(new GeneratingEmbeddings('emb', $provider, 'embed', $embeddings));
    $events->dispatch(new EmbeddingsGenerated('emb', $provider, 'embed', $embeddings, new EmbeddingsResponse([[0.123, 0.456]], new SourceUsage(1, 0), $meta)));
    $events->dispatch(new GeneratingImage('img', $provider, 'image', $image));
    $events->dispatch(new ImageGenerated('img', $provider, 'image', $image, new ImageResponse(collect([new GeneratedImage(base64_encode($excluded), 'image/png')]), new ImageUsage, $meta)));
    $events->dispatch(new GeneratingAudio('audio', $provider, 'audio', $audio));
    $events->dispatch(new AudioGenerated('audio', $provider, 'audio', $audio, new AudioResponse(base64_encode($excluded), new SourceUsage, $meta)));
    $events->dispatch(new GeneratingTranscription('tx', $provider, 'transcribe', $transcription));
    $events->dispatch(new TranscriptionGenerated('tx', $provider, 'transcribe', $transcription, new TranscriptionResponse('transcribed text', collect(), new TranscriptionUsage, $meta)));
    $events->dispatch(new Reranking('rank', $rerankProvider, 'rerank', $reranking));
    $events->dispatch(new Reranked('rank', $rerankProvider, 'rerank', $reranking, new RerankingResponse([new RankedDocument(1, 'document two', 0.9)], new RerankingUsage, $meta)));
    $events->dispatch(new Classifying('class', $classifyProvider, 'classify', $classification));
    $events->dispatch(new Classified('class', $classifyProvider, 'classify', $classification, new ClassificationResponse(['flag' => new BooleanAnswer(0.8)], new TextUsage, $meta)));

    $starts = collect($recorder->records)->filter(static fn (RecordInput $record): bool => $record instanceof OperationStartInput)->keyBy(static fn (OperationStartInput $record): string => $record->operation->value);
    $ends = collect($recorder->records)->filter(static fn (RecordInput $record): bool => $record instanceof SingleOperationInput)->keyBy(static fn (SingleOperationInput $record): string => $record->operation->value);

    expect($starts['embeddings']->content?->toArray())->toBe(['inputs' => ['embed one', 'embed two']])
        ->and($starts['image']->content?->toArray())->toBe(['prompt' => 'draw this'])
        ->and($starts['audio']->content?->toArray())->toBe(['text' => 'say this'])
        ->and($starts['transcription']->content)->toBeNull()
        ->and($starts['reranking']->content?->toArray())->toBe(['query' => 'find this', 'documents' => ['document one', 'document two']])
        ->and($starts['classification']->content?->toArray())->toBe(['prompt' => 'classify this', 'labels' => ['flag']])
        ->and($ends['embeddings']->content)->toBeNull()
        ->and($ends['image']->content?->toArray())->toBe(['count' => 1])
        ->and($ends['audio']->content)->toBeNull()
        ->and($ends['transcription']->content?->toArray())->toBe(['text' => 'transcribed text'])
        ->and($ends['reranking']->content?->toArray())->toBe(['results' => [['index' => 1, 'score' => 0.9]]])
        ->and($ends['classification']->content?->toArray())->toBe(['answers' => ['flag' => ['probability' => 0.8]]])
        ->and(serialize($recorder->records))->not->toContain($excluded, base64_encode($excluded), 'EXCLUDED-CREDENTIAL');

    $dispatcher = new CollectingDispatcher;
    $buffered = new BufferedRecorder(
        'laravel-ai',
        new SourceInfo('laravel/ai', 'test'),
        new Client('artisan-build/assay-client', 'test'),
        'testing',
        null,
        100,
        86400,
        new InMemoryDropCounter,
        $dispatcher,
        app(),
    );
    foreach ($recorder->records as $record) {
        $buffered->record($record);
    }
    $buffered->flush();

    expect($dispatcher->dispatched[0]['json'])->not->toContain(
        $excluded,
        base64_encode($excluded),
        'EXCLUDED-CREDENTIAL',
        'EXCLUDED-RERANK-CREDENTIAL',
        'EXCLUDED-CLASSIFY-CREDENTIAL',
    );
});
