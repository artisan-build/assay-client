<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

use ArtisanBuild\AssayContracts\Model;
use InvalidArgumentException;

final readonly class ModelInfo
{
    public function __construct(
        public ?string $requested = null,
        public ?string $responded = null,
        public ?string $provider = null,
    ) {
        $values = array_filter([$this->requested, $this->responded, $this->provider], static fn (?string $value): bool => $value !== null);

        if ($values === [] || in_array('', $values, true)) {
            throw new InvalidArgumentException('Model information must contain non-empty reported values.');
        }
    }

    public function toContract(): Model
    {
        return new Model($this->requested, $this->responded, $this->provider);
    }
}
