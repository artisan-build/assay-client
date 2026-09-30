<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Records;

use ArtisanBuild\AssayClient\Internal\InputValidation;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Operation;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class SingleOperationInput implements RecordInput
{
    public DateTimeImmutable $at;

    public function __construct(
        public Operation $operation,
        public string $invocationId,
        DateTimeInterface $at,
        public ?int $attempt = null,
        public CaptureMode $capture = CaptureMode::Usage,
        public bool $sampled = false,
        public ?ParentLink $parent = null,
        public ?string $subject = null,
        public ?Usage $usage = null,
        public ?ModelInfo $model = null,
    ) {
        if ($this->operation === Operation::Agent) {
            throw new InvalidArgumentException('Single-operation input cannot use the agent operation.');
        }

        InputValidation::required($this->invocationId, 'Invocation id');

        if ($this->attempt !== null) {
            InputValidation::attempt($this->attempt);
        }

        $this->at = InputValidation::time($at);
    }
}
