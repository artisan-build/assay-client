<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

interface Sampler
{
    public function sample(float $rate): bool;
}
