# Assay Client

The client core converts source-specific activity into Assay Envelope v1 records and dispatches encrypted queue jobs. It has no dependency on a specific AI package.

## Driver API

A driver implements `ArtisanBuild\AssayClient\CaptureDriver`. Its `register()` method receives a `Recorder` and may submit only the immutable inputs in `ArtisanBuild\AssayClient\Records`: `RunInput`, `AttemptInput`, `OperationStartInput`, `StepInput`, `ToolCallInput`, and `SingleOperationInput`.

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

It also verifies that descendants report the root's sampling decision and frozen subject consistently. The core recorder makes the authoritative root decision before filtering and shipping.

## Configuration

Publish `assay-config` or set `ASSAY_URL` and `ASSAY_TOKEN`. If either is missing, the HTTP transport is inert. The queue job resolves both values only when it handles the already-projected envelope.

`ASSAY_BATCH_SIZE` defaults to 100 and may not exceed the server admission limit of 500. `ASSAY_MAX_BATCH_BYTES` defaults to 4 MiB; the client flushes before that encoded-envelope limit and recursively splits any batch the server rejects as too large.

In `full` capture, `ASSAY_SAMPLE_RATE` is a number from `0` to `1` and defaults to `1`. The `agent_sample_rates` configuration map applies exact agent-class overrides; an override wins for an agent root, while every descendant inherits that root decision without another draw. Usage mode remains complete and never carries full content.

`ASSAY_ALWAYS_ON_FAILURE` defaults to `true`. Every record in an unsampled full-mode agent tree is queued immediately as `capture=usage` and `sampled=false`, with its original id, linkage, timing, model, outcome, failure, and usage fields but no content. Post-filter content is retained only in process, bounded by `ASSAY_FAILURE_BUFFER_BYTES` (512 KiB per root by default) with oldest content evicted first. Success discards that buffer. `AgentFailed` or `StepFailed` sends retained content once as content-only `content.attach` records with fresh ids targeting the originals, without running the payload filter again, and marks the failed agent end as `complete` or `truncated`. Standalone non-agent operations are never failure-buffered, although a non-agent operation linked inside an agent tree inherits that tree's decision and capture outcome.

Post-terminal state waiting for a Laravel AI approval event is also bounded in process. `ASSAY_MAX_RETAINED_ROOTS` defaults to 128, `ASSAY_MAX_RETAINED_BUFFER_BYTES` defaults to 64 MiB across retained roots, and `ASSAY_RETAINED_STATE_TTL_SECONDS` defaults to 60 seconds. A lifecycle check expires entries at the TTL and evicts the oldest retained roots when either aggregate cap is exceeded. Cleanup discards their buffered content without attaching it and increments the transport-drop total; it does not add a background worker or durable store.

Set the opaque subject before a root starts with `Context::add('assay.subject', 'user:123')`. The key is configurable as `subject_context_key` or `ASSAY_SUBJECT_CONTEXT_KEY`. It is read once and frozen for the tree; Laravel carries Context into queued jobs when it dehydrates and hydrates job context. An unset subject is sent as `unknown`. This application subject is unrelated to Built for Cloud's credential `Subject`.
