<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\CacheDropCounter;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('cache');
    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration')->index();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('cache');
});

it('persists monotonic application and environment scoped drop totals', function (): void {
    $repository = new Repository(new DatabaseStore(DB::connection('pgsql'), 'cache'));

    $first = new CacheDropCounter($repository, 'application-a', 'production');

    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::table('cache')->count())->toBe(0)
        ->and($first->transportTotal())->toBe(0)
        ->and($first->incrementTransport())->toBe(1)
        ->and($first->incrementTransport())->toBe(2)
        ->and($first->incrementHook())->toBe(1)
        ->and(DB::table('cache')->count())->toBe(2);

    $freshRepository = new Repository(new DatabaseStore(DB::connection('pgsql'), 'cache'));
    $fresh = new CacheDropCounter($freshRepository, 'application-a', 'production');

    expect($fresh->transportTotal())->toBe(2)
        ->and($fresh->hookTotal())->toBe(1)
        ->and($fresh->transportTotal())->toBe(2)
        ->and((new CacheDropCounter($freshRepository, 'application-b', 'production'))->transportTotal())->toBe(0)
        ->and((new CacheDropCounter($freshRepository, 'application-a', 'staging'))->transportTotal())->toBe(0);
});
