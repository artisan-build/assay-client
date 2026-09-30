<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Contracts;

interface Transport
{
    public function send(string $envelopeJson): void;
}
