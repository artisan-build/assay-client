<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Records;

use ArtisanBuild\AssayClient\Internal\InputValidation;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\CaptureMode;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class AttemptInput implements RecordInput
{
    public DateTimeImmutable $at;

    public function __construct(
        public ?string $invocationId,
        public ?int $attempt,
        DateTimeInterface $at,
        public CaptureMode $capture = CaptureMode::Usage,
        public bool $sampled = false,
        public ?ParentLink $parent = null,
        public ?string $subject = null,
        public ?Usage $usage = null,
        public ?ModelInfo $model = null,
        public ?string $agent = null,
        public ?string $failureClass = null,
    ) {
        if ($this->invocationId === null) {
            if ($this->attempt !== null || $this->parent !== null || $this->agent !== null) {
                throw new \InvalidArgumentException('Unattributed failover must omit attempt, parent, and agent metadata.');
            }

            if ($this->model?->provider === null || $this->model->requested === null) {
                throw new \InvalidArgumentException('Unattributed failover requires model provider and requested.');
            }
        } else {
            InputValidation::required($this->invocationId, 'Invocation id');

            if ($this->attempt === null) {
                throw new \InvalidArgumentException('Attributed failover requires an attempt.');
            }

            InputValidation::attempt($this->attempt);
        }

        if ($this->agent !== null) {
            InputValidation::metadataString($this->agent, 'Agent');
        }

        if ($this->failureClass !== null) {
            InputValidation::failureClass($this->failureClass);
        }

        $this->at = InputValidation::time($at);
    }
}
