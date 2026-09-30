<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\Contracts\DropCounter;
use DateInterval;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

final readonly class CacheDropCounter implements DropCounter
{
    private string $prefix;

    public function __construct(
        private Repository $cache,
        string $application,
        string $environment,
    ) {
        if ($application === '' || $environment === '') {
            throw new RuntimeException('Application identity and environment must be non-empty.');
        }

        $this->prefix = 'assay:drops:'.hash('sha256', $application."\0".$environment);
    }

    public function transportTotal(): int
    {
        return $this->total('transport');
    }

    public function hookTotal(): int
    {
        return $this->total('hook');
    }

    public function incrementTransport(): int
    {
        return $this->increment('transport');
    }

    public function incrementHook(): int
    {
        return $this->increment('hook');
    }

    private function total(string $kind): int
    {
        return max(0, (int) $this->cache->get($this->key($kind), 0));
    }

    private function increment(string $kind): int
    {
        $key = $this->key($kind);
        $total = $this->cache->increment($key);

        if ($total === false) {
            $this->cache->add($key, 0, new DateInterval('P10Y'));
            $total = $this->cache->increment($key);
        }

        if (! is_int($total)) {
            throw new RuntimeException('The configured cache store does not support atomic increments.');
        }

        return $total;
    }

    private function key(string $kind): string
    {
        return "{$this->prefix}:{$kind}";
    }
}
