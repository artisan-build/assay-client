<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Records;

use ArtisanBuild\AssayClient\Internal\InputValidation;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Operation;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class AttemptInput implements RecordInput
{
    public DateTimeImmutable $at;

    public function __construct(
        public ?string $invocationId,
        public ?int $attempt,
        DateTimeInterface $at,
        public ?Operation $operation = null,
        public CaptureMode $capture = CaptureMode::Usage,
        public bool $sampled = false,
        public ?ParentLink $parent = null,
        public ?string $subject = null,
        public ?ModelInfo $model = null,
        public ?string $agent = null,
        public ?string $failureClass = null,
    ) {
        if ($this->invocationId === null) {
            if ($this->operation !== null || $this->attempt !== null || $this->parent !== null || $this->agent !== null) {
                throw new \InvalidArgumentException('Unattributed failover must omit operation, attempt, parent, and agent metadata.');
            }

            if ($this->model?->provider === null || $this->model->requested === null) {
                throw new \InvalidArgumentException('Unattributed failover requires model provider and requested.');
            }
        } else {
            InputValidation::required($this->invocationId, 'Invocation id');

            if ($this->operation === null) {
                throw new \InvalidArgumentException('Attributed failover requires an operation.');
            }

            if (($this->operation === Operation::Agent) !== ($this->attempt !== null)) {
                throw new \InvalidArgumentException('Failover attempt is required exactly for the agent operation.');
            }

            if ($this->attempt !== null) {
                InputValidation::attempt($this->attempt);
            }
        }

        $agent = $this->agent;

        if ($agent !== null) {
            if ($this->operation !== Operation::Agent) {
                throw new \InvalidArgumentException('Agent metadata is allowed only for the agent operation.');
            }

            InputValidation::metadataString($agent, 'Agent');
        }

        if ($this->failureClass !== null) {
            InputValidation::failureClass($this->failureClass);
        }

        $this->at = InputValidation::time($at);
    }
}
