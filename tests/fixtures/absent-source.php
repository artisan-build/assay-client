<?php

declare(strict_types=1);

namespace Illuminate\Contracts\Events {
    interface Dispatcher {}
}

namespace Composer {
    final class InstalledVersions {}
}

namespace {
    use ArtisanBuild\AssayClient\LaravelAiDriver;
    use Illuminate\Contracts\Events\Dispatcher;

    $source = dirname(__DIR__, 2).'/src';

    require $source.'/RecordInput.php';
    require $source.'/Recorder.php';
    require $source.'/SourceInfo.php';
    require $source.'/CaptureDriver.php';
    require $source.'/ParentLink.php';
    require $source.'/Internal/InvocationState.php';
    require $source.'/LaravelAiDriver.php';

    $events = new class implements Dispatcher {};
    $driver = new LaravelAiDriver($events);

    echo $driver->source() === null ? 'inert' : 'loaded';
}
