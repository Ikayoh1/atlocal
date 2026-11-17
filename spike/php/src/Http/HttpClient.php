<?php

declare(strict_types=1);

namespace AtLocal\Spike\Http;

interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse;
}
