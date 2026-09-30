# Assay Client

The client core converts source-specific activity into Assay Envelope v1 records and dispatches encrypted queue jobs. It has no dependency on a specific AI package.

## Driver API

A driver implements `ArtisanBuild\AssayClient\CaptureDriver`. Its `register()` method receives a `Recorder` and may submit only the immutable inputs in `ArtisanBuild\AssayClient\Records`: `RunInput`, `AttemptInput`, `StepInput`, `ToolCallInput`, and `SingleOperationInput`.

Drivers do not construct envelopes or jobs and do not perform HTTP. Call `Recorder::flush()` at a deterministic lifecycle boundary when a partial batch must ship.

```php
use ArtisanBuild\AssayClient\CaptureDriver;

$app->bind(CaptureDriver::class, MyDriver::class);
```

When the active driver's `source()` returns `null`, Assay remains inert and does not call `register()`.

## Driver conformance

Downstream driver packages can run the stable conformance entry point without using Assay internals:

```php
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Testing\DriverConformance;
use ArtisanBuild\AssayClient\Testing\DriverScenario;

DriverConformance::assert($driver, new DriverScenario(
    driverName: 'vendor-ai',
    source: new SourceInfo('vendor/ai', '1.2.3'),
    exercise: fn (string $canary) => $source->runScenario($canary),
    expectedRecords: $expectedRecords,
    canary: 'scenario-secret-canary',
    supportsFailover: true,
));
```

The harness verifies source identity, exact typed records, usage omission and fractional precision, failover ordinals, linkage, primitive-only shipping state, and canary absence. A failure throws `ArtisanBuild\AssayClient\Testing\ConformanceViolation`.

## Configuration

Publish `assay-config` or set `ASSAY_URL` and `ASSAY_TOKEN`. If either is missing, the HTTP transport is inert. The queue job resolves both values only when it handles the already-projected envelope.
