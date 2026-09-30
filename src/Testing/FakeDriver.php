<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Testing;

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\SourceInfo;
use Closure;
use LogicException;

final class FakeDriver implements CaptureDriver
{
    /** @var Closure(string): list<RecordInput> */
    private readonly Closure $project;

    private ?Recorder $recorder = null;

    /** @param callable(string): list<RecordInput> $project */
    public function __construct(
        private readonly string $driverName,
        private readonly SourceInfo $sourceInfo,
        callable $project,
    ) {
        $this->project = Closure::fromCallable($project);
    }

    public function name(): string
    {
        return $this->driverName;
    }

    public function source(): SourceInfo
    {
        return $this->sourceInfo;
    }

    public function register(Recorder $recorder): void
    {
        $this->recorder = $recorder;
    }

    public function capture(string $sourceValue): void
    {
        $recorder = $this->recorder;

        if ($recorder === null) {
            throw new LogicException('The fake driver must be registered before capture.');
        }

        foreach (($this->project)($sourceValue) as $record) {
            $recorder->record($record);
        }

        $recorder->flush();
    }
}
