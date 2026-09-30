<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Contracts\Transport;
use ArtisanBuild\AssayClient\Internal\CacheDropCounter;
use ArtisanBuild\AssayClient\Internal\DriverRegistrar;
use ArtisanBuild\AssayClient\Internal\NullCaptureDriver;
use ArtisanBuild\AssayClient\Internal\QueueEnvelopeDispatcher;
use ArtisanBuild\AssayClient\Jobs\ShipEnvelope;
use ArtisanBuild\AssayClient\Transport\HttpTransport;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class AssayClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/assay.php', 'assay');

        $this->app->bindIf(CaptureDriver::class, NullCaptureDriver::class);
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
}
