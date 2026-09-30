<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\Operation;
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

    public static function metadataString(string $value, string $field): void
    {
        $length = preg_match_all('/./us', $value);

        if ($length === false || $length < 1 || $length > 255 || preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidArgumentException("{$field} must contain 1 to 255 characters and no control characters.");
        }
    }

    public static function duration(float $durationMs): void
    {
        if (! is_finite($durationMs) || $durationMs < 0) {
            throw new InvalidArgumentException('Duration must be finite and non-negative.');
        }
    }

    public static function failureClass(string $failureClass): void
    {
        self::metadataString($failureClass, 'Failure class');

        if (preg_match('~^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$~D', $failureClass) !== 1) {
            throw new InvalidArgumentException('Failure class must be a PHP class name.');
        }
    }

    public static function usage(Usage $usage, Operation $operation): void
    {
        try {
            $usage->toContract()->validateFor($operation);
        } catch (InvalidEnvelope $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }
    }

    public static function time(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at);
    }
}
