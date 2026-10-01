<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadDisposition;
use ArtisanBuild\BuiltForCloudContracts\PayloadFilter;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use stdClass;
use Throwable;

final class BufferedRecorder implements Recorder
{
    /** @var list<RecordV1> */
    private array $records = [];

    /** @var array<string, array<string, true>> */
    private array $sentMessageHashes = [];

    public function __construct(
        private readonly string $driver,
        private readonly SourceInfo $source,
        private readonly Client $client,
        private readonly string $environment,
        private readonly ?string $deploy,
        private readonly int $batchSize,
        private readonly int $retryForSeconds,
        private readonly DropCounter $drops,
        private readonly EnvelopeDispatcher $dispatcher,
        private readonly Container $app,
        private readonly RecordInputProjector $projector = new RecordInputProjector,
        private readonly int $maxBatchBytes = 4_194_304,
    ) {
        if ($this->driver === '' || $this->environment === '') {
            throw new InvalidArgumentException('Driver and environment must be non-empty.');
        }

        if ($this->batchSize < 1 || $this->retryForSeconds < 1 || $this->maxBatchBytes < 1) {
            throw new InvalidArgumentException('Batch size, retry bound, and maximum batch bytes must be positive.');
        }

        if ($this->batchSize > 500) {
            throw new InvalidArgumentException('Assay batch_size must not exceed 500.');
        }
    }

    public function record(RecordInput $input): void
    {
        try {
            $projected = $this->projector->project($input, $this->driver);
            $record = $projected;

            try {
                $record = $this->filter($record);
            } catch (Throwable) {
                $this->incrementHookSafely();

                return;
            }

            if ($record === null) {
                $this->incrementHookSafely();

                return;
            }

            $record = $this->deduplicateMessages($record);

            if (! $this->fits([$record])) {
                $this->incrementTransportSafely();

                return;
            }

            if ($this->records !== [] && ! $this->fits([...$this->records, $record])) {
                $this->flush();
            }

            $this->records[] = $record;

            if (count($this->records) >= $this->batchSize) {
                $this->flush();
            }
        } catch (Throwable) {
            $this->incrementTransportSafely();
        } finally {
            if (isset($projected)
                && $projected->type === RecordType::RunEnd
                && $projected->operation === Operation::Agent
                && $projected->invocationId !== null) {
                unset($this->sentMessageHashes[$projected->invocationId]);
            }
        }
    }

