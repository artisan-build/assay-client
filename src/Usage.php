<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

use ArtisanBuild\AssayContracts\Usage as ContractUsage;
use InvalidArgumentException;

final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $cacheReadInputTokens = null,
        public ?int $cacheWriteInputTokens = null,
        public ?int $reasoningTokens = null,
        public ?int $imageInputTokens = null,
        public ?int $imageOutputTokens = null,
        public int|float|null $audioSeconds = null,
        public int|float|null $searchUnits = null,
    ) {
        $metrics = $this->toArray();

        if ($metrics === []) {
            throw new InvalidArgumentException('Usage must contain at least one reported metric.');
        }

        foreach ($metrics as $name => $value) {
            if ($value < 0 || (is_float($value) && ! is_finite($value))) {
                throw new InvalidArgumentException("Usage metric {$name} must be finite and non-negative.");
            }
        }
    }

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return array_filter([
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'image_input_tokens' => $this->imageInputTokens,
            'image_output_tokens' => $this->imageOutputTokens,
            'audio_seconds' => $this->audioSeconds,
            'search_units' => $this->searchUnits,
        ], static fn (int|float|null $value): bool => $value !== null);
    }

    public function toContract(): ContractUsage
    {
        return new ContractUsage(
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            cacheReadInputTokens: $this->cacheReadInputTokens,
            cacheWriteInputTokens: $this->cacheWriteInputTokens,
            reasoningTokens: $this->reasoningTokens,
            imageInputTokens: $this->imageInputTokens,
            imageOutputTokens: $this->imageOutputTokens,
            audioSeconds: $this->audioSeconds,
            searchUnits: $this->searchUnits,
        );
    }
}
