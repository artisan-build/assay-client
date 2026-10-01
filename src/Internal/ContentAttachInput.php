<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\UuidV7;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ContentAttachInput
{
    public function __construct(
        public UuidV7 $targetRecordId,
        public string $invocationId,
        public DateTimeImmutable $at,
        public Content $content,
    ) {
        if ($this->invocationId === '') {
            throw new InvalidArgumentException('Content attach invocation id must be non-empty.');
        }
    }
}
