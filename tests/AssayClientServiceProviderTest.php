<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\AssayClientServiceProvider;
use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Internal\NullCaptureDriver;

it('loads the client service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AssayClientServiceProvider::class, true)
        ->and(resolve(CaptureDriver::class))->toBeInstanceOf(NullCaptureDriver::class);
});
