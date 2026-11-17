<?php

declare(strict_types=1);

namespace AtLocal\Spike\Config;

use RuntimeException;

/**
 * Non-secret spike settings (spike/spike.config.json) plus paths to generated,
 * git-ignored runtime material under var/.
 */
final class SpikeConfig
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data, public readonly string $projectRoot)
    {
    }

    public static function load(?string $projectRoot = null): self
    {
        $root = $projectRoot ?? dirname(__DIR__, 4);
        $file = $root . '/spike/spike.config.json';
        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException("Cannot read spike config: {$file}");
        }
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        $domain = (string) ($data['mailDomain'] ?? '');
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new RuntimeException("mailDomain is not a valid lower-case domain name: '{$domain}'");
        }
        if (!filter_var($data['bindIp'] ?? '', FILTER_VALIDATE_IP)) {
            throw new RuntimeException('bindIp must be a literal IP address');
        }

        return new self($data, $root);
    }

    public function mailDomain(): string
    {
        return (string) $this->data['mailDomain'];
    }

    public function hostname(): string
    {
        return (string) $this->data['hostname'];
    }

    public function bindIp(): string
    {
        return (string) $this->data['bindIp'];
    }

    public function port(string $name): int
    {
        $port = $this->data['ports'][$name] ?? null;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException("Port '{$name}' is missing or invalid in spike config");
        }
        return $port;
    }

    /** @return list<string> */
    public function users(): array
    {
        return array_values(array_map('strval', $this->data['users']));
    }

    public function address(string $localPart): string
    {
        return $localPart . '@' . $this->mailDomain();
    }

    /** Public HTTPS base URL. Clients connect to bindIp but present this hostname for TLS/SNI. */
    public function httpsBaseUrl(): string
    {
        return sprintf('https://%s:%d', $this->hostname(), $this->port('https'));
    }

    public function recoveryBaseUrl(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port('recoveryHttp'));
    }

    /** curl CURLOPT_RESOLVE entries so tests do not depend on hosts-file or .local resolution. */
    public function curlResolve(): array
    {
        return [sprintf('%s:%d:%s', $this->hostname(), $this->port('https'), $this->bindIp())];
    }

    public function varDir(string $sub = ''): string
    {
        return $this->projectRoot . '/var' . ($sub === '' ? '' : '/' . $sub);
    }

    public function caCertFile(): string
    {
        return $this->varDir('certs/atlocal-dev-ca.pem');
    }
}
