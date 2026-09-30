<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Tests\Support\CollectingDispatcher;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\Operation;

it('batches deterministically and stamps current cumulative totals', function (): void {
    $drops = new InMemoryDropCounter;
    $drops->incrementTransport();
    $drops->incrementHook();
    $dispatcher = new CollectingDispatcher;
    $recorder = new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: 'deploy-1',
        batchSize: 2,
        retryForSeconds: 3600,
        drops: $drops,
        dispatcher: $dispatcher,
    );

    foreach (range(1, 3) as $index) {
        $recorder->record(new SingleOperationInput(
            operation: Operation::Embeddings,
            invocationId: "operation-{$index}",
            at: new DateTimeImmutable('2026-09-30T12:00:00+00:00'),
            usage: new Usage(inputTokens: $index),
        ));
    }

    expect($dispatcher->dispatched)->toHaveCount(1);

    $recorder->flush();

    $first = EnvelopeCodec::decode($dispatcher->dispatched[0]['json']);
    $second = EnvelopeCodec::decode($dispatcher->dispatched[1]['json']);

    expect($dispatcher->dispatched)->toHaveCount(2)
        ->and($first->records)->toHaveCount(2)
        ->and($second->records)->toHaveCount(1)
        ->and($first->droppedTransportTotal)->toBe(1)
        ->and($first->droppedHookTotal)->toBe(1)
        ->and($first->toArray())->not->toHaveKey('app');
});

it('contains projection and dispatch failures without reaching the host', function (): void {
    $drops = new InMemoryDropCounter;
    $dispatcher = new class implements EnvelopeDispatcher
    {
        public function dispatch(string $envelopeJson, int $retryUntilUnix): void
        {
            throw new RuntimeException('dispatch failed');
        }
    };
    $recorder = new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: null,
        batchSize: 1,
        retryForSeconds: 3600,
        drops: $drops,
        dispatcher: $dispatcher,
    );

    $recorder->record(new SingleOperationInput(
        operation: Operation::Embeddings,
        invocationId: 'operation-1',
        at: new DateTimeImmutable,
        usage: new Usage(inputTokens: 1),
    ));

    expect($drops->transportTotal())->toBe(1);
});

it('rejects unsupported driver input instead of coercing it into a record', function (): void {
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $recorder = new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: null,
        batchSize: 1,
        retryForSeconds: 3600,
        drops: $drops,
        dispatcher: $dispatcher,
    );

    $recorder->record(new class implements RecordInput {});

    expect($dispatcher->dispatched)->toBe([])
        ->and($drops->transportTotal())->toBe(1);
});
