<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Records;

use ArtisanBuild\AssayClient\Internal\InputValidation;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\RecordType;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class StepInput implements RecordInput
{
    public DateTimeImmutable $at;

    public function __construct(
        public RecordType $type,
        public string $invocationId,
        public int $attempt,
        public int $step,
        DateTimeInterface $at,
        public CaptureMode $capture = CaptureMode::Usage,
        public bool $sampled = false,
        public ?ParentLink $parent = null,
        public ?string $subject = null,
        public ?Usage $usage = null,
        public ?ModelInfo $model = null,
        public ?string $agent = null,
        public ?float $durationMs = null,
        public ?FinishReason $finishReason = null,
        public ?string $failureClass = null,
    ) {
        if (! in_array($this->type, [RecordType::StepStart, RecordType::StepEnd, RecordType::StepFail], true)) {
            throw new InvalidArgumentException('Step input type must be step.start, step.end, or step.fail.');
        }

        InputValidation::required($this->invocationId, 'Invocation id');
        InputValidation::attempt($this->attempt);
        InputValidation::step($this->step);

        if ($this->agent !== null) {
            InputValidation::metadataString($this->agent, 'Agent');
        }

        if ($this->durationMs !== null) {
            InputValidation::duration($this->durationMs);

            if ($this->type === RecordType::StepStart) {
                throw new InvalidArgumentException('Duration is not allowed on step.start.');
            }
        }

        if ($this->finishReason !== null && $this->type !== RecordType::StepEnd) {
            throw new InvalidArgumentException('Finish reason is allowed only on step.end.');
        }

        if ($this->failureClass !== null) {
            InputValidation::failureClass($this->failureClass);

            if ($this->type !== RecordType::StepFail) {
                throw new InvalidArgumentException('Failure class is allowed only on step.fail.');
            }
        }

        $this->at = InputValidation::time($at);
    }
}
