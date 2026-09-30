<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Jobs;

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use ArtisanBuild\AssayClient\Contracts\Transport;
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
            $transport->send($this->envelopeJson);
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
}
