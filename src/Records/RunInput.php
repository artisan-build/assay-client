<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Records;

use ArtisanBuild\AssayClient\Internal\InputValidation;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\FailureCapture;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\ReplayInputOmission;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class RunInput implements RecordInput
{
    public DateTimeImmutable $at;

    /** @var list<ReplayInputOmission>|null */
    public ?array $replayInputsOmitted;

    /** @param array<array-key, mixed>|null $replayInputsOmitted */
    public function __construct(
        public RecordType $type,
        public string $invocationId,
        public int $attempt,
        DateTimeInterface $at,
        public CaptureMode $capture = CaptureMode::Usage,
        public bool $sampled = false,
        public ?ParentLink $parent = null,
        public ?string $subject = null,
        public ?Usage $usage = null,
        public ?ModelInfo $model = null,
        public ?string $agent = null,
        public ?FinishReason $finishReason = null,
        public ?Outcome $outcome = null,
        public ?string $failureClass = null,
        public ?FailureCapture $failureCapture = null,
        ?array $replayInputsOmitted = null,
        public ?Content $content = null,
    ) {
        if (! in_array($this->type, [RecordType::RunStart, RecordType::RunEnd], true)) {
            throw new InvalidArgumentException('Run input type must be run.start or run.end.');
        }

        InputValidation::required($this->invocationId, 'Invocation id');
        InputValidation::attempt($this->attempt);

        if ($this->usage !== null) {
            if ($this->type !== RecordType::RunEnd) {
                throw new InvalidArgumentException('Usage is allowed only on run.end.');
            }

            InputValidation::usage($this->usage, Operation::Agent);
        }

        if ($this->agent !== null) {
            InputValidation::metadataString($this->agent, 'Agent');
        }

        if (($this->type === RecordType::RunEnd) !== ($this->outcome !== null)) {
            throw new InvalidArgumentException('Outcome is required on run.end and forbidden on run.start.');
        }

        if ($this->finishReason !== null && $this->type !== RecordType::RunEnd) {
            throw new InvalidArgumentException('Finish reason is allowed only on run.end.');
        }

        if ($this->failureClass !== null) {
            InputValidation::failureClass($this->failureClass);

            if ($this->type !== RecordType::RunEnd || $this->outcome !== Outcome::Failed) {
                throw new InvalidArgumentException('Failure class requires a failed run.end.');
            }
        }

        if ($this->failureCapture !== null
            && ($this->type !== RecordType::RunEnd
                || $this->outcome !== Outcome::Failed
                || $this->sampled !== false)) {
            throw new InvalidArgumentException('Failure capture requires an unsampled failed run.end.');
        }

        if ($replayInputsOmitted !== null) {
            if ($this->type !== RecordType::RunEnd || $replayInputsOmitted === [] || ! array_is_list($replayInputsOmitted)) {
                throw new InvalidArgumentException('Replay inputs omitted must be a non-empty list on run.end.');
            }

            foreach ($replayInputsOmitted as $omission) {
                if (! $omission instanceof ReplayInputOmission) {
                    throw new InvalidArgumentException('Every replay input omission must be a ReplayInputOmission.');
                }
            }
        }

        $this->at = InputValidation::time($at);
        $this->replayInputsOmitted = $replayInputsOmitted;

        if ($this->content !== null && $this->capture !== CaptureMode::Full) {
            throw new InvalidArgumentException('Content requires full capture.');
        }
    }
}
