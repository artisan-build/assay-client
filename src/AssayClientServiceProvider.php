<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Contracts\Transport;
use ArtisanBuild\AssayClient\Internal\CacheDropCounter;
use ArtisanBuild\AssayClient\Internal\DriverRegistrar;
use ArtisanBuild\AssayClient\Internal\QueueEnvelopeDispatcher;
use ArtisanBuild\AssayClient\Internal\RandomSampler;
use ArtisanBuild\AssayClient\Jobs\ShipEnvelope;
use ArtisanBuild\AssayClient\Transport\HttpTransport;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Throwable;

final class AssayClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/assay.php', 'assay');

        $this->app->bindIf(CaptureDriver::class, LaravelAiDriver::class);
        $this->app->bindIf(Sampler::class, RandomSampler::class);
        $this->app->bind(Transport::class, HttpTransport::class);
        $this->app->bind(EnvelopeDispatcher::class, QueueEnvelopeDispatcher::class);
        $this->app->singleton(fn (Application $app): DropCounter => new CacheDropCounter(
            cache: $app->make(Repository::class),
            application: (string) config('assay.app'),
            environment: (string) config('assay.environment'),
        ));
    }

    public function boot(): void
    {
        $this->validateConfiguration();

        $this->publishes([
            __DIR__.'/../config/assay.php' => config_path('assay.php'),
        ], 'assay-config');

        try {
            $this->app->make(Dispatcher::class)->listen(JobProcessing::class, function (JobProcessing $event): void {
                try {
                    $retryUntil = $event->job->retryUntil();

                    if ($event->job->resolveQueuedJobClass() !== ShipEnvelope::class
                        || ! is_int($retryUntil)
                        || time() <= $retryUntil) {
                        return;
                    }

                    $event->job->delete();
                    $this->app->make(DropCounter::class)->incrementTransport();
                } catch (Throwable) {
                    // Telemetry lifecycle failures cannot fail the host queue worker.
                }
            });
        } catch (Throwable) {
            // Telemetry listener registration cannot prevent the host application from booting.
        }

        $this->app->booted(function (): void {
            try {
                $this->app->make(DriverRegistrar::class)->register();
            } catch (Throwable) {
                try {
                    $this->app->make(DropCounter::class)->incrementTransport();
                } catch (Throwable) {
                    // Telemetry cannot prevent the host application from booting.
                }
            }
        });
    }

    private function validateConfiguration(): void
    {
        $batchSize = (int) config('assay.batch_size');
        $sampleRate = config('assay.sample_rate');
        $agentSampleRates = config('assay.agent_sample_rates');

        if ($batchSize > 500) {
            throw new InvalidArgumentException('Assay batch_size must not exceed 500.');
        }

        if ($batchSize < 1
            || (int) config('assay.retry_for_seconds') < 1
            || (int) config('assay.max_batch_bytes') < 1
            || (int) config('assay.failure_buffer_bytes') < 1
            || (int) config('assay.max_retained_roots') < 1
            || (int) config('assay.max_retained_buffer_bytes') < 1
            || (int) config('assay.retained_state_ttl_seconds') < 1) {
            throw new InvalidArgumentException('Assay batch size, retry bound, buffer bounds, and retained lifecycle bounds must be positive.');
        }

        if (! is_numeric($sampleRate) || ! is_finite((float) $sampleRate) || (float) $sampleRate < 0.0 || (float) $sampleRate > 1.0) {
            throw new InvalidArgumentException('Assay sample_rate must be a finite number between 0 and 1.');
        }

        if (! is_array($agentSampleRates)) {
            throw new InvalidArgumentException('Assay agent_sample_rates must be an array of agent class names to rates.');
        }

        foreach ($agentSampleRates as $agent => $rate) {
            if (! is_string($agent) || $agent === '' || ! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate < 0.0 || (float) $rate > 1.0) {
                throw new InvalidArgumentException('Every Assay agent sample rate must map a non-empty class name to a finite number between 0 and 1.');
            }
        }

        if (! is_bool(config('assay.always_on_failure'))) {
            throw new InvalidArgumentException('Assay always_on_failure must be boolean.');
        }

        if (! is_string(config('assay.subject_context_key')) || config('assay.subject_context_key') === '') {
            throw new InvalidArgumentException('Assay subject_context_key must be a non-empty string.');
        }
    }
}
