<?php

declare(strict_types=1);

namespace AtLocal\Spike\Jmap;

use AtLocal\Spike\Http\HttpClient;

/**
 * Minimal RFC 8620 JMAP client.
 *
 * Security decisions:
 *  - Every request goes to the configured base URL. URLs advertised in the JMAP
 *    session (apiUrl, downloadUrl) only contribute their *path*; their scheme,
 *    host and port are ignored, so a session document cannot redirect
 *    credentials to another host.
 *  - One client instance == one principal's credential. The app never reuses an
 *    admin client for user mailbox operations.
 */
final class JmapClient
{
    public const CORE = 'urn:ietf:params:jmap:core';
    public const MAIL = 'urn:ietf:params:jmap:mail';
    public const SUBMISSION = 'urn:ietf:params:jmap:submission';
    public const STALWART = 'urn:stalwart:jmap';

    /** @var array<string, mixed>|null */
    private ?array $session = null;
    private int $callCounter = 0;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $authorization,
    ) {
    }

    public static function basic(HttpClient $http, string $baseUrl, string $user, string $password): self
    {
        return new self($http, rtrim($baseUrl, '/'), 'Basic ' . base64_encode($user . ':' . $password));
    }

    public static function bearer(HttpClient $http, string $baseUrl, string $token): self
    {
        return new self($http, rtrim($baseUrl, '/'), 'Bearer ' . $token);
    }

    /** @return array<string, mixed> */
    public function session(bool $refresh = false): array
    {
        if ($this->session !== null && !$refresh) {
            return $this->session;
        }
        $response = $this->http->request('GET', $this->baseUrl . '/jmap/session', null, $this->headers());
        $json = $response->json();
        if ($response->status !== 200 || $json === null) {
            throw JmapException::fromHttp($response->status, $response->body, 'session');
        }
        return $this->session = $json;
    }

    public function primaryAccountId(string $capability): string
    {
        $id = $this->session()['primaryAccounts'][$capability] ?? null;
        if (!is_string($id) || $id === '') {
            throw new JmapException("Session has no primary account for capability {$capability}");
        }
        return $id;
    }

    public function hasCapability(string $capability): bool
    {
        return isset($this->session()['capabilities'][$capability]);
    }

    /** Resolves a session-advertised URL to the pinned base URL (path only). */
    public function pinnedUrl(string $sessionKey, array $templateValues = []): string
    {
        $advertised = $this->session()[$sessionKey] ?? null;
        if (!is_string($advertised)) {
            throw new JmapException("Session is missing {$sessionKey}");
        }
        foreach ($templateValues as $name => $value) {
            $advertised = str_replace('{' . $name . '}', rawurlencode((string) $value), $advertised);
        }
        $path = parse_url($advertised, PHP_URL_PATH) ?? '/';
        $query = parse_url($advertised, PHP_URL_QUERY);
        return $this->baseUrl . $path . ($query !== null ? '?' . $query : '');
    }

    /**
     * Sends a JMAP request and returns raw methodResponses.
     *
     * @param list<string> $using
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $methodCalls
     * @return list<array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public function call(array $using, array $methodCalls): array
    {
        $body = json_encode(['using' => $using, 'methodCalls' => $methodCalls], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = $this->http->request(
            'POST',
            $this->pinnedUrl('apiUrl'),
            $body,
            $this->headers() + ['Content-Type' => 'application/json'],
        );
        $json = $response->json();
        if ($response->status !== 200 || !isset($json['methodResponses']) || !is_array($json['methodResponses'])) {
            throw JmapException::fromHttp($response->status, $response->body, 'api');
        }
        return $json['methodResponses'];
    }

    /**
     * Invokes a single method and returns its arguments, throwing on a JMAP method error.
     *
     * @param list<string> $using
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function invoke(array $using, string $method, array $arguments): array
    {
        $callId = 'c' . (++$this->callCounter);
        // An empty PHP array would encode as [] but JMAP requires an object.
        $responses = $this->call($using, [[$method, $arguments === [] ? new \stdClass() : $arguments, $callId]]);
        foreach ($responses as [$name, $args, $id]) {
            if ($id !== $callId) {
                continue;
            }
            if ($name === 'error') {
                throw JmapMethodError::fromArguments($method, $args);
            }
            return $args;
        }
        throw new JmapException("No response for {$method}");
    }

    /** Raw authenticated GET of a pinned path (used for blob download authorisation tests). */
    public function get(string $url): \AtLocal\Spike\Http\HttpResponse
    {
        if (!str_starts_with($url, $this->baseUrl . '/')) {
            throw new JmapException('Refusing to send credentials outside the pinned base URL');
        }
        return $this->http->request('GET', $url, null, $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => $this->authorization, 'Accept' => 'application/json'];
    }
}
