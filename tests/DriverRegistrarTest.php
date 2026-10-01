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

it('wires retained lifecycle bounds into the buffered recorder', function (): void {
    $driver = new class implements CaptureDriver
    {
        public ?Recorder $recorder = null;

        public function name(): string
        {
            return 'configured';
        }

        public function source(): ?SourceInfo
        {
            return new SourceInfo('vendor/source', '1.0.0');
        }

        public function register(Recorder $recorder): void
        {
            $this->recorder = $recorder;
        }
    };
    config()->set('assay.max_retained_roots', 12);
    config()->set('assay.max_retained_buffer_bytes', 3456);
    config()->set('assay.retained_state_ttl_seconds', 78);
    app()->instance(CaptureDriver::class, $driver);

    (new DriverRegistrar(app(), new InMemoryDropCounter, new CollectingDispatcher))->register();

    expect($driver->recorder)->not->toBeNull();
    assert($driver->recorder instanceof Recorder);
    expect((new ReflectionProperty($driver->recorder, 'maxRetainedRoots'))->getValue($driver->recorder))->toBe(12)
        ->and((new ReflectionProperty($driver->recorder, 'maxRetainedBufferBytes'))->getValue($driver->recorder))->toBe(3456)
        ->and((new ReflectionProperty($driver->recorder, 'retainedStateTtlSeconds'))->getValue($driver->recorder))->toBe(78);
});
