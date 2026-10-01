<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Tests;

use ArtisanBuild\AssayClient\AssayClientServiceProvider;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadFilter;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AssayClientServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app->bind(PayloadFilter::class, static fn (): PayloadFilter => new class implements PayloadFilter
        {
            public function filter(OutboundPayload $payload): OutboundPayload
            {
                return $payload;
            }
        });
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'database');
        $app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'connection' => 'pgsql',
            'table' => 'cache',
            'lock_connection' => 'pgsql',
            'lock_table' => 'cache_locks',
        ]);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '5432'),
            'database' => (string) env('DB_DATABASE', 'assay_app_test'),
            'username' => (string) env('DB_USERNAME', 'root'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'pgsql',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'pgsql',
            'table' => 'failed_jobs',
        ]);
    }
}
