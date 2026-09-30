<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\SourceInfo;

final class NullCaptureDriver implements CaptureDriver
{
    public function name(): string
    {
        return 'none';
    }

    public function source(): ?SourceInfo
    {
        return null;
    }

    public function register(Recorder $recorder): void
    {
        // An absent source intentionally registers no capture hooks.
    }
}
