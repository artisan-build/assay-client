<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

use InvalidArgumentException;

final readonly class ParentLink
{
    public function __construct(
        public ?string $invocationId = null,
        public ?string $toolInvocationId = null,
    ) {
        if (($this->invocationId === null && $this->toolInvocationId === null)
            || $this->invocationId === '' || $this->toolInvocationId === '') {
            throw new InvalidArgumentException('A parent link must contain at least one non-empty identifier.');
        }
    }
}
