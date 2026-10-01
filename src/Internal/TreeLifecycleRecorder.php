<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use Closure;

interface TreeLifecycleRecorder
{
    public function retainTreeContext(string $invocationId, ?Closure $onForcedRelease = null): void;

    public function releaseTreeContext(string $invocationId): void;

    public function sweepTreeContexts(): void;
}
