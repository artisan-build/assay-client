<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\AssayClientServiceProvider;
use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\LaravelAiDriver;

it('loads the client service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AssayClientServiceProvider::class, true)
        ->and(resolve(CaptureDriver::class))->toBeInstanceOf(LaravelAiDriver::class)
        ->and(config('assay.batch_size'))->toBe(100)
        ->and(config('assay.max_batch_bytes'))->toBe(4_194_304);
});

it('accepts 500 records per batch during boot', function (): void {
    config()->set('assay.batch_size', 500);

    (new AssayClientServiceProvider(app()))->boot();

    expect(config('assay.batch_size'))->toBe(500);
});

it('refuses more than 500 records per batch during boot', function (): void {
    config()->set('assay.batch_size', 501);

    expect(fn () => (new AssayClientServiceProvider(app()))->boot())
        ->toThrow(InvalidArgumentException::class, 'Assay batch_size must not exceed 500.');
});

it('reads the maximum batch bytes from its environment setting', function (): void {
    putenv('ASSAY_MAX_BATCH_BYTES=123456');

    try {
        $configuration = require __DIR__.'/../config/assay.php';
    } finally {
        putenv('ASSAY_MAX_BATCH_BYTES');
    }

    expect($configuration['max_batch_bytes'])->toBe(123456);
});

it('refuses non-positive client bounds during boot', function (string $key): void {
    config()->set("assay.{$key}", 0);

    expect(fn () => (new AssayClientServiceProvider(app()))->boot())
        ->toThrow(InvalidArgumentException::class, 'Assay batch size, retry bound, and maximum batch bytes must be positive.');
})->with([
    'batch size' => 'batch_size',
    'retry bound' => 'retry_for_seconds',
    'maximum batch bytes' => 'max_batch_bytes',
]);
