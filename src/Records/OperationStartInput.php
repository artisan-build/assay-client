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
use InvalidArgumentException;

final readonly class OperationStartInput implements RecordInput
{
    public DateTimeImmutable $at;

    public function __construct(
        public Operation $operation,
        public string $invocationId,
        DateTimeInterface $at,
        public ?ParentLink $parent,
        public CaptureMode $capture,
        public bool $sampled,
        public ?string $subject,
        public ModelInfo $model,
    ) {
        if ($this->operation === Operation::Agent) {
            throw new InvalidArgumentException('Operation start input cannot use the agent operation.');
        }

        InputValidation::required($this->invocationId, 'Invocation id');

        if ($this->model->requested === null || $this->model->provider === null || $this->model->responded !== null) {
            throw new InvalidArgumentException('Operation start input requires requested model and provider only.');
        }

        $this->at = InputValidation::time($at);
    }
}
