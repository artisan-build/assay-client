<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

use ArtisanBuild\AssayClient\Internal\DroppedRecordInput;
use ArtisanBuild\AssayClient\Internal\InvocationState;
use ArtisanBuild\AssayClient\Records\AttemptInput;
use ArtisanBuild\AssayClient\Records\OperationStartInput;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\Records\ToolCallInput;
use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\ReplayInputOmission;
use Closure;
use Composer\InstalledVersions;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
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
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage as SourceUsage;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Throwable;

final class LaravelAiDriver implements CaptureDriver
{
    private readonly InvocationState $invocations;

    private readonly Closure $clock;

    private ?Recorder $recorder = null;

    /** @var array<string, array<string, Approval>> */
    private array $approvalDecisions = [];

    /** @param (Closure(): DateTimeImmutable)|null $clock */
    public function __construct(private readonly Dispatcher $events, ?Closure $clock = null)
    {
        $this->invocations = new InvocationState;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;
    }

    public function name(): string
    {
        return 'laravel-ai';
    }

    public function source(): ?SourceInfo
    {
        if (! class_exists(AiServiceProvider::class)) {
            return null;
        }

        $version = InstalledVersions::getPrettyVersion('laravel/ai')
            ?? InstalledVersions::getVersion('laravel/ai');

        return is_string($version) && $version !== ''
            ? new SourceInfo('laravel/ai', ltrim($version, 'v'))
            : null;
    }

