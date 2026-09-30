<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient;

interface Recorder
{
    public function record(RecordInput $input): void;

    public function flush(): void;
}
