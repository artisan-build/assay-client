# Assay Client

The Assay client captures AI activity inside a Laravel application, projects it into primitive Envelope v1
records, runs the application's outbound payload filter, and dispatches encrypted queue jobs to a
self-hosted Assay server. Its core has no dependency on a specific AI package; the included default driver
supports `laravel/ai` 1.x.

## Installation

The split package repositories are a release prerequisite and are not assumed to be published. During
source development, add both packages from an Assay checkout to the reporting application's Composer
configuration:

```bash
composer config repositories.assay-contracts path /absolute/path/to/assay/packages/assay-contracts
composer config repositories.assay-client path /absolute/path/to/assay/packages/assay-client
composer require artisan-build/assay-client:^1.0
php artisan vendor:publish --tag=assay-config
```

Laravel package discovery registers `AssayClientServiceProvider`. The package does not require
`laravel/ai`, but it conflicts with releases older than 1.0. When `laravel/ai` is absent, the default driver
registers no listeners and capture remains inert.

Issue an `assay.ingest` installation credential in the Assay server's package UI. Configure the complete
ingest URL and the one-time bearer secret in the reporting application:

```dotenv
ASSAY_URL=https://assay.example.test/ingest
ASSAY_TOKEN=<installation credential>
ASSAY_CAPTURE=usage
```

If `ASSAY_URL` or `ASSAY_TOKEN` is missing, HTTP transport is inert. The credential identifies the
reporting installation; no application id is accepted from the envelope.

## Capture Modes

`usage` is the default. It records record ids and tree linkage, operation and agent class, provider and
requested/responded model, tool names, timings, finish reason, outcomes, approvals, failure classes, and
reported usage metrics. It does not send instructions, messages, responses, structured output, tool
arguments/results, or exception messages.

`full` adds supported content after filtering. Projectors are deny-by-default. Provider options,
continuation tokens, replay blocks, raw provider responses, credentials, headers, endpoints, embedding
vectors, and attachment or media bytes and locators are never projected in either mode.

Set an opaque subject before a root begins:

```php
use Illuminate\Support\Facades\Context;

Context::add('assay.subject', 'user:123');
```

The configured `subject_context_key` defaults to `assay.subject`. The value is read once and frozen for the
tree; Laravel carries Context into queued jobs when it dehydrates and hydrates job context. An unset subject
is sent as `unknown`. This application subject is unrelated to Built for Cloud's credential `Subject`.

## Sampling And Failure Capture

Usage is always complete and is never sampled. In `full` mode, `ASSAY_SAMPLE_RATE` is a finite number from
`0` to `1` and defaults to `1`. The `agent_sample_rates` config map applies exact agent-class overrides. One
decision is made for the root and inherited by every descendant, including linked non-agent operations.

`ASSAY_ALWAYS_ON_FAILURE` defaults to `true`. Every record in an unsampled full-mode agent tree is queued
immediately as usage-only with its original metadata and no content. Post-filter content stays only in a
bounded process buffer. Success discards the buffer. `AgentFailed` or `StepFailed` sends retained content
once as content-only `content.attach` records with fresh ids targeting the original records; the failed
agent end reports `failure_capture` as `complete` or `truncated`. Standalone non-agent operations have no
failure event and are not failure-buffered.

The per-root buffer defaults to 512 KiB with oldest content evicted first. Post-terminal roots waiting for
approval events are also bounded: 128 roots, 64 MiB total, and a 60-second TTL by default. Expiry or eviction
discards buffered content and increments the transport-drop total without adding a durable store.

## Payload Filter

Assay performs no redaction. Applications that need masking, hashing, suppression, or dropping must rebind
`ArtisanBuild\BuiltForCloudContracts\PayloadFilter` in an application service provider. Built for Cloud's
default binding passes payloads through.

Every filter must check `$payload->product` first and return payloads for products it does not own. Assay
payloads are droppable: returning `null` or throwing drops the record and increments
`dropped_hook_total`; nothing is sent unfiltered. The hook may change content only. Record identity,
linkage, usage, timings, capture metadata, and the other usage-class fields are restored from the client's
copy after filtering. Message hashes are computed from post-filter content.

The server's authenticated `/assay/risk` guide contains product-first recipes for dropping one agent,
masking known fields, hashing identifiers, and keeping usage while dropping content.

