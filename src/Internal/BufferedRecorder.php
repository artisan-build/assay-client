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
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class BufferedRecorder implements Recorder
{
    /** @var list<RecordV1> */
    private array $records = [];

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
        private readonly RecordInputProjector $projector = new RecordInputProjector,
    ) {
        if ($this->driver === '' || $this->environment === '') {
            throw new InvalidArgumentException('Driver and environment must be non-empty.');
        }

        if ($this->batchSize < 1 || $this->retryForSeconds < 1) {
            throw new InvalidArgumentException('Batch size and retry bound must be positive.');
        }
    }

    public function record(RecordInput $input): void
    {
        try {
            $this->records[] = $this->projector->project($input, $this->driver);

            if (count($this->records) >= $this->batchSize) {
                $this->flush();
            }
        } catch (Throwable) {
            $this->incrementTransportSafely();
        }
    }

    public function flush(): void
    {
        if ($this->records === []) {
            return;
        }

        $records = $this->records;
        $this->records = [];

        try {
            $envelope = new EnvelopeV1(
                envelopeId: UuidV7::generate(),
                sentAt: Timestamp::fromDateTime(new DateTimeImmutable),
                client: $this->client,
                sources: [new Source($this->driver, $this->source->package, $this->source->version)],
                environment: $this->environment,
                droppedTransportTotal: $this->drops->transportTotal(),
                droppedHookTotal: $this->drops->hookTotal(),
                records: $records,
                deploy: $this->deploy,
            );

            $this->dispatcher->dispatch(
                EnvelopeCodec::encode($envelope),
                time() + $this->retryForSeconds,
            );
        } catch (Throwable) {
            $this->incrementTransportSafely();
        }
    }

    private function incrementTransportSafely(): void
    {
        try {
            $this->drops->incrementTransport();
        } catch (Throwable) {
            // Telemetry cannot escape into the host application.
        }
    }
}
