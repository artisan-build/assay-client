<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Sampler;
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
                sampler: $this->app->make(Sampler::class),
                sampleRate: (float) config('assay.sample_rate'),
                agentSampleRates: (array) config('assay.agent_sample_rates'),
                alwaysOnFailure: (bool) config('assay.always_on_failure'),
                failureBufferBytes: (int) config('assay.failure_buffer_bytes'),
                subjectContextKey: (string) config('assay.subject_context_key'),
                maxRetainedRoots: (int) config('assay.max_retained_roots'),
                maxRetainedBufferBytes: (int) config('assay.max_retained_buffer_bytes'),
                retainedStateTtlSeconds: (int) config('assay.retained_state_ttl_seconds'),
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
