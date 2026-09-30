<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\AssayClientServiceProvider;

it('loads the client service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AssayClientServiceProvider::class, true);
});
