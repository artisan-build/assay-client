<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Contracts\Transport;
use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\Jobs\ShipEnvelope;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\Operation;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('failed_jobs');
    Schema::dropIfExists('jobs');
    Schema::dropIfExists('cache');

    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration')->index();
    });

    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('failed_jobs');
    Schema::dropIfExists('jobs');
    Schema::dropIfExists('cache');
});

it('defaults the bounded retry deadline to twenty four hours', function (): void {
    $now = time();
    $job = new ShipEnvelope('{}');

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThanOrEqual($now + 86400)
        ->toBeLessThanOrEqual(time() + 86400);
});

it('uses Laravel queue encryption and serializes primitive job state only', function (): void {
    $recorder = new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: null,
        batchSize: 1,
        retryForSeconds: 86400,
        drops: new InMemoryDropCounter,
        dispatcher: resolve(EnvelopeDispatcher::class),
    );
    $recorder->record(new SingleOperationInput(
        operation: Operation::Transcription,
        invocationId: 'operation-1',
        at: new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00'),
        usage: new Usage(audioSeconds: 1.25),
    ));

    $payload = (string) DB::table('jobs')->value('payload');
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $command = $decoded['data']['command'];

    expect($payload)->not->toContain('"audio_seconds":1.25')
        ->and($command)->not->toContain('ShipEnvelope');

    $serialized = resolve(Encrypter::class)->decrypt($command);
    $job = unserialize($serialized, ['allowed_classes' => [ShipEnvelope::class]]);

    expect($job)->toBeInstanceOf(ShipEnvelope::class)
        ->and($serialized)->toContain('"audio_seconds":1.25');

    $envelope = EnvelopeCodec::decode($job->envelopeJson);

    expect($envelope->records[0]->usage?->toArray())->toBe(['audio_seconds' => 1.25]);

    $reflection = new ReflectionObject($job);

    foreach ($reflection->getProperties() as $property) {
        $value = $property->getValue($job);
        expect(is_scalar($value) || $value === null)->toBeTrue("{$property->getName()} is primitive");
    }
});

it('releases inside the bound then terminally discards without a failed job row', function (): void {
    $drops = new InMemoryDropCounter;
    app()->instance(DropCounter::class, $drops);
    app()->bind(Transport::class, static fn (): Transport => new class implements Transport
    {
        public function send(string $envelopeJson): void
        {
            throw new RuntimeException('transport failed');
        }
    });

    resolve(Queue::class)->push(new ShipEnvelope('{"retry":true}', time() + 3600, 0), queue: 'retry');
    $this->artisan('queue:work', [
        'connection' => 'database',
        '--queue' => 'retry',
        '--once' => true,
        '--sleep' => 0,
        '--tries' => 3,
    ])->assertExitCode(0);

    expect(DB::table('jobs')->where('queue', 'retry')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($drops->transportTotal())->toBe(0);

    resolve(Queue::class)->push(new ShipEnvelope('{"terminal":true}', time() + 1, 60), queue: 'terminal');
    $this->artisan('queue:work', [
        'connection' => 'database',
        '--queue' => 'terminal',
        '--once' => true,
        '--sleep' => 0,
        '--tries' => 3,
    ])->assertExitCode(0);

    expect(DB::table('jobs')->where('queue', 'terminal')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($drops->transportTotal())->toBe(1);
});

it('discards an already overdue encrypted job before Laravel can fail it', function (): void {
    $drops = new InMemoryDropCounter;
    $transport = new class implements Transport
    {
        public int $calls = 0;

        public function send(string $envelopeJson): void
        {
            $this->calls++;
        }
    };
    app()->instance(DropCounter::class, $drops);
    app()->instance(Transport::class, $transport);

    resolve(Queue::class)->push(new ShipEnvelope('{"overdue":true}', time() - 1), queue: 'overdue');

    expect((string) DB::table('jobs')->where('queue', 'overdue')->value('payload'))
        ->not->toContain('{"overdue":true}');

    $this->artisan('queue:work', [
        'connection' => 'database',
        '--queue' => 'overdue',
        '--once' => true,
        '--sleep' => 0,
        '--tries' => 3,
    ])->assertExitCode(0);

    expect(DB::table('jobs')->where('queue', 'overdue')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($transport->calls)->toBe(0)
        ->and($drops->transportTotal())->toBe(1);
});

it('contains drop counter failure while discarding an overdue job', function (): void {
    app()->instance(DropCounter::class, new class implements DropCounter
    {
        public function transportTotal(): int
        {
            return 0;
        }

        public function hookTotal(): int
        {
            return 0;
        }

        public function incrementTransport(): int
        {
            throw new RuntimeException('cache unavailable');
        }

        public function incrementHook(): int
        {
            return 0;
        }
    });

    resolve(Queue::class)->push(new ShipEnvelope('{"overdue":true}', time() - 1), queue: 'counter-failure');
    $this->artisan('queue:work', [
        'connection' => 'database',
        '--queue' => 'counter-failure',
        '--once' => true,
        '--sleep' => 0,
        '--tries' => 3,
    ])->assertExitCode(0);

    expect(DB::table('jobs')->where('queue', 'counter-failure')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
