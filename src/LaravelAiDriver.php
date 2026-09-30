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
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\ReplayInputOmission;
use Closure;
use Composer\InstalledVersions;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
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
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage as SourceUsage;
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

        $this->listen(GeneratingEmbeddings::class, fn (GeneratingEmbeddings $event) => $this->operationStarted(Operation::Embeddings, $event->invocationId, $event->model, $event->provider->name()));
        $this->listen(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event) => $this->operationCompleted(Operation::Embeddings, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage));
        $this->listen(GeneratingImage::class, fn (GeneratingImage $event) => $this->operationStarted(Operation::Image, $event->invocationId, $event->model, $event->provider->name()));
        $this->listen(ImageGenerated::class, fn (ImageGenerated $event) => $this->operationCompleted(Operation::Image, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage));
        $this->listen(GeneratingAudio::class, fn (GeneratingAudio $event) => $this->operationStarted(Operation::Audio, $event->invocationId, $event->model, $event->provider->name()));
        $this->listen(AudioGenerated::class, fn (AudioGenerated $event) => $this->operationCompleted(Operation::Audio, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage));
        $this->listen(GeneratingTranscription::class, fn (GeneratingTranscription $event) => $this->operationStarted(Operation::Transcription, $event->invocationId, $event->model, $event->provider->name()));
        $this->listen(TranscriptionGenerated::class, fn (TranscriptionGenerated $event) => $this->operationCompleted(Operation::Transcription, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage));
        $this->listen(Reranking::class, fn (Reranking $event) => $this->operationStarted(Operation::Reranking, $event->invocationId, $event->model, $event->provider->name()));
        $this->listen(Reranked::class, fn (Reranked $event) => $this->operationCompleted(Operation::Reranking, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage));
        $this->listen(Classifying::class, fn (Classifying $event) => $this->operationStarted(Operation::Classification, $event->invocationId, $event->model, $event->provider->name()));
        $this->listen(Classified::class, fn (Classified $event) => $this->operationCompleted(Operation::Classification, $event->invocationId, $event->model, $event->provider->name(), $event->response->meta->model, $event->response->meta->provider, $event->response->usage));
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

    private function operationStarted(Operation $operation, string $invocationId, string $model, string $provider): void
    {
        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        $this->record(new OperationStartInput(
            $operation,
            $invocationId,
            $this->now(),
            parent: $this->parent($parentInvocationId, $parentToolInvocationId),
            capture: CaptureMode::Usage,
            sampled: false,
            subject: null,
            model: new ModelInfo(requested: $model, provider: $provider),
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
