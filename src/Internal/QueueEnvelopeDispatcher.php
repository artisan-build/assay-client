<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;
use ArtisanBuild\AssayClient\Jobs\ShipEnvelope;
use Illuminate\Contracts\Queue\Queue;

final readonly class QueueEnvelopeDispatcher implements EnvelopeDispatcher
{
    public function __construct(private Queue $queue) {}

    public function dispatch(string $envelopeJson, int $retryUntilUnix): void
    {
        $queue = config('assay.queue');

        $this->queue->push(
            new ShipEnvelope(
                envelopeJson: $envelopeJson,
                retryUntilUnix: $retryUntilUnix,
                retryDelaySeconds: max(0, (int) config('assay.retry_delay_seconds', 60)),
            ),
            queue: is_string($queue) && $queue !== '' ? $queue : null,
        );
    }
}
