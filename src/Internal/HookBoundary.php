<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\RecordV1;
use stdClass;

final class HookBoundary
{
    /** @param array<string, mixed> $filtered */
    public function restore(RecordV1 $original, array $filtered): RecordV1
    {
        $value = $filtered['content'] ?? null;
        $content = array_key_exists('content', $filtered)
            && ! ($value instanceof stdClass && get_object_vars($value) === [])
            && $value !== []
                ? Content::fromValue($value)
                : null;

        return new RecordV1(
            recordId: $original->recordId,
            source: $original->source,
            type: $original->type,
            operation: $original->operation,
            at: $original->at,
            capture: $original->capture,
            sampled: $original->sampled,
            invocationId: $original->invocationId,
            attempt: $original->attempt,
            parentInvocationId: $original->parentInvocationId,
            parentToolInvocationId: $original->parentToolInvocationId,
            step: $original->step,
            toolInvocationId: $original->toolInvocationId,
            subject: $original->subject,
            usage: $original->usage,
            model: $original->model,
            content: $content,
            agent: $original->agent,
            tool: $original->tool,
            durationMs: $original->durationMs,
            finishReason: $original->finishReason,
            outcome: $original->outcome,
            approval: $original->approval,
            failureClass: $original->failureClass,
            failureCapture: $original->failureCapture,
            replayInputsOmitted: $original->replayInputsOmitted,
        );
    }
}
