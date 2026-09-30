<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Contracts;

interface EnvelopeDispatcher
{
    public function dispatch(string $envelopeJson, int $retryUntilUnix): void;
}
