<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Records;

use ArtisanBuild\AssayClient\Internal\InputValidation;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class ToolCallInput implements RecordInput
{
    public DateTimeImmutable $at;

    public function __construct(
        public RecordType $type,
        public string $invocationId,
        public int $attempt,
        public string $toolInvocationId,
        DateTimeInterface $at,
        public ?int $step = null,
        public CaptureMode $capture = CaptureMode::Usage,
        public bool $sampled = false,
        public ?ParentLink $parent = null,
        public ?string $subject = null,
        public ?ModelInfo $model = null,
        public ?string $agent = null,
        public ?string $tool = null,
        public ?float $durationMs = null,
        public ?Outcome $outcome = null,
        public ?Approval $approval = null,
        public ?string $failureClass = null,
        public ?Content $content = null,
    ) {
        if (! in_array($this->type, [RecordType::ToolStart, RecordType::ToolEnd, RecordType::ToolApproval], true)) {
            throw new InvalidArgumentException('Tool input type must be tool.start, tool.end, or tool.approval.');
        }

        InputValidation::required($this->invocationId, 'Invocation id');
        InputValidation::required($this->toolInvocationId, 'Tool invocation id');
        InputValidation::attempt($this->attempt);

        if ($this->step !== null) {
            InputValidation::step($this->step);
        }

        if ($this->agent !== null) {
            InputValidation::metadataString($this->agent, 'Agent');
        }

        if ($this->tool !== null) {
            InputValidation::metadataString($this->tool, 'Tool');
        }

        if ($this->durationMs !== null) {
            InputValidation::duration($this->durationMs);

            if ($this->type !== RecordType::ToolEnd) {
                throw new InvalidArgumentException('Duration is allowed only on tool.end.');
            }
        }

        if (($this->type === RecordType::ToolEnd) !== ($this->outcome !== null)) {
            throw new InvalidArgumentException('Outcome is required on tool.end and forbidden on other tool records.');
        }

        if (($this->type === RecordType::ToolApproval) !== ($this->approval !== null)) {
            throw new InvalidArgumentException('Approval is required on tool.approval and forbidden on other tool records.');
        }

        if ($this->failureClass !== null) {
            InputValidation::failureClass($this->failureClass);

            if ($this->type !== RecordType::ToolEnd || $this->outcome !== Outcome::Failed) {
                throw new InvalidArgumentException('Failure class requires a failed tool.end.');
            }
        }

        $this->at = InputValidation::time($at);

        if ($this->content !== null && $this->capture !== CaptureMode::Full) {
            throw new InvalidArgumentException('Content requires full capture.');
        }
    }
}
