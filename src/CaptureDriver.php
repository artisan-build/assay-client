<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

interface CaptureDriver
{
    public function name(): string;

    public function source(): ?SourceInfo;

    public function register(Recorder $recorder): void;
}
