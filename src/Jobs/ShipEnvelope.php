<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Jobs;

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\Transport;
use ArtisanBuild\AssayClient\Transport\PayloadTooLargeException;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\UuidV7;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class ShipEnvelope implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;

    public readonly int $retryUntilUnix;

    public function __construct(
        public readonly string $envelopeJson,
        ?int $retryUntilUnix = null,
        public readonly int $retryDelaySeconds = 60,
    ) {
        $this->retryUntilUnix = $retryUntilUnix ?? time() + 86400;
    }

    public function retryUntil(): DateTimeInterface
    {
        return (new DateTimeImmutable)->setTimestamp($this->retryUntilUnix);
    }

    public function handle(Transport $transport, DropCounter $drops): void
    {
        try {
            $this->send($this->envelopeJson, $transport, $drops);
        } catch (Throwable) {
            if (time() + max(1, $this->retryDelaySeconds) < $this->retryUntilUnix) {
                try {
                    $this->release(max(0, $this->retryDelaySeconds));

                    return;
                } catch (Throwable) {
                    // A release failure becomes a terminal transport drop.
                }
            }

            try {
                $drops->incrementTransport();
            } catch (Throwable) {
                // A broken host cache must not fail the host queue worker.
            }

            try {
                $this->delete();
            } catch (Throwable) {
                // The original transport failure is always contained.
            }
        }
    }

    private function send(string $envelopeJson, Transport $transport, DropCounter $drops): void
    {
        try {
            $transport->send($envelopeJson);

            return;
        } catch (PayloadTooLargeException) {
            $envelope = EnvelopeCodec::decode($envelopeJson);
        }

        $recordCount = count($envelope->records);

        if ($recordCount <= 1) {
            if ($recordCount === 1) {
                $this->incrementTransportSafely($drops);
            }

            return;
        }

        $middle = intdiv($recordCount, 2);
        $this->send($this->splitEnvelope($envelope, array_slice($envelope->records, 0, $middle)), $transport, $drops);
        $this->send($this->splitEnvelope($envelope, array_slice($envelope->records, $middle)), $transport, $drops);
    }

    /** @param list<RecordV1> $records */
    private function splitEnvelope(EnvelopeV1 $envelope, array $records): string
    {
        return EnvelopeCodec::encode(new EnvelopeV1(
            envelopeId: UuidV7::generate(),
            sentAt: $envelope->sentAt,
            client: $envelope->client,
            sources: $envelope->sources,
            environment: $envelope->environment,
            droppedTransportTotal: $envelope->droppedTransportTotal,
            droppedHookTotal: $envelope->droppedHookTotal,
            records: $records,
            deploy: $envelope->deploy,
        ));
    }

    private function incrementTransportSafely(DropCounter $drops): void
    {
        try {
            $drops->incrementTransport();
        } catch (Throwable) {
            // A broken host cache must not fail the host queue worker.
        }
    }
}
