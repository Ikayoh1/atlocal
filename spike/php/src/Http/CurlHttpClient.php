<?php

declare(strict_types=1);

namespace AtLocal\Spike\Http;

use RuntimeException;

/**
 * curl transport with TLS verification always on (custom CA for the offline
 * internal PKI) and environment proxies explicitly ignored so a stray
 * HTTP(S)_PROXY can never route LAN mail traffic elsewhere.
 */
final class CurlHttpClient implements HttpClient
{
    /** @param list<string> $resolve CURLOPT_RESOLVE entries ("host:port:ip") */
    public function __construct(
        private readonly ?string $caFile = null,
        private readonly array $resolve = [],
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, // never forward credentials to a redirect target
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($this->caFile !== null) {
            $opts[CURLOPT_CAINFO] = $this->caFile;
        }
        if ($this->resolve !== []) {
            $opts[CURLOPT_RESOLVE] = $this->resolve;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new TransportException("HTTP {$method} {$url} failed: [{$errno}] {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new HttpResponse($status, (string) $responseBody);
    }
}
