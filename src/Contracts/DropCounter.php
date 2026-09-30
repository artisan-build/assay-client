<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Contracts;

interface DropCounter
{
    public function transportTotal(): int;

    public function hookTotal(): int;

    public function incrementTransport(): int;

    public function incrementHook(): int;
}
