<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayContracts\Client;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final readonly class DriverRegistrar
{
    public function __construct(
        private Application $app,
        private DropCounter $drops,
        private EnvelopeDispatcher $dispatcher,
    ) {}

    public function register(): void
    {
        try {
            $driver = $this->app->make(CaptureDriver::class);
            $source = $driver->source();

            if ($source === null) {
                return;
            }

            $deploy = config('assay.deploy');

            $driver->register(new BufferedRecorder(
                driver: $driver->name(),
                source: $source,
                client: new Client(
                    package: 'artisan-build/assay-client',
                    version: InstalledVersions::getPrettyVersion('artisan-build/assay-client') ?? 'dev',
                ),
                environment: (string) config('assay.environment'),
                deploy: is_string($deploy) ? $deploy : null,
                batchSize: (int) config('assay.batch_size'),
                maxBatchBytes: (int) config('assay.max_batch_bytes'),
                retryForSeconds: (int) config('assay.retry_for_seconds'),
                drops: $this->drops,
                dispatcher: $this->dispatcher,
                app: $this->app,
            ));
        } catch (Throwable) {
            try {
                $this->drops->incrementTransport();
            } catch (Throwable) {
                // Telemetry cannot escape into the host application.
            }
        }
    }
}
