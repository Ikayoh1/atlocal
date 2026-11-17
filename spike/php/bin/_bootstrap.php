<?php

declare(strict_types=1);

use AtLocal\Spike\Config\SecretStore;
use AtLocal\Spike\Config\SpikeConfig;
use AtLocal\Spike\Http\CurlHttpClient;
use AtLocal\Spike\Jmap\JmapClient;

require dirname(__DIR__) . '/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
foreach (['curl', 'openssl', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "PHP extension '{$ext}' is required\n");
        exit(2);
    }
}

$config = SpikeConfig::load();
$secrets = new SecretStore($config->varDir('secrets/spike-secrets.json'));

/** HTTPS client pinned to the internal CA and to bindIp (no hosts-file or .local DNS dependency). */
function spike_https(SpikeConfig $config): CurlHttpClient
{
    return new CurlHttpClient($config->caCertFile(), $config->curlResolve());
}

function spike_admin(SpikeConfig $config, SecretStore $secrets, bool $recovery): JmapClient
{
    $admin = $secrets->read()['recoveryAdmin'];
    return $recovery
        ? JmapClient::basic(new CurlHttpClient(), $config->recoveryBaseUrl(), $admin['user'], $admin['password'])
        : JmapClient::basic(spike_https($config), $config->httpsBaseUrl(), $admin['user'], $admin['password']);
}

function spike_user(SpikeConfig $config, SecretStore $secrets, string $user, ?string $password = null): JmapClient
{
    return JmapClient::basic(spike_https($config), $config->httpsBaseUrl(), $config->address($user), $password ?? $secrets->userPassword($user));
}

function spike_write_json(string $file, mixed $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
