<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\HookBoundary;
use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\FailureCapture;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\Model;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\ReplayInputOmission;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\Usage;
use ArtisanBuild\AssayContracts\UuidV7;

it('restores every usage-class field after a hook and accepts only content changes', function (): void {
    $records = [
        new RecordV1(
            recordId: UuidV7::generate(),
            source: 'fake',
            type: RecordType::RunEnd,
            operation: Operation::Agent,
            at: new Timestamp('2026-09-30T12:34:56.123456Z'),
            capture: CaptureMode::Full,
            sampled: false,
            invocationId: 'run-1',
            attempt: 2,
            parentInvocationId: 'parent-1',
            parentToolInvocationId: 'parent-tool-1',
            subject: 'user:1',
            usage: new Usage(inputTokens: 3),
            model: new Model(requested: 'model-a', responded: 'model-b', provider: 'provider-a'),
            content: new Content(['exception_message' => 'before']),
            agent: 'App\\Ai\\Agent',
            finishReason: FinishReason::Error,
            outcome: Outcome::Failed,
            failureClass: RuntimeException::class,
            failureCapture: FailureCapture::Truncated,
            replayInputsOmitted: [ReplayInputOmission::ProviderOptions],
        ),
        new RecordV1(
            recordId: UuidV7::generate(),
            source: 'fake',
            type: RecordType::ToolEnd,
            operation: Operation::Agent,
            at: new Timestamp('2026-09-30T12:34:57.123456Z'),
            capture: CaptureMode::Full,
            sampled: true,
            invocationId: 'run-1',
            attempt: 2,
            step: 4,
            toolInvocationId: 'tool-1',
            content: new Content(['result' => 'before']),
            agent: 'App\\Ai\\Agent',
            tool: 'lookup_order',
            outcome: Outcome::Completed,
        ),
        new RecordV1(
            recordId: UuidV7::generate(),
            source: 'fake',
            type: RecordType::ToolApproval,
            operation: Operation::Agent,
            at: new Timestamp('2026-09-30T12:34:57.623456Z'),
            capture: CaptureMode::Full,
            sampled: true,
            invocationId: 'run-1',
            attempt: 2,
            step: 4,
            toolInvocationId: 'tool-1',
            agent: 'App\\Ai\\Agent',
            tool: 'lookup_order',
            approval: Approval::Approved,
        ),
        new RecordV1(
            recordId: UuidV7::generate(),
            source: 'fake',
            type: RecordType::RunEnd,
            operation: Operation::Image,
            at: new Timestamp('2026-09-30T12:34:58.123456Z'),
            capture: CaptureMode::Full,
            sampled: true,
            invocationId: 'image-1',
            content: new Content(['count' => 1]),
            durationMs: 4.5,
            outcome: Outcome::Completed,
        ),
    ];
    $boundary = new HookBoundary;

    foreach ($records as $original) {
        $protected = $original->toArray();
        $filtered = array_fill_keys(array_keys($protected), 'hook-corruption');
        $content = match ($original->type) {
            RecordType::RunEnd => $original->operation === Operation::Agent
                ? ['exception_message' => 'after']
                : ['count' => 2],
            RecordType::ToolEnd => ['result' => 'after'],
            RecordType::ToolApproval => null,
            default => throw new LogicException('Unexpected fixture.'),
        };

        if ($content === null) {
            unset($filtered['content']);
        } else {
            $filtered['content'] = $content;
        }

        $restoredRecord = $boundary->restore($original, $filtered);
        $restored = $restoredRecord->toArray();
        $expected = $protected;
        unset($restored['content'], $expected['content']);

        expect($restored)->toBe($expected)
            ->and($restoredRecord->approval)->toBe($original->approval)
            ->and($restoredRecord->content?->toArray())->toBe($content);
    }
});

it('lets a hook remove or empty content without changing protected fields', function (array $filtered): void {
    $original = new RecordV1(
        recordId: UuidV7::generate(),
        source: 'fake',
        type: RecordType::RunStart,
        operation: Operation::Agent,
        at: new Timestamp('2026-09-30T12:34:56.123456Z'),
        capture: CaptureMode::Full,
        sampled: true,
        invocationId: 'run-1',
        attempt: 1,
        content: new Content(['instructions' => 'before']),
    );
    $expected = $original->toArray();
    unset($expected['content']);

    expect((new HookBoundary)->restore($original, $filtered)->toArray())->toBe($expected);
})->with([
    'removed' => [['source' => 'changed']],
    'empty object' => [['content' => (object) []]],
]);
