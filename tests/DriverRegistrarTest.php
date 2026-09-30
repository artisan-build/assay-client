<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Internal\DriverRegistrar;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Tests\Support\CollectingDispatcher;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;

it('does not register a driver whose source is absent', function (): void {
    $driver = new class implements CaptureDriver
    {
        public bool $registered = false;

        public function name(): string
        {
            return 'absent';
        }

        public function source(): ?SourceInfo
        {
            return null;
        }

        public function register(Recorder $recorder): void
        {
            $this->registered = true;
        }
    };
    $drops = new InMemoryDropCounter;
    app()->instance(CaptureDriver::class, $driver);

    (new DriverRegistrar(app(), $drops, new CollectingDispatcher))->register();

    expect($driver->registered)->toBeFalse()
        ->and($drops->transportTotal())->toBe(0);
});

it('contains driver registration failures and counts the transport drop', function (): void {
    $driver = new class implements CaptureDriver
    {
        public function name(): string
        {
            return 'throwing';
        }

        public function source(): ?SourceInfo
        {
            return new SourceInfo('vendor/source', '1.0.0');
        }

        public function register(Recorder $recorder): void
        {
            throw new RuntimeException('source failure');
        }
    };
    $drops = new InMemoryDropCounter;
    app()->instance(CaptureDriver::class, $driver);

    (new DriverRegistrar(app(), $drops, new CollectingDispatcher))->register();

    expect($drops->transportTotal())->toBe(1);
});
