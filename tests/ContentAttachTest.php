<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\Internal\ContentAttachInput;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Records\OperationStartInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Tests\Support\CollectingDispatcher;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadFilter;
use Illuminate\Container\Container;

it('projects post-hook content through the restricted attach seam without filtering it again', function (): void {
    $filter = new class implements PayloadFilter
    {
        public int $calls = 0;

        public function filter(OutboundPayload $payload): OutboundPayload
        {
            $this->calls++;

            return $payload;
        }
    };
    $container = new Container;
    $container->instance(PayloadFilter::class, $filter);
    $dispatcher = new CollectingDispatcher;
    $recorder = new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: null,
        batchSize: 1,
        retryForSeconds: 3600,
        drops: new InMemoryDropCounter,
        dispatcher: $dispatcher,
        app: $container,
    );
    $recorder->record(new OperationStartInput(
        operation: Operation::Image,
        invocationId: 'run-1',
        at: new DateTimeImmutable('2026-10-01T12:00:00.000000+00:00'),
        parent: null,
        capture: CaptureMode::Usage,
        sampled: false,
        subject: null,
        model: new ModelInfo(requested: 'model', provider: 'provider'),
    ));
    $target = UuidV7::generate();
    $content = new Content(['structured_output' => [
        'empty_object' => (object) [],
        'empty_list' => [],
    ]]);
    $input = new ContentAttachInput(
        targetRecordId: $target,
        invocationId: 'run-1',
        at: new DateTimeImmutable('2026-10-01T12:01:00.000000+00:00'),
        content: $content,
    );

    $recorder->attach($input);
    $recorder->attach($input);

    $first = EnvelopeCodec::decode($dispatcher->dispatched[1]['json'])->records[0];
    $second = EnvelopeCodec::decode($dispatcher->dispatched[2]['json'])->records[0];

    expect($filter->calls)->toBe(1)
        ->and((string) $first->recordId)->not->toBe((string) $second->recordId)
        ->and((string) $first->targetRecordId)->toBe((string) $target)
        ->and($first->invocationId)->toBe('run-1')
        ->and($first->content?->toJson())->toBe($content->toJson())
        ->and(array_keys($first->toArray()))->toBe([
            'record_id', 'type', 'target_record_id', 'invocation_id', 'at', 'capture', 'content',
        ]);
});

it('does not expose content attach through the public recorder input API', function (): void {
    $input = new ContentAttachInput(
        targetRecordId: UuidV7::generate(),
        invocationId: 'run-1',
        at: new DateTimeImmutable,
        content: new Content(['output_text' => 'post-hook']),
    );

    expect($input)->not->toBeInstanceOf(RecordInput::class)
        ->and((new ReflectionClass(Recorder::class))->hasMethod('attach'))->toBeFalse();
});
