<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Transport\HttpTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('is inert when endpoint configuration is incomplete', function (): void {
    config()->set('assay.url', 'https://assay.test/ingest');
    config()->set('assay.token', null);
    Http::preventStrayRequests();

    resolve(HttpTransport::class)->send('{"safe":true}');

    Http::assertNothingSent();
});

it('sends canonical json with a bearer token when fully configured', function (): void {
    config()->set('assay.url', 'https://assay.test/ingest');
    config()->set('assay.token', 'test-token');
    Http::fake(['https://assay.test/ingest' => Http::response(status: 202)]);

    resolve(HttpTransport::class)->send('{"safe":true}');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://assay.test/ingest'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->body() === '{"safe":true}');
});

it('uses the registered client identity macro when it returns a pending request', function (): void {
    $factory = new class extends Factory
    {
        public bool $macroCalled = false;

        public function __call($method, $parameters)
        {
            $this->macroCalled = true;

            return $this->createPendingRequest()->withHeader('X-Test-Identity', 'present');
        }
    };
    $factory->fake(['https://assay.test/ingest' => $factory->response(status: 202)]);
    config()->set('assay.url', 'https://assay.test/ingest');
    config()->set('assay.token', 'test-token');

    (new HttpTransport($factory))->send('{"safe":true}');

    expect($factory->macroCalled)->toBeTrue();
    $factory->assertSent(static fn (Request $request): bool => $request->hasHeader('X-Test-Identity', 'present'));
});

it('falls back safely when the identity macro returns an unexpected value', function (): void {
    $factory = new class extends Factory
    {
        public bool $fallbackUsed = false;

        public function __call($method, $parameters): object
        {
            return new stdClass;
        }

        public function createPendingRequest(): PendingRequest
        {
            $this->fallbackUsed = true;

            return parent::createPendingRequest();
        }
    };
    $factory->fake(['https://assay.test/ingest' => $factory->response(status: 202)]);
    config()->set('assay.url', 'https://assay.test/ingest');
    config()->set('assay.token', 'test-token');

    (new HttpTransport($factory))->send('{"safe":true}');

    expect($factory->fallbackUsed)->toBeTrue();
    $factory->assertSent(static fn (Request $request): bool => ! $request->hasHeader('X-Test-Identity'));
});
