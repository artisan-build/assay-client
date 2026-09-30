<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\ModelInfo;
use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\Records\AttemptInput;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\SingleOperationInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\Records\ToolCallInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Testing\ConformanceViolation;
use ArtisanBuild\AssayClient\Testing\DriverConformance;
use ArtisanBuild\AssayClient\Testing\DriverScenario;
use ArtisanBuild\AssayClient\Testing\FakeDriver;
use ArtisanBuild\AssayClient\Usage;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\RecordType;

function conformanceRecords(?int $manufacturedOutput = null): array
{
    $at = new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00');
    $parent = new ParentLink('parent-invocation', 'parent-tool');
    $model = new ModelInfo(requested: 'requested-model', responded: 'responded-model', provider: 'provider-a');

    return [
        new RunInput(RecordType::RunStart, 'run-1', 1, $at, parent: $parent, subject: 'subject-1', model: $model),
        new StepInput(RecordType::StepEnd, 'run-1', 1, 0, $at, parent: $parent, usage: new Usage(
            inputTokens: 11,
            outputTokens: 12,
            cacheReadInputTokens: 3,
            cacheWriteInputTokens: 4,
            reasoningTokens: 5,
            imageInputTokens: 6,
            imageOutputTokens: 7,
            audioSeconds: 1.25,
            searchUnits: 2.75,
        ), model: $model),
        new ToolCallInput(RecordType::ToolEnd, 'run-1', 1, 'tool-1', $at, step: 0, parent: $parent),
        new AttemptInput('run-1', 1, $at, parent: $parent, model: $model),
        new RunInput(RecordType::RunStart, 'run-1', 2, $at, parent: $parent, model: $model),
        new RunInput(RecordType::RunEnd, 'run-1', 2, $at, parent: $parent, usage: new Usage(inputTokens: 2), model: $model),
        new SingleOperationInput(Operation::Embeddings, 'embeddings-1', $at, parent: $parent, usage: new Usage(inputTokens: 8)),
        new SingleOperationInput(Operation::Image, 'image-1', $at, parent: $parent, usage: new Usage(imageOutputTokens: 9)),
        new SingleOperationInput(Operation::Audio, 'audio-1', $at, parent: $parent, usage: new Usage(outputTokens: 10)),
        new SingleOperationInput(Operation::Transcription, 'transcription-1', $at, parent: $parent, usage: new Usage(audioSeconds: 3.5)),
        new SingleOperationInput(Operation::Reranking, 'reranking-1', $at, parent: $parent, usage: new Usage(outputTokens: $manufacturedOutput, searchUnits: 4.125)),
        new SingleOperationInput(Operation::Classification, 'classification-1', $at, parent: $parent, usage: new Usage(inputTokens: 13, outputTokens: 14)),
    ];
}

it('passes a source independent fake with all operations metrics failover and linkage', function (): void {
    $source = new SourceInfo('vendor/fake-source', '1.2.3');
    $records = conformanceRecords();
    $receivedCanary = null;
    $driver = new FakeDriver('fake', $source, static function (string $sourceValue) use (&$receivedCanary, $records): array {
        $receivedCanary = $sourceValue;

        return $records;
    });
    $canary = 'content-canary-must-not-ship';

    DriverConformance::assert($driver, new DriverScenario(
        driverName: 'fake',
        source: $source,
        exercise: static function (string $canary) use ($driver): void {
            $driver->capture($canary);
        },
        expectedRecords: $records,
        canary: $canary,
        supportsFailover: true,
    ));

    expect($receivedCanary)->toBe($canary);
});

it('fails a deliberately broken driver that leaks a source object', function (): void {
    $source = new SourceInfo('vendor/broken-source', '1.0.0');
    $sourceEvent = new stdClass;
    $leaky = new class($sourceEvent) implements RecordInput
    {
        public function __construct(public object $sourceEvent) {}
    };
    $driver = new FakeDriver('broken-leak', $source, static fn (string $sourceValue): array => [$leaky]);

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'broken-leak',
        source: $source,
        exercise: static function (string $canary) use ($driver): void {
            $driver->capture($canary);
        },
        expectedRecords: [$leaky],
        canary: 'leak-canary',
    )))->toThrow(ConformanceViolation::class, 'source object');
});

it('fails a deliberately broken driver that manufactures zero for an omitted metric', function (): void {
    $source = new SourceInfo('vendor/broken-source', '1.0.0');
    $expected = conformanceRecords();
    $actual = conformanceRecords(0);
    $driver = new FakeDriver('broken-zero', $source, static fn (string $sourceValue): array => $actual);

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'broken-zero',
        source: $source,
        exercise: static function (string $canary) use ($driver): void {
            $driver->capture($canary);
        },
        expectedRecords: $expected,
        canary: 'zero-canary',
        supportsFailover: true,
    )))->toThrow(ConformanceViolation::class, 'zero manufacturing');
});

it('fails a deliberately broken driver that projects the source canary', function (): void {
    $source = new SourceInfo('vendor/broken-source', '1.0.0');
    $at = new DateTimeImmutable('2026-09-30T12:00:00.123456+00:00');
    $expected = new SingleOperationInput(
        operation: Operation::Embeddings,
        invocationId: 'embeddings-1',
        at: $at,
        usage: new Usage(inputTokens: 8),
    );
    $driver = new FakeDriver('broken-canary', $source, static fn (string $sourceValue): array => [
        new SingleOperationInput(
            operation: Operation::Embeddings,
            invocationId: 'embeddings-1',
            at: $at,
            subject: $sourceValue,
            usage: new Usage(inputTokens: 8),
        ),
    ]);

    expect(fn () => DriverConformance::assert($driver, new DriverScenario(
        driverName: 'broken-canary',
        source: $source,
        exercise: static function (string $canary) use ($driver): void {
            $driver->capture($canary);
        },
        expectedRecords: [$expected],
        canary: 'projected-source-canary',
    )))->toThrow(ConformanceViolation::class, 'canary reached recorder input');
});

it('keeps unknown source absence inert', function (): void {
    $registered = false;
    $driver = new class($registered) implements CaptureDriver
    {
        public function __construct(private bool &$registered) {}

        public function name(): string
        {
            return 'absent';
        }

        public function source(): ?SourceInfo
        {
            return null;
        }

        public function register(Recorder $recorder): void
        {
            $this->registered = true;
        }
    };

    expect($driver->source())->toBeNull()
        ->and($registered)->toBeFalse();
});
