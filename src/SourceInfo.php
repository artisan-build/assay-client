<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

use InvalidArgumentException;

final readonly class SourceInfo
{
    public function __construct(
        public string $package,
        public string $version,
    ) {
        if ($this->package === '' || $this->version === '') {
            throw new InvalidArgumentException('Source package and version must be non-empty.');
        }
    }
}