    private function filter(RecordV1 $record): ?RecordV1
    {
        $payload = new OutboundPayload(
            product: 'assay',
            kind: $record->type->value,
            schemaVersion: 1,
            disposition: PayloadDisposition::Droppable,
            data: $record->toArray(),
            attributes: [
                'subject' => $record->subject,
                'operation' => $record->operation?->value,
                'agent' => $record->agent,
                'capture' => $record->capture->value,
            ],
        );
        $filtered = $this->app->make(PayloadFilter::class)->filter($payload);

        if ($filtered === null) {
            return null;
        }

        if (! is_array($filtered->data)) {
            throw new InvalidArgumentException('The Assay payload filter must return record data as an array.');
        }

        $data = $filtered->data;

        if (array_key_exists('content', $data)) {
            $data['content'] = $this->normalizeContentStrings($data['content']);
        }

        return (new HookBoundary)->restore($record, $this->rehashMessages($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function rehashMessages(array $data): array
    {
        if (($data['type'] ?? null) !== RecordType::StepStart->value || ! isset($data['content'])) {
            return $data;
        }

        $contentValue = $data['content'];
        $content = $contentValue instanceof stdClass
            ? get_object_vars($contentValue)
            : $contentValue;

        if (! is_array($content)
            || ! isset($content['message_hashes'], $content['new_messages'])
            || ! is_array($content['message_hashes'])
            || (! is_array($content['new_messages']) && ! $content['new_messages'] instanceof stdClass)) {
            return $data;
        }

        $replacements = [];
        $messages = [];
        $newMessages = $content['new_messages'] instanceof stdClass
            ? get_object_vars($content['new_messages'])
            : $content['new_messages'];

        foreach ($newMessages as $hash => $message) {
            if (! is_string($hash)
                || (! is_array($message) && ! $message instanceof stdClass)) {
                continue;
            }

            $replacement = hash('sha256', json_encode(
                $this->canonicalize($message),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $replacements[$hash] = $replacement;
            $messages[$replacement] = $message;
        }

        $content['message_hashes'] = array_map(
            static fn (mixed $hash): mixed => is_string($hash) ? ($replacements[$hash] ?? $hash) : $hash,
            $content['message_hashes'],
        );
        $content['new_messages'] = (object) $messages;
        $data['content'] = $contentValue instanceof stdClass ? (object) $content : $content;

        return $data;
    }

    private function deduplicateMessages(RecordV1 $record): RecordV1
    {
        if ($record->type !== RecordType::StepStart || $record->content === null || $record->invocationId === null) {
            return $record;
        }

        $content = $record->content->toArray();
        $hashes = $content['message_hashes'] ?? [];
        $messages = $content['new_messages'] ?? [];
        $messageMap = $messages instanceof stdClass ? get_object_vars($messages) : $messages;
        $ordered = [];
        $new = [];

        foreach ($hashes as $hash) {
            $message = is_array($messageMap) && is_string($hash) ? ($messageMap[$hash] ?? null) : null;

            if (is_array($message) || $message instanceof stdClass) {
                $hash = hash('sha256', json_encode(
                    $this->canonicalize($message),
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                if (! isset($this->sentMessageHashes[$record->invocationId][$hash])) {
                    $new[$hash] = $message;
                    $this->sentMessageHashes[$record->invocationId][$hash] = true;
                }
            }

            $ordered[] = $hash;
        }

        $content['message_hashes'] = $ordered;
        $content['new_messages'] = (object) $new;

        return (new HookBoundary)->restore($record, ['content' => $content]);
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map($this->canonicalize(...), $properties);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map($this->canonicalize(...), $value);
    }

    private function normalizeContentStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", "\u{FFFD}", $value);
        }

        if ($value instanceof stdClass) {
            return (object) array_map($this->normalizeContentStrings(...), get_object_vars($value));
        }

        return is_array($value) ? array_map($this->normalizeContentStrings(...), $value) : $value;
    }

    public function flush(): void
    {
        if ($this->records === []) {
            return;
        }

        $records = $this->records;
        $this->records = [];

        try {
            $this->dispatch($records);
        } catch (Throwable) {
            $this->incrementTransportSafely();
        }
    }

    /** @param list<RecordV1> $records */
    private function dispatch(array $records): void
    {
        $json = $this->encode($records);

        if (strlen($json) <= $this->maxBatchBytes) {
            $this->dispatcher->dispatch($json, time() + $this->retryForSeconds);

            return;
        }

        if (count($records) === 1) {
            $this->incrementTransportSafely();

            return;
        }

        $middle = intdiv(count($records), 2);
        $this->dispatch(array_slice($records, 0, $middle));
        $this->dispatch(array_slice($records, $middle));
    }

    /** @param list<RecordV1> $records */
    private function fits(array $records): bool
    {
        return strlen($this->encode($records)) <= $this->maxBatchBytes;
    }

    /** @param list<RecordV1> $records */
    private function encode(array $records): string
    {
        return EnvelopeCodec::encode(new EnvelopeV1(
            envelopeId: UuidV7::generate(),
            sentAt: Timestamp::fromDateTime(new DateTimeImmutable),
            client: $this->client,
            sources: [new Source($this->driver, $this->source->package, $this->source->version)],
            environment: $this->environment,
            droppedTransportTotal: $this->drops->transportTotal(),
            droppedHookTotal: $this->drops->hookTotal(),
            records: $records,
            deploy: $this->deploy,
        ));
    }

    private function incrementTransportSafely(): void
    {
        try {
            $this->drops->incrementTransport();
        } catch (Throwable) {
            // Telemetry cannot escape into the host application.
        }
    }

    private function incrementHookSafely(): void
    {
        try {
            $this->drops->incrementHook();
        } catch (Throwable) {
            // Telemetry cannot escape into the host application.
        }
    }
}
