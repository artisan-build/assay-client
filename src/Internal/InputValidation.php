<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class InputValidation
{
    public static function required(string $value, string $field): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("{$field} must be non-empty.");
        }
    }

    public static function attempt(int $attempt): void
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Attempt must be positive.');
        }
    }

    public static function step(int $step): void
    {
        if ($step < 0) {
            throw new InvalidArgumentException('Step must be non-negative.');
        }
    }

    public static function time(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at);
    }
}
