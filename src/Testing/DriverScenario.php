<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Testing;

use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\SourceInfo;
use Closure;
use InvalidArgumentException;

final readonly class DriverScenario
{
    /** @var list<RecordInput> */
    public array $expectedRecords;

    private Closure $exercise;

    /**
     * @param  callable(string): void  $exercise
     * @param  list<RecordInput>  $expectedRecords
     */
    public function __construct(
        public string $driverName,
        public SourceInfo $source,
        callable $exercise,
        array $expectedRecords,
        public string $canary,
        public bool $supportsFailover = false,
    ) {
        if ($this->driverName === '' || $this->canary === '' || $expectedRecords === []) {
            throw new InvalidArgumentException('A conformance scenario requires a driver name, canary, and expected records.');
        }

        $this->exercise = Closure::fromCallable($exercise);
        $this->expectedRecords = $expectedRecords;
    }

    public function exercise(): void
    {
        ($this->exercise)($this->canary);
    }
}