## Queue Lifecycle

Listeners project source objects synchronously. No source event, agent, tool, throwable, media object, or
provider option reaches the queue. The payload hook runs before `ShipEnvelope`, which implements
`ShouldBeEncrypted`, is queued.

Run a worker for `ASSAY_QUEUE`, or for the application's default queue when it is unset. Shipping retries at
`ASSAY_RETRY_DELAY_SECONDS` intervals until `ASSAY_RETRY_FOR_SECONDS`, which defaults to 24 hours. A terminal
failure is deleted and counted in the application cache store rather than retained with its payload in
`failed_jobs`. Transport and hook drops are monotonic cumulative totals partitioned by configured app and
environment. A broken telemetry path never throws into the host request or queue worker.

Batches default to 100 records and may not exceed the server limit of 500. The encoded client limit defaults
to 4 MiB. A 413 response recursively splits multi-record envelopes; a single record that is still too large
is counted as a transport drop.

## Configuration

| Environment/config | Default | Purpose |
|---|---:|---|
| `ASSAY_URL` / `url` | none | Full server ingest URL |
| `ASSAY_TOKEN` / `token` | none | `assay.ingest` installation secret |
| `ASSAY_APP` / `app` | `APP_NAME` | Local drop-counter partition |
| `ASSAY_ENVIRONMENT` / `environment` | `APP_ENV` | Envelope attribution and drop partition |
| `ASSAY_CAPTURE` / `capture` | `usage` | `usage` or `full` |
| `ASSAY_SAMPLE_RATE` / `sample_rate` | `1` | Full-content root sampling rate |
| `agent_sample_rates` | empty | Exact agent-class rate overrides |
| `ASSAY_ALWAYS_ON_FAILURE` / `always_on_failure` | `true` | Buffer unsampled agent content until failure |
| `ASSAY_FAILURE_BUFFER_BYTES` | `524288` | Per-root failure buffer |
| `ASSAY_MAX_RETAINED_ROOTS` | `128` | Post-terminal retained-root cap |
| `ASSAY_MAX_RETAINED_BUFFER_BYTES` | `67108864` | Aggregate retained-content cap |
| `ASSAY_RETAINED_STATE_TTL_SECONDS` | `60` | Retained-root TTL |
| `ASSAY_SUBJECT_CONTEXT_KEY` | `assay.subject` | Laravel Context subject key |
| `ASSAY_DEPLOY` / `deploy` | `NIGHTWATCH_DEPLOY` | Optional deploy attribution |
| `ASSAY_BATCH_SIZE` | `100` | Maximum records per client batch |
| `ASSAY_MAX_BATCH_BYTES` | `4194304` | Encoded envelope target |
| `ASSAY_RETRY_FOR_SECONDS` | `86400` | Hard delivery retry window |
| `ASSAY_RETRY_DELAY_SECONDS` | `60` | Retry delay |
| `ASSAY_QUEUE` | default queue | Queue name for shipping jobs |
| `ASSAY_CONNECT_TIMEOUT` | `0.5` | HTTP connect timeout in seconds |
| `ASSAY_TIMEOUT` | `5` | HTTP request timeout in seconds |

## Driver API

A custom source driver implements `ArtisanBuild\AssayClient\CaptureDriver`. Its `register()` method receives
a `Recorder` and submits only immutable inputs from `ArtisanBuild\AssayClient\Records`. Drivers do not build
envelopes, run the filter, queue jobs, or perform HTTP. Call `Recorder::flush()` only at a deterministic
lifecycle boundary when a partial batch must ship.

```php
use ArtisanBuild\AssayClient\CaptureDriver;

$app->bind(CaptureDriver::class, MyDriver::class);
```

Only one driver is active. A third-party package owns and distributes its driver, depends on this client,
and rebinds `CaptureDriver` in its service provider. When the active driver's `source()` returns `null`,
Assay does not call `register()`.

## Driver Conformance

Downstream drivers can run the stable conformance entry point without using client internals:

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

The harness verifies source identity, exact typed records, omitted metrics and fractional precision,
failover ordinals, linkage, inherited sampling and subjects, primitive-only shipping state, and canary
absence. A failure throws `ArtisanBuild\AssayClient\Testing\ConformanceViolation`.