    public function register(Recorder $recorder): void
    {
        if ($this->source() === null) {
            return;
        }

        $this->recorder = $recorder;

        $this->listen(PromptingAgent::class, fn (PromptingAgent $event) => $this->agentStarted($event));
        $this->listen(StreamingAgent::class, fn (StreamingAgent $event) => $this->agentStarted($event));
        $this->listen(AgentPrompted::class, fn (AgentPrompted $event) => $this->agentCompleted($event));
        $this->listen(AgentStreamed::class, fn (AgentStreamed $event) => $this->agentCompleted($event));
        $this->listen(AgentFailed::class, fn (AgentFailed $event) => $this->agentFailed($event));
        $this->listen(AgentFailedOver::class, fn (AgentFailedOver $event) => $this->agentFailedOver($event));
        $this->listen(StartingStep::class, fn (StartingStep $event) => $this->stepStarted($event));
        $this->listen(StepCompleted::class, fn (StepCompleted $event) => $this->stepCompleted($event));
        $this->listen(StepFailed::class, fn (StepFailed $event) => $this->stepFailed($event));
        $this->listen(InvokingTool::class, fn (InvokingTool $event) => $this->toolStarted($event));
        $this->listen(ToolInvoked::class, fn (ToolInvoked $event) => $this->toolCompleted($event));
        $this->listen(ToolFailed::class, fn (ToolFailed $event) => $this->toolFailed($event));
        $this->listen(ToolApprovalRequested::class, fn (ToolApprovalRequested $event) => $this->approvalRequested($event));
        $this->listen(ToolApprovalResolved::class, fn (ToolApprovalResolved $event) => $this->approvalResolved($event));

        $this->listen(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event) => $this->operationStarted(Operation::Embeddings, $event->invocationId, $event->model, $event->provider->name(), $event->prompt));
        $this->listen(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event) => $this->operationCompleted(Operation::Embeddings, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage, $event->response));
        $this->listen(GeneratingImage::class, fn (GeneratingImage $event) => $this->operationStarted(Operation::Image, $event->invocationId, $event->model, $event->provider->name(), $event->prompt));
        $this->listen(ImageGenerated::class, fn (ImageGenerated $event) => $this->operationCompleted(Operation::Image, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage, $event->response));
        $this->listen(GeneratingAudio::class, fn (GeneratingAudio $event) => $this->operationStarted(Operation::Audio, $event->invocationId, $event->model, $event->provider->name(), $event->prompt));
        $this->listen(AudioGenerated::class, fn (AudioGenerated $event) => $this->operationCompleted(Operation::Audio, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage, $event->response));
        $this->listen(GeneratingTranscription::class, fn (GeneratingTranscription $event) => $this->operationStarted(Operation::Transcription, $event->invocationId, $event->model, $event->provider->name(), $event->prompt));
        $this->listen(TranscriptionGenerated::class, fn (TranscriptionGenerated $event) => $this->operationCompleted(Operation::Transcription, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage, $event->response));
        $this->listen(Reranking::class, fn (Reranking $event) => $this->operationStarted(Operation::Reranking, $event->invocationId, $event->model, $event->provider->name(), $event->prompt));
        $this->listen(Reranked::class, fn (Reranked $event) => $this->operationCompleted(Operation::Reranking, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage, $event->response));
        $this->listen(Classifying::class, fn (Classifying $event) => $this->operationStarted(Operation::Classification, $event->invocationId, $event->model, $event->provider->name(), $event->prompt));
        $this->listen(Classified::class, fn (Classified $event) => $this->operationCompleted(Operation::Classification, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage, $event->response));
        $this->listen(ProviderFailedOver::class, fn (ProviderFailedOver $event) => $this->operationFailedOver($event));
    }

    /** @param class-string $event */
    private function listen(string $event, callable $listener): void
    {
        $this->events->listen($event, function (object $event) use ($listener): void {
            try {
                $listener($event);
            } catch (Throwable) {
                try {
                    $this->recorder?->record(new DroppedRecordInput);
                } catch (Throwable) {
                    // Telemetry failures cannot escape into the host application.
                }
            }
        });
    }

    private function agentStarted(PromptingAgent $event): void
    {
        $parent = $this->parent($event->prompt->parentInvocationId, $event->prompt->parentToolInvocationId);
        $attempt = $this->invocations->begin($event->invocationId, $parent);
        $decisions = $event->prompt->approvalDecisions?->all();

        if ($decisions !== null) {
            $this->approvalDecisions[$event->invocationId] = array_map(
                fn (Decision $decision): Approval => $this->approval($decision->action),
                $decisions,
            );
        } else {
            unset($this->approvalDecisions[$event->invocationId]);
        }

        try {
            $this->invocations->addReplayInputOmissions($event->invocationId, $this->replayInputOmissions($event->prompt));
            $this->record(new RunInput(
                RecordType::RunStart,
                $event->invocationId,
                $attempt,
                $this->now(),
                parent: $parent,
                model: new ModelInfo(requested: $event->prompt->model, provider: $event->prompt->provider()->name()),
                agent: $event->prompt->agent::class,
                capture: $this->capture(),
                sampled: $this->capture() === CaptureMode::Full,
                content: $this->content($this->agentStartContent($event->prompt)),
            ));
        } catch (Throwable $exception) {
            $this->invocations->finish($event->invocationId);
            unset($this->approvalDecisions[$event->invocationId]);

            throw $exception;
        }
    }

    private function agentCompleted(AgentPrompted $event): void
    {
        $retainApproval = $event->response->hasPendingApprovals() || $event->prompt->approvalDecisions !== null;
        $recorded = false;

        try {
            $lastStep = $event->response->steps->last();
            $this->record(new RunInput(
                RecordType::RunEnd,
                $event->invocationId,
                $this->invocations->attempt($event->invocationId),
                $this->now(),
                parent: $this->invocations->parent($event->invocationId),
                model: new ModelInfo(
                    requested: $event->prompt->model,
                    responded: $event->response->meta->model,
                    provider: $event->response->meta->provider ?? $event->prompt->provider()->name(),
                ),
                agent: $event->prompt->agent::class,
                usage: $this->usage($event->response->usage),
                finishReason: $lastStep === null ? null : $this->finishReason($lastStep->finishReason->value),
                outcome: Outcome::Completed,
                replayInputsOmitted: $this->invocations->replayInputOmissions($event->invocationId),
                capture: $this->capture(),
                sampled: $this->capture() === CaptureMode::Full,
            ));
            $recorded = true;
        } finally {
            $this->invocations->finish($event->invocationId, $recorded && $retainApproval);

            if (! $recorded || $event->prompt->approvalDecisions === null) {
                unset($this->approvalDecisions[$event->invocationId]);
            }
        }
    }

    private function agentFailed(AgentFailed $event): void
    {
        try {
            $this->record(new RunInput(
                RecordType::RunEnd,
                $event->invocationId,
                $this->invocations->attempt($event->invocationId),
                $this->now(),
                parent: $this->invocations->parent($event->invocationId),
                model: new ModelInfo(requested: $event->prompt->model, provider: $event->prompt->provider()->name()),
                agent: $event->prompt->agent::class,
                outcome: Outcome::Failed,
                failureClass: $event->exception::class,
                replayInputsOmitted: $this->invocations->replayInputOmissions($event->invocationId),
                capture: $this->capture(),
                sampled: $this->capture() === CaptureMode::Full,
                content: $this->content(['exception_message' => $event->exception->getMessage()]),
            ));
        } finally {
            $this->invocations->finish($event->invocationId);
            unset($this->approvalDecisions[$event->invocationId]);
        }
    }

    private function agentFailedOver(AgentFailedOver $event): void
    {
        $this->record(new AttemptInput(
            $event->invocationId,
            $this->invocations->attempt($event->invocationId),
            $this->now(),
            parent: $this->invocations->parent($event->invocationId),
            model: new ModelInfo(requested: $event->model, provider: $event->provider->name()),
            agent: $event->agent::class,
            failureClass: $event->exception::class,
            operation: Operation::Agent,
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
        ));
    }

    private function stepStarted(StartingStep $event): void
    {
        $this->record(new StepInput(
            RecordType::StepStart,
            $event->invocationId,
            $this->invocations->attempt($event->invocationId),
            $event->stepNumber,
            $this->now(),
            parent: $this->invocations->parent($event->invocationId),
            model: new ModelInfo(requested: $event->model, provider: $event->provider->name()),
            agent: $event->agent::class,
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            content: $this->content($this->stepMessages($event->messages)),
        ));
    }

    private function stepCompleted(StepCompleted $event): void
    {
        $this->record(new StepInput(
            RecordType::StepEnd,
            $event->invocationId,
            $this->invocations->attempt($event->invocationId),
            $event->stepNumber,
            $this->now(),
            parent: $this->invocations->parent($event->invocationId),
            usage: $this->usage($event->response->usage),
            model: new ModelInfo(requested: $event->model, responded: $event->response->meta->model, provider: $event->response->meta->provider ?? $event->provider->name()),
            agent: $event->agent::class,
            durationMs: $event->time,
            finishReason: $this->finishReason($event->response->finishReason->value),
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            content: $this->content($this->stepEndContent($event->response)),
        ));
    }

    private function stepFailed(StepFailed $event): void
    {
        $this->record(new StepInput(
            RecordType::StepFail,
            $event->invocationId,
            $this->invocations->attempt($event->invocationId),
            $event->stepNumber,
            $this->now(),
            parent: $this->invocations->parent($event->invocationId),
            model: new ModelInfo(requested: $event->model, provider: $event->provider->name()),
            agent: $event->agent::class,
            durationMs: $event->time,
            failureClass: $event->exception::class,
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            content: $this->content(['exception_message' => $event->exception->getMessage()]),
        ));
    }

    private function toolStarted(InvokingTool $event): void
    {
        $this->record(new ToolCallInput(
            RecordType::ToolStart,
            $event->invocationId,
            $this->invocations->attempt($event->invocationId),
            $event->toolInvocationId,
            $this->now(),
            parent: $this->invocations->parent($event->invocationId),
            agent: $event->agent::class,
            tool: $this->toolName($event->tool),
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            content: $this->content(['arguments' => $event->arguments]),
        ));
    }

    private function toolCompleted(ToolInvoked $event): void
    {
        $this->record(new ToolCallInput(
            RecordType::ToolEnd,
            $event->invocationId,
            $this->invocations->attempt($event->invocationId),
            $event->toolInvocationId,
            $this->now(),
            parent: $this->invocations->parent($event->invocationId),
            agent: $event->agent::class,
            tool: $this->toolName($event->tool),
            durationMs: $event->time,
            outcome: Outcome::Completed,
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            content: $this->content(['result' => $event->result]),
        ));
    }

    private function toolFailed(ToolFailed $event): void
    {
        $this->record(new ToolCallInput(
            RecordType::ToolEnd,
            $event->invocationId,
            $this->invocations->attempt($event->invocationId),
            $event->toolInvocationId,
            $this->now(),
            parent: $this->invocations->parent($event->invocationId),
            agent: $event->agent::class,
            tool: $this->toolName($event->tool),
            durationMs: $event->time,
            outcome: Outcome::Failed,
            failureClass: $event->exception::class,
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            content: $this->content(['exception_message' => $event->exception->getMessage()]),
        ));
    }

    private function approvalRequested(ToolApprovalRequested $event): void
    {
        $snapshot = $this->invocations->approvalSnapshot($event->invocationId);
        $recorded = false;

        try {
            foreach ($event->pendingApprovals as $approval) {
                $this->record(new ToolCallInput(
                    RecordType::ToolApproval,
                    $event->invocationId,
                    $snapshot['attempt'] ?? $this->invocations->attempt($event->invocationId),
                    $approval->id,
                    $this->now(),
                    parent: $snapshot['parent'] ?? $this->invocations->parent($event->invocationId),
                    agent: $event->agent::class,
                    tool: $approval->tool,
                    approval: Approval::Requested,
                ));
            }
            $recorded = true;
        } finally {
            if (! $recorded) {
                unset($this->approvalDecisions[$event->invocationId]);
            }

            if (! $recorded || ! isset($this->approvalDecisions[$event->invocationId])) {
                $this->invocations->finishApproval($event->invocationId);
            }
        }
    }

    private function approvalResolved(ToolApprovalResolved $event): void
    {
        $snapshot = $this->invocations->approvalSnapshot($event->invocationId);

        try {
            foreach ($event->toolResults as $result) {
                $approval = $this->approvalDecisions[$event->invocationId][$result->id]
                    ?? ($result->denied ? Approval::Rejected : Approval::Approved);

                $this->record(new ToolCallInput(
                    RecordType::ToolApproval,
                    $event->invocationId,
                    $snapshot['attempt'] ?? $this->invocations->attempt($event->invocationId),
                    $result->id,
                    $this->now(),
                    parent: $snapshot['parent'] ?? $this->invocations->parent($event->invocationId),
                    agent: $event->agent::class,
                    tool: $result->name,
                    approval: $approval,
                ));
            }
        } finally {
            unset($this->approvalDecisions[$event->invocationId]);
            $this->invocations->finishApproval($event->invocationId);
        }
    }

    private function operationStarted(Operation $operation, string $invocationId, string $model, string $provider, object $prompt): void
    {
        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        $this->record(new OperationStartInput(
            $operation,
            $invocationId,
            $this->now(),
            parent: $this->parent($parentInvocationId, $parentToolInvocationId),
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            subject: null,
            model: new ModelInfo(requested: $model, provider: $provider),
            content: $this->content($this->operationStartContent($operation, $prompt)),
        ));
    }

    private function operationCompleted(
        Operation $operation,
        string $invocationId,
        string $requestedModel,
        string $requestedProvider,
        ?string $respondedModel,
        ?string $respondedProvider,
        SourceUsage $usage,
        object $response,
    ): void {
        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        $this->record(new SingleOperationInput(
            $operation,
            $invocationId,
            $this->now(),
            parent: $this->parent($parentInvocationId, $parentToolInvocationId),
            usage: $this->usage($usage),
            model: new ModelInfo(
                requested: $requestedModel,
                responded: $respondedModel,
                provider: $respondedProvider ?? $requestedProvider,
            ),
            outcome: Outcome::Completed,
            capture: $this->capture(),
            sampled: $this->capture() === CaptureMode::Full,
            content: $this->content($this->operationEndContent($operation, $response)),
        ));
    }

    private function operationFailedOver(ProviderFailedOver $event): void
    {
        $this->record(new AttemptInput(
            null,
            null,
            $this->now(),
            model: new ModelInfo(requested: $event->model, provider: $event->provider->name()),
            failureClass: $event->exception::class,
        ));
    }

    /** @return array<string, mixed> */
    private function agentStartContent(AgentPrompt $prompt): array
    {
        $content = ['instructions' => (string) $prompt->agent->instructions()];

        if (! $prompt->agent instanceof HasTools) {
            return $content;
        }

        $tools = [];

        foreach ($prompt->agent->tools() as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }

            $tools[] = [
                'name' => $this->toolName($tool),
                'description' => (string) $tool->description(),
                'parameters' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toArray(),
            ];
        }

        if ($tools !== []) {
            $content['tools'] = $tools;
        }

        return $content;
    }

    /**
     * @param  array<array-key, Message>  $messages
     * @return array<string, mixed>
     */
    private function stepMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $normalized[] = [
                        'role' => 'tool',
                        'text' => $result->text(),
                        'tool_call_id' => $result->id,
                    ];
                }

                continue;
            }

            $body = [
                'role' => $message->role->value,
            ];

            if ($message->content !== null) {
                $body['text'] = $message->content;
            }

            if ($message instanceof AssistantMessage) {
                $calls = $this->toolCalls($message->toolCalls->all());

                if ($calls !== []) {
                    $body['tool_calls'] = $calls;
                }
            }

            $normalized[] = $body;
        }

        $hashes = [];
        $bodies = [];

        foreach ($normalized as $message) {
            $hash = $this->messageHash($message);
            $hashes[] = $hash;
            $bodies[$hash] = $message;
        }

        if ($hashes === []) {
            return [];
        }

        return ['message_hashes' => $hashes, 'new_messages' => $bodies];
    }

    /** @return array<string, mixed> */
    private function stepEndContent(StepResponse $response): array
    {
        $content = ['output_text' => $response->text];

        if ($response->structured !== null && $this->isPlainJson($response->structured)) {
            $content['structured_output'] = $response->structured;
        }

        $calls = $this->toolCalls($response->toolCalls);

        if ($calls !== []) {
            $content['tool_calls'] = $calls;
        }

        return $content;
    }

    /**
     * @param  array<array-key, mixed>  $calls
     * @return list<array{id: string, name: string, arguments: mixed}>
     */
    private function toolCalls(array $calls): array
    {
        $normalized = [];

        foreach ($calls as $call) {
            if (! $call instanceof ToolCall || ! $this->isPlainJson($call->arguments)) {
                continue;
            }

            $normalized[] = [
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => $call->arguments,
            ];
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function operationStartContent(Operation $operation, object $prompt): array
    {
        return match (true) {
            $operation === Operation::Embeddings && $prompt instanceof EmbeddingsPrompt => ['inputs' => $prompt->inputs],
            $operation === Operation::Image && $prompt instanceof ImagePrompt => ['prompt' => $prompt->prompt],
            $operation === Operation::Audio && $prompt instanceof AudioPrompt => ['text' => $prompt->text],
            $operation === Operation::Reranking && $prompt instanceof RerankingPrompt => ['query' => $prompt->query, 'documents' => $prompt->documents],
            $operation === Operation::Classification && $prompt instanceof ClassificationPrompt && is_string($prompt->state) => [
                'prompt' => $prompt->state,
                'labels' => array_keys($prompt->questions),
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function operationEndContent(Operation $operation, object $response): array
    {
        return match (true) {
            $operation === Operation::Image && $response instanceof ImageResponse => ['count' => $response->count()],
            $operation === Operation::Transcription && $response instanceof TranscriptionResponse => ['text' => $response->text],
            $operation === Operation::Reranking && $response instanceof RerankingResponse => [
                'results' => array_map(static fn ($result): array => [
                    'index' => $result->index,
                    'score' => $result->score,
                ], $response->results),
            ],
            $operation === Operation::Classification && $response instanceof ClassificationResponse => [
                'answers' => array_map(static fn ($answer): array => $answer->toArray(), $response->answers),
            ],
            default => [],
        };
    }

    /** @param array<string, mixed> $data */
    private function content(array $data): ?Content
    {
        return $this->capture() === CaptureMode::Full && $data !== [] && $this->isPlainJson($data)
            ? new Content($data)
            : null;
    }

    private function capture(): CaptureMode
    {
        return CaptureMode::tryFrom((string) config('assay.capture', CaptureMode::Usage->value))
            ?? CaptureMode::Usage;
    }

    /** @param array<string, mixed> $message */
    private function messageHash(array $message): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($message),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map($this->canonicalize(...), $value);
    }

    private function isPlainJson(mixed $value): bool
    {
        if (is_null($value) || is_scalar($value)) {
            return ! is_float($value) || is_finite($value);
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isPlainJson($item)) {
                return false;
            }
        }

        return true;
    }

    private function approval(string $action): Approval
    {
        return match ($action) {
            'approve' => Approval::Approved,
            'reject' => Approval::Rejected,
            default => Approval::Other,
        };
    }

    /** @return list<ReplayInputOmission> */
    private function replayInputOmissions(AgentPrompt $prompt): array
    {
        $messages = $prompt->messages ?? [];
        $hasAttachments = $prompt->attachments->isNotEmpty();
        $hasReplayState = false;

        foreach ($messages as $message) {
            $hasAttachments = $hasAttachments || ($message instanceof UserMessage && $message->attachments->isNotEmpty());
            $hasReplayState = $hasReplayState || ($message instanceof AssistantMessage && $message->replayBlocks !== []);
        }

        $hasProviderOptions = $prompt->agent instanceof HasProviderOptions
            && $prompt->agent->providerOptions($prompt->provider()->name()) !== [];

        return array_values(array_filter([
            $hasAttachments ? ReplayInputOmission::Attachments : null,
            $prompt->agent instanceof HasStructuredOutput ? ReplayInputOmission::OutputSchema : null,
            $hasProviderOptions ? ReplayInputOmission::ProviderOptions : null,
            $hasReplayState ? ReplayInputOmission::ProviderReplayState : null,
        ]));
    }

    private function usage(SourceUsage $usage): Usage
    {
        if ($usage instanceof RerankingUsage) {
            return new Usage(inputTokens: $usage->inputTokens, searchUnits: $usage->searchUnits);
        }

        return new Usage(
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadInputTokens: $usage instanceof TextUsage ? $usage->cacheReadInputTokens : null,
            cacheWriteInputTokens: $usage instanceof TextUsage ? $usage->cacheWriteInputTokens : null,
            reasoningTokens: $usage instanceof TextUsage ? $usage->reasoningTokens : null,
            imageInputTokens: $usage instanceof ImageUsage ? $usage->imageInputTokens : null,
            imageOutputTokens: $usage instanceof ImageUsage ? $usage->imageOutputTokens : null,
            audioSeconds: $usage instanceof TranscriptionUsage ? $usage->audioSeconds : null,
        );
    }

    private function finishReason(string $value): FinishReason
    {
        return FinishReason::tryFrom($value) ?? FinishReason::Unknown;
    }

    private function parent(?string $invocationId, ?string $toolInvocationId): ?ParentLink
    {
        return $invocationId === null && $toolInvocationId === null
            ? null
            : new ParentLink($invocationId, $toolInvocationId);
    }

    private function toolName(Tool $tool): string
    {
        return is_callable([$tool, 'name']) ? $tool->name() : $tool::class;
    }

    private function record(RecordInput $input): void
    {
        $this->recorder?->record($input);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
