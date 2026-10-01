<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Tests\Support\CollectingDispatcher;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadFilter;
use Illuminate\Container\Container;

function filteredRecorder(Container $container, InMemoryDropCounter $drops, CollectingDispatcher $dispatcher): BufferedRecorder
{
    return new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: null,
        batchSize: 100,
        retryForSeconds: 3600,
        drops: $drops,
        dispatcher: $dispatcher,
        app: $container,
    );
}

function passThroughPayloadFilter(): PayloadFilter
{
    return new class implements PayloadFilter
    {
        public function filter(OutboundPayload $payload): OutboundPayload
        {
            return $payload;
        }
    };
}

it('resolves the released filter for every record and restores all protected fields', function (): void {
    $container = new Container;
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $recorder = filteredRecorder($container, $drops, $dispatcher);
    $observed = (object) ['value' => []];
    $container->instance(PayloadFilter::class, new readonly class($observed) implements PayloadFilter
    {
        public function __construct(private stdClass $observed) {}

        public function filter(OutboundPayload $payload): OutboundPayload
        {
            $this->observed->value = [$payload->product, $payload->kind, $payload->schemaVersion, $payload->attributes];
            $data = array_fill_keys(array_keys($payload->data), 'corrupted');
            $data['content'] = ['instructions' => 'masked'];

            return new OutboundPayload('assay', 'changed', 99, $payload->disposition, $data, []);
        }
    });
    $recorder->record(new RunInput(
        type: RecordType::RunStart,
        invocationId: 'run-1',
        attempt: 1,
        at: new DateTimeImmutable('2026-10-01T12:00:00+00:00'),
        capture: CaptureMode::Full,
        sampled: true,
        subject: 'user:1',
        agent: 'App\\Ai\\Agent',
        content: new Content(['instructions' => 'secret']),
    ));
    $recorder->flush();

    $record = EnvelopeCodec::decode($dispatcher->dispatched[0]['json'])->records[0];
    expect($observed->value)->toBe(['assay', 'run.start', 1, [
        'subject' => 'user:1',
        'operation' => 'agent',
        'agent' => 'App\\Ai\\Agent',
        'capture' => 'full',
    ]])->and($record->invocationId)->toBe('run-1')
        ->and($record->attempt)->toBe(1)
        ->and($record->capture)->toBe(CaptureMode::Full)
        ->and($record->content?->toArray())->toBe(['instructions' => 'masked'])
        ->and($drops->hookTotal())->toBe(0);
});

it('observes a filter rebind after recording has started', function (): void {
    $container = new Container;
    $dispatcher = new CollectingDispatcher;
    $recorder = filteredRecorder($container, new InMemoryDropCounter, $dispatcher);
    $filter = static fn (string $instructions): PayloadFilter => new readonly class($instructions) implements PayloadFilter
    {
        public function __construct(private string $instructions) {}

        public function filter(OutboundPayload $payload): OutboundPayload
        {
            $data = $payload->data;
            $data['content'] = ['instructions' => $this->instructions];

            return new OutboundPayload(
                $payload->product,
                $payload->kind,
                $payload->schemaVersion,
                $payload->disposition,
                $data,
                $payload->attributes,
            );
        }
    };
    $record = static fn (string $invocation): RunInput => new RunInput(
        RecordType::RunStart,
        $invocation,
        1,
        new DateTimeImmutable,
        capture: CaptureMode::Full,
        sampled: true,
        content: new Content(['instructions' => 'original']),
    );

    $container->instance(PayloadFilter::class, $filter('first'));
    $recorder->record($record('run-1'));
    $container->instance(PayloadFilter::class, $filter('second'));
    $recorder->record($record('run-2'));
    $recorder->flush();

    $records = EnvelopeCodec::decode($dispatcher->dispatched[0]['json'])->records;

    expect($records[0]->content?->toArray())->toBe(['instructions' => 'first'])
        ->and($records[1]->content?->toArray())->toBe(['instructions' => 'second']);
});

