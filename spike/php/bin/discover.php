<?php

declare(strict_types=1);

/**
 * Records what the running engine ACTUALLY exposes (no assumed endpoints):
 * JMAP session capabilities for admin and a normal user, the management schema
 * and OAuth discovery metadata. Output: var/spike/discovery-<mode>.json
 *
 * Usage: php discover.php [--recovery]
 */

use AtLocal\Spike\Http\CurlHttpClient;
use AtLocal\Spike\Jmap\JmapException;

require __DIR__ . '/_bootstrap.php';

$recovery = in_array('--recovery', $argv, true);
$out = ['mode' => $recovery ? 'recovery' : 'normal', 'at' => gmdate('c')];

$summariseSession = static function (array $session): array {
    return [
        'capabilities' => array_keys($session['capabilities'] ?? []),
        'primaryAccounts' => array_keys($session['primaryAccounts'] ?? []),
        'accountCount' => count($session['accounts'] ?? []),
        'apiUrl' => $session['apiUrl'] ?? null,
        'downloadUrl' => $session['downloadUrl'] ?? null,
        'uploadUrl' => $session['uploadUrl'] ?? null,
        'eventSourceUrl' => $session['eventSourceUrl'] ?? null,
        'coreLimits' => $session['capabilities']['urn:ietf:params:jmap:core'] ?? null,
    ];
};

$admin = spike_admin($config, $secrets, $recovery);
try {
    $out['adminSession'] = $summariseSession($admin->session());
} catch (JmapException $e) {
    $out['adminSession'] = ['error' => $e->getMessage()];
}

$base = $recovery ? $config->recoveryBaseUrl() : $config->httpsBaseUrl();
$http = $recovery ? new CurlHttpClient() : spike_https($config);
$adminCreds = $secrets->read()['recoveryAdmin'];
$auth = ['Authorization' => 'Basic ' . base64_encode($adminCreds['user'] . ':' . $adminCreds['password'])];

$schema = $http->request('GET', $base . '/api/schema', null, $auth);
$out['apiSchema'] = ['httpStatus' => $schema->status, 'bytes' => strlen($schema->body)];
if ($schema->status === 200) {
    file_put_contents($config->varDir('spike/api-schema.json'), $schema->body);
    $out['apiSchema']['savedTo'] = 'var/spike/api-schema.json';
}

if (!$recovery) {
    foreach (['/.well-known/oauth-authorization-server', '/.well-known/openid-configuration', '/.well-known/jmap'] as $path) {
        $r = $http->request('GET', $base . $path);
        $json = $r->json();
        $out['wellKnown'][$path] = ['httpStatus' => $r->status] + ($json !== null ? [
            'grant_types_supported' => $json['grant_types_supported'] ?? null,
            'code_challenge_methods_supported' => $json['code_challenge_methods_supported'] ?? null,
            'token_endpoint_auth_methods_supported' => $json['token_endpoint_auth_methods_supported'] ?? null,
            'scopes_supported' => $json['scopes_supported'] ?? null,
            'issuer' => $json['issuer'] ?? null,
        ] : []);
    }
    try {
        $out['userSession(alice)'] = $summariseSession(spike_user($config, $secrets, 'alice')->session());
    } catch (JmapException $e) {
        $out['userSession(alice)'] = ['error' => $e->getMessage()];
    }
}

$file = $config->varDir('spike/discovery-' . $out['mode'] . '.json');
spike_write_json($file, $out);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
