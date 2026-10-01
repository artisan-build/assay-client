<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Transport;

use ArtisanBuild\AssayClient\Contracts\Transport;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

final readonly class HttpTransport implements Transport
{
    public function __construct(private Factory $http) {}

    public function send(string $envelopeJson): void
    {
        $url = config('assay.url');
        $token = config('assay.token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            return;
        }

        $response = $this->pendingRequest()
            ->withToken($token)
            ->connectTimeout(max(0.05, (float) config('assay.connect_timeout', 0.5)))
            ->timeout(max(0.05, (float) config('assay.timeout', 5)))
            ->withBody($envelopeJson, 'application/json')
            ->post($url);

        if ($response->status() === 413) {
            throw new PayloadTooLargeException;
        }

        $response->throw();
    }

    private function pendingRequest(): PendingRequest
    {
        try {
            $request = $this->http->__call('withClientIdentity', []);

            if ($request instanceof PendingRequest) {
                return $request;
            }
        } catch (Throwable) {
            // Identity is attribution only, so transport remains fail-open.
        }

        return $this->http->createPendingRequest();
    }
}
