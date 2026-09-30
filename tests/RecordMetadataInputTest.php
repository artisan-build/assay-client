<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\RecordInputProjector;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\Records\AttemptInput;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\Records\ToolCallInput;
use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;

it('projects metadata from every source-agnostic input shape', function (): void {
    $at = new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00');
    $projector = new RecordInputProjector;
    $failureClass = RuntimeException::class;
    $inputs = [
        new RunInput(RecordType::RunEnd, 'run-1', 1, $at, agent: 'App\\Ai\\Agent', finishReason: FinishReason::Stop, outcome: Outcome::Failed, failureClass: $failureClass),
        new AttemptInput('run-1', 1, $at, agent: 'App\\Ai\\Agent', failureClass: $failureClass),
        new StepInput(RecordType::StepEnd, 'run-1', 1, 0, $at, agent: 'App\\Ai\\Agent', durationMs: 12.5, finishReason: FinishReason::ToolCalls),
        new ToolCallInput(RecordType::ToolEnd, 'run-1', 1, 'tool-1', $at, agent: 'App\\Ai\\Agent', tool: 'lookup_order', durationMs: 4.25, outcome: Outcome::Failed, failureClass: $failureClass),
        new ToolCallInput(RecordType::ToolApproval, 'run-1', 1, 'tool-2', $at, agent: 'App\\Ai\\Agent', tool: 'refund_order', approval: Approval::Approved),
        new SingleOperationInput(Operation::Image, 'image-1', $at, durationMs: 20.0, finishReason: FinishReason::Unknown, outcome: Outcome::Failed, failureClass: $failureClass),
    ];

    $records = array_map(
        static fn ($input): array => $projector->project($input, 'fake')->toArray(),
        $inputs,
    );

    expect($records[0])->toMatchArray([
        'agent' => 'App\\Ai\\Agent',
        'finish_reason' => 'stop',
        'outcome' => 'failed',
        'failure_class' => $failureClass,
    ])->and($records[1])->toMatchArray([
        'agent' => 'App\\Ai\\Agent',
        'failure_class' => $failureClass,
    ])->and($records[2])->toMatchArray([
        'agent' => 'App\\Ai\\Agent',
        'duration_ms' => 12.5,
        'finish_reason' => 'tool_calls',
    ])->and($records[3])->toMatchArray([
        'agent' => 'App\\Ai\\Agent',
        'tool' => 'lookup_order',
        'duration_ms' => 4.25,
        'outcome' => 'failed',
        'failure_class' => $failureClass,
    ])->and($records[4])->toMatchArray([
        'tool' => 'refund_order',
        'approval' => 'approved',
    ])->and($records[5])->toMatchArray([
        'duration_ms' => 20.0,
        'finish_reason' => 'unknown',
        'outcome' => 'failed',
        'failure_class' => $failureClass,
    ]);
});

it('keeps absent optional metadata absent through projection', function (): void {
    $record = (new RecordInputProjector)->project(new RunInput(
        type: RecordType::RunStart,
        invocationId: 'run-1',
        attempt: 1,
        at: new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00'),
    ), 'fake')->toArray();

    expect($record)->not->toHaveKeys([
        'agent',
        'tool',
        'duration_ms',
        'finish_reason',
        'outcome',
        'approval',
        'failure_class',
    ]);
});

it('projects attributed and unattributed failover forms without false operation data', function (): void {
    $at = new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00');
    $projector = new RecordInputProjector;
    $attributed = $projector->project(new AttemptInput(
        invocationId: 'run-1',
        attempt: 2,
        at: $at,
        parent: new ParentLink(invocationId: 'parent-1'),
        model: new ModelInfo(requested: 'requested-model', provider: 'provider-name'),
    ), 'fake')->toArray();
    $unattributed = $projector->project(new AttemptInput(
        invocationId: null,
        attempt: null,
        at: $at,
        model: new ModelInfo(requested: 'requested-model', provider: 'provider-name'),
        failureClass: RuntimeException::class,
    ), 'fake')->toArray();

    expect($attributed)->toMatchArray([
        'operation' => Operation::Agent->value,
        'invocation_id' => 'run-1',
        'attempt' => 2,
        'parent_invocation_id' => 'parent-1',
    ])->and($unattributed)->toMatchArray([
        'type' => RecordType::RunFailover->value,
        'model' => [
            'requested' => 'requested-model',
            'provider' => 'provider-name',
        ],
        'failure_class' => RuntimeException::class,
    ])->and($unattributed)->not->toHaveKeys([
        'operation',
        'invocation_id',
        'attempt',
        'parent_invocation_id',
        'parent_tool_invocation_id',
    ]);
});

