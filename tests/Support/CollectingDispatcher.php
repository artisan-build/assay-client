<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Tests\Support;

use ArtisanBuild\AssayClient\Contracts\EnvelopeDispatcher;

final class CollectingDispatcher implements EnvelopeDispatcher
{
    /** @var list<array{json: string, retry_until: int}> */
    public array $dispatched = [];

    public function dispatch(string $envelopeJson, int $retryUntilUnix): void
    {
        $this->dispatched[] = [
            'json' => $envelopeJson,
            'retry_until' => $retryUntilUnix,
        ];
    }
}
