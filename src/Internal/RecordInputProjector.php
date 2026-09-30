<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Records\AttemptInput;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\Records\ToolCallInput;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;
use InvalidArgumentException;

final class RecordInputProjector
{
    public function project(RecordInput $input, string $source): RecordV1
    {
        return match (true) {
            $input instanceof RunInput => $this->run($input, $source),
            $input instanceof AttemptInput => $this->attempt($input, $source),
            $input instanceof StepInput => $this->step($input, $source),
            $input instanceof ToolCallInput => $this->tool($input, $source),
            $input instanceof SingleOperationInput => $this->operation($input, $source),
            default => throw new InvalidArgumentException('Unsupported recorder input '.get_debug_type($input).'.'),
        };
    }

    private function run(RunInput $input, string $source): RecordV1
    {
        return new RecordV1(
            recordId: UuidV7::generate(),
            source: $source,
            type: $input->type,
            operation: Operation::Agent,
            at: Timestamp::fromDateTime($input->at),
            capture: $input->capture,
            sampled: $input->sampled,
            invocationId: $input->invocationId,
            attempt: $input->attempt,
            parentInvocationId: $input->parent?->invocationId,
            parentToolInvocationId: $input->parent?->toolInvocationId,
            subject: $input->subject,
            usage: $input->usage?->toContract(),
            model: $input->model?->toContract(),
        );
    }

    private function attempt(AttemptInput $input, string $source): RecordV1
    {
        return new RecordV1(
            recordId: UuidV7::generate(),
            source: $source,
            type: RecordType::RunFailover,
            operation: Operation::Agent,
            at: Timestamp::fromDateTime($input->at),
            capture: $input->capture,
            sampled: $input->sampled,
            invocationId: $input->invocationId,
            attempt: $input->attempt,
            parentInvocationId: $input->parent?->invocationId,
            parentToolInvocationId: $input->parent?->toolInvocationId,
            subject: $input->subject,
            usage: $input->usage?->toContract(),
            model: $input->model?->toContract(),
        );
    }

    private function step(StepInput $input, string $source): RecordV1
    {
        return new RecordV1(
            recordId: UuidV7::generate(),
            source: $source,
            type: $input->type,
            operation: Operation::Agent,
            at: Timestamp::fromDateTime($input->at),
            capture: $input->capture,
            sampled: $input->sampled,
            invocationId: $input->invocationId,
            attempt: $input->attempt,
            parentInvocationId: $input->parent?->invocationId,
            parentToolInvocationId: $input->parent?->toolInvocationId,
            step: $input->step,
            subject: $input->subject,
            usage: $input->usage?->toContract(),
            model: $input->model?->toContract(),
        );
    }

    private function tool(ToolCallInput $input, string $source): RecordV1
    {
        return new RecordV1(
            recordId: UuidV7::generate(),
            source: $source,
            type: $input->type,
            operation: Operation::Agent,
            at: Timestamp::fromDateTime($input->at),
            capture: $input->capture,
            sampled: $input->sampled,
            invocationId: $input->invocationId,
            attempt: $input->attempt,
            parentInvocationId: $input->parent?->invocationId,
            parentToolInvocationId: $input->parent?->toolInvocationId,
            step: $input->step,
            toolInvocationId: $input->toolInvocationId,
            subject: $input->subject,
            usage: $input->usage?->toContract(),
            model: $input->model?->toContract(),
        );
    }

    private function operation(SingleOperationInput $input, string $source): RecordV1
    {
        return new RecordV1(
            recordId: UuidV7::generate(),
            source: $source,
            type: RecordType::RunEnd,
            operation: $input->operation,
            at: Timestamp::fromDateTime($input->at),
            capture: $input->capture,
            sampled: $input->sampled,
            invocationId: $input->invocationId,
            attempt: $input->attempt,
            parentInvocationId: $input->parent?->invocationId,
            parentToolInvocationId: $input->parent?->toolInvocationId,
            subject: $input->subject,
            usage: $input->usage?->toContract(),
            model: $input->model?->toContract(),
        );
    }
}
