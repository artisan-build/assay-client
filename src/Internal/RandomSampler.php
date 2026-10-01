<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\Sampler;

final class RandomSampler implements Sampler
{
    public function sample(float $rate): bool
    {
        if ($rate <= 0.0) {
            return false;
        }

        if ($rate >= 1.0) {
            return true;
        }

        return random_int(0, PHP_INT_MAX - 1) < $rate * PHP_INT_MAX;
    }
}
