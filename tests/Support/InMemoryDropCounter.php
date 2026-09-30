<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Tests\Support;

use ArtisanBuild\AssayClient\Contracts\DropCounter;

final class InMemoryDropCounter implements DropCounter
{
    public int $transport = 0;

    public int $hook = 0;

    public function transportTotal(): int
    {
        return $this->transport;
    }

    public function hookTotal(): int
    {
        return $this->hook;
    }

    public function incrementTransport(): int
    {
        return ++$this->transport;
    }

    public function incrementHook(): int
    {
        return ++$this->hook;
    }
}
