<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Records;

use ArtisanBuild\AssayClient\Internal\InputValidation;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\CaptureMode;
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
        public ?Usage $usage = null,
        public ?ModelInfo $model = null,
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

        $this->at = InputValidation::time($at);
    }
}
