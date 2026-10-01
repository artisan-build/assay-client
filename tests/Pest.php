<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Tests\TestCase;

uses(TestCase::class)->in(
    'AssayClientServiceProviderTest.php',
    'DropCounterTest.php',
    'DriverConformanceTest.php',
    'DriverRegistrarTest.php',
    'HttpTransportTest.php',
    'LaravelAiDriverTest.php',
    'QueueShippingTest.php',
);