it('silently drops null and throwing hook results exactly once', function (string $behavior): void {
    $container = new Container;
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $container->instance(PayloadFilter::class, new readonly class($behavior) implements PayloadFilter
    {
        public function __construct(private string $behavior) {}

        public function filter(OutboundPayload $payload): ?OutboundPayload
        {
            if ($this->behavior === 'throw') {
                throw new RuntimeException('HOOK-CANARY');
            }

            return null;
        }
    });

    expect(fn () => filteredRecorder($container, $drops, $dispatcher)->record(new RunInput(
        RecordType::RunStart,
        'run-1',
        1,
        new DateTimeImmutable,
    )))->not->toThrow(Throwable::class)
        ->and($dispatcher->dispatched)->toBe([])
        ->and($drops->hookTotal())->toBe(1)
        ->and($drops->transportTotal())->toBe(0);
})->with(['null', 'throw']);

it('hashes post-hook messages, deduplicates bodies, and prunes at run end', function (): void {
    $container = new Container;
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $container->instance(PayloadFilter::class, new class implements PayloadFilter
    {
        public function filter(OutboundPayload $payload): OutboundPayload
        {
            $data = $payload->data;

            if (($data['type'] ?? null) === 'step.start') {
                foreach ($data['content']->new_messages as $hash => $message) {
                    if ($message->text === 'first secret') {
                        $data['content']->new_messages->{$hash}->text = 'masked';
                    }
                }
            }

            return new OutboundPayload(
                $payload->product,
                $payload->kind,
                $payload->schemaVersion,
                $payload->disposition,
                $data,
                $payload->attributes,
            );
        }
    });
    $recorder = filteredRecorder($container, $drops, $dispatcher);

    $step = static function (int $number, array $texts): StepInput {
        $messages = [];

        foreach ($texts as $text) {
            $message = ['role' => 'user', 'text' => $text];
            $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $messages[$hash] = $message;
        }

        return new StepInput(
            type: RecordType::StepStart,
            invocationId: 'run-1',
            attempt: 1,
            step: $number,
            at: new DateTimeImmutable,
            capture: CaptureMode::Full,
            sampled: true,
            content: new Content(['message_hashes' => array_keys($messages), 'new_messages' => $messages]),
        );
    };

    $recorder->record($step(0, ['first secret']));
    $recorder->record($step(1, ['first secret', 'second secret']));
    $recorder->record(new RunInput(
        type: RecordType::RunEnd,
        invocationId: 'run-1',
        attempt: 1,
        at: new DateTimeImmutable,
        capture: CaptureMode::Full,
        sampled: true,
        outcome: Outcome::Completed,
    ));
    $recorder->record($step(2, ['first secret']));
    $recorder->flush();

    $records = EnvelopeCodec::decode($dispatcher->dispatched[0]['json'])->records;
    $first = $records[0]->content?->toArray();
    $secondContent = $records[1]->content?->toArray();
    $afterPrune = $records[3]->content?->toArray();
    $masked = ['role' => 'user', 'text' => 'masked'];
    $expectedHash = hash('sha256', json_encode(['role' => 'user', 'text' => 'masked'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $secondMessage = ['role' => 'user', 'text' => 'second secret'];
    $secondHash = hash('sha256', json_encode($secondMessage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    expect($first)->toBe(['message_hashes' => [$expectedHash], 'new_messages' => [$expectedHash => $masked]])
        ->and($secondContent)->toBe(['message_hashes' => [$expectedHash, $secondHash], 'new_messages' => [$secondHash => $secondMessage]])
        ->and($afterPrune)->toBe(['message_hashes' => [$expectedHash], 'new_messages' => [$expectedHash => $masked]])
        ->and($dispatcher->dispatched[0]['json'])->not->toContain('first secret');
});

it('preserves canonical message JSON identity through pass-through filtering and deduplication', function (): void {
    $container = new Container;
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $container->instance(PayloadFilter::class, passThroughPayloadFilter());
    $recorder = filteredRecorder($container, $drops, $dispatcher);
    $message = [
        'role' => 'assistant',
        'tool_calls' => [
            [
                'arguments' => (object) [],
                'id' => 'empty-arguments',
                'name' => 'empty',
            ],
            [
                'arguments' => (object) [
                    'empty_list' => [],
                    'empty_object' => (object) [],
                    'populated_list' => [(object) ['value' => 1], false, null],
                ],
                'id' => 'nested-arguments',
                'name' => 'nested',
            ],
        ],
    ];
    $canonicalBody = json_encode(
        $message,
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $hash = hash('sha256', $canonicalBody);
    $step = static fn (int $attempt): StepInput => new StepInput(
        type: RecordType::StepStart,
        invocationId: 'identity-run',
        attempt: $attempt,
        step: 0,
        at: new DateTimeImmutable,
        capture: CaptureMode::Full,
        sampled: true,
        content: new Content([
            'message_hashes' => [$hash],
            'new_messages' => [$hash => $message],
        ]),
    );

    $recorder->record($step(1));
    $recorder->record($step(2));
    $recorder->flush();

    $wire = $dispatcher->dispatched[0]['json'];
    $records = EnvelopeCodec::decode($wire)->records;
    $first = $records[0]->content?->jsonSerialize();
    $second = $records[1]->content?->jsonSerialize();
    assert($first instanceof stdClass);
    assert($second instanceof stdClass);
    assert($first->new_messages instanceof stdClass);

    expect($wire)->toContain('"'.$hash.'":'.$canonicalBody)
        ->and($first->message_hashes)->toBe([$hash])
        ->and($first->new_messages->{$hash}->tool_calls[0]->arguments)->toBeInstanceOf(stdClass::class)
        ->and($first->new_messages->{$hash}->tool_calls[1]->arguments->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($first->new_messages->{$hash}->tool_calls[1]->arguments->empty_list)->toBe([])
        ->and($first->new_messages->{$hash}->tool_calls[1]->arguments->populated_list)->toHaveCount(3)
        ->and($second->message_hashes)->toBe([$hash])
        ->and($second->new_messages)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($second->new_messages))->toBe([])
        ->and($drops->hookTotal())->toBe(0)
        ->and($drops->transportTotal())->toBe(0);
});

it('normalizes null characters recursively after the payload hook without changing JSON shapes', function (): void {
    $container = new Container;
    $dispatcher = new CollectingDispatcher;
    $container->instance(PayloadFilter::class, passThroughPayloadFilter());
    $recorder = filteredRecorder($container, new InMemoryDropCounter, $dispatcher);
    $message = ['role' => 'user', 'text' => "message\0value"];
    $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $normalizedMessage = ['role' => 'user', 'text' => "message\u{FFFD}value"];
    $normalizedHash = hash('sha256', json_encode($normalizedMessage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $recorder->record(new StepInput(
        type: RecordType::StepStart,
        invocationId: 'null-normalization-run',
        attempt: 1,
        step: 0,
        at: new DateTimeImmutable,
        capture: CaptureMode::Full,
        sampled: true,
        content: new Content([
            'message_hashes' => [$hash],
            'new_messages' => [$hash => $message],
        ]),
    ));
    $recorder->record(new StepInput(
        type: RecordType::StepEnd,
        invocationId: 'null-normalization-run',
        attempt: 1,
        step: 1,
        at: new DateTimeImmutable,
        capture: CaptureMode::Full,
        sampled: true,
        content: new Content([
            'output_text' => "outer\0value",
            'structured_output' => (object) [
                'empty_object' => (object) [],
                'list' => ["list\0value", 7, false, null],
                'nested' => (object) ['text' => "nested\0value"],
            ],
        ]),
    ));
    $recorder->flush();

    $records = EnvelopeCodec::decode($dispatcher->dispatched[0]['json'])->records;
    $messageContent = $records[0]->content?->jsonSerialize();
    $content = $records[1]->content?->jsonSerialize();
    assert($messageContent instanceof stdClass);
    assert($content instanceof stdClass);

    expect($messageContent->message_hashes)->toBe([$normalizedHash])
        ->and($messageContent->new_messages->{$normalizedHash}->text)->toBe("message\u{FFFD}value")
        ->and($content->output_text)->toBe("outer\u{FFFD}value")
        ->and($content->structured_output)->toBeInstanceOf(stdClass::class)
        ->and($content->structured_output->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($content->structured_output->list)->toBe(["list\u{FFFD}value", 7, false, null])
        ->and($content->structured_output->nested)->toBeInstanceOf(stdClass::class)
        ->and($content->structured_output->nested->text)->toBe("nested\u{FFFD}value");
});
