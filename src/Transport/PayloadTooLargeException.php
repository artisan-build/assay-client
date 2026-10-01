<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Transport;

use RuntimeException;

final class PayloadTooLargeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Assay server rejected the envelope as too large.');
    }
}