it('rejects incomplete source-agnostic failover forms', function (Closure $construct): void {
    expect($construct)->toThrow(InvalidArgumentException::class);
})->with([
    'attributed without attempt' => fn () => new AttemptInput('run-1', null, new DateTimeImmutable),
    'unattributed with attempt' => fn () => new AttemptInput(null, 1, new DateTimeImmutable, model: new ModelInfo(requested: 'model', provider: 'provider')),
    'unattributed with parent' => fn () => new AttemptInput(null, null, new DateTimeImmutable, parent: new ParentLink(invocationId: 'parent-1'), model: new ModelInfo(requested: 'model', provider: 'provider')),
    'unattributed without model' => fn () => new AttemptInput(null, null, new DateTimeImmutable),
    'unattributed without provider' => fn () => new AttemptInput(null, null, new DateTimeImmutable, model: new ModelInfo(requested: 'model')),
    'unattributed without requested model' => fn () => new AttemptInput(null, null, new DateTimeImmutable, model: new ModelInfo(provider: 'provider')),
]);

it('rejects missing required input metadata', function (Closure $construct): void {
    expect($construct)->toThrow(InvalidArgumentException::class);
})->with([
    'run outcome' => fn () => new RunInput(RecordType::RunEnd, 'run-1', 1, new DateTimeImmutable),
    'tool outcome' => fn () => new ToolCallInput(RecordType::ToolEnd, 'run-1', 1, 'tool-1', new DateTimeImmutable),
    'tool approval' => fn () => new ToolCallInput(RecordType::ToolApproval, 'run-1', 1, 'tool-1', new DateTimeImmutable),
    'operation outcome' => fn () => new SingleOperationInput(Operation::Image, 'image-1', new DateTimeImmutable),
]);

it('rejects inapplicable or unsafe input metadata early', function (Closure $construct): void {
    expect($construct)->toThrow(InvalidArgumentException::class);
})->with([
    'run-start outcome' => fn () => new RunInput(RecordType::RunStart, 'run-1', 1, new DateTimeImmutable, outcome: Outcome::Completed),
    'run-start finish' => fn () => new RunInput(RecordType::RunStart, 'run-1', 1, new DateTimeImmutable, finishReason: FinishReason::Stop),
    'step-start duration' => fn () => new StepInput(RecordType::StepStart, 'run-1', 1, 0, new DateTimeImmutable, durationMs: 1.0),
    'step-end failure' => fn () => new StepInput(RecordType::StepEnd, 'run-1', 1, 0, new DateTimeImmutable, failureClass: RuntimeException::class),
    'tool-start approval' => fn () => new ToolCallInput(RecordType::ToolStart, 'run-1', 1, 'tool-1', new DateTimeImmutable, approval: Approval::Requested),
    'completed tool failure' => fn () => new ToolCallInput(RecordType::ToolEnd, 'run-1', 1, 'tool-1', new DateTimeImmutable, outcome: Outcome::Completed, failureClass: RuntimeException::class),
    'negative duration' => fn () => new SingleOperationInput(Operation::Image, 'image-1', new DateTimeImmutable, durationMs: -0.1, outcome: Outcome::Completed),
    'non-finite duration' => fn () => new StepInput(RecordType::StepEnd, 'run-1', 1, 0, new DateTimeImmutable, durationMs: INF),
    'message as failure class' => fn () => new AttemptInput('run-1', 1, new DateTimeImmutable, failureClass: 'secret exception message'),
    'control in agent' => fn () => new RunInput(RecordType::RunStart, 'run-1', 1, new DateTimeImmutable, agent: "App\\Ai\nAgent"),
]);
