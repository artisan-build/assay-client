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

final readonly class RunInput implements RecordInput
{
    public DateTimeImmutable $at;

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
    ) {
        if (! in_array($this->type, [RecordType::RunStart, RecordType::RunEnd], true)) {
            throw new InvalidArgumentException('Run input type must be run.start or run.end.');
        }

        InputValidation::required($this->invocationId, 'Invocation id');
        InputValidation::attempt($this->attempt);
        $this->at = InputValidation::time($at);
    }
}
