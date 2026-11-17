<?php

declare(strict_types=1);

/**
 * Applies the idempotent spike provisioning plan through the engine's management
 * JMAP API: domain, certificate, system settings, LAN listeners, offline hardening,
 * and the alice/bob/mallory accounts.
 *
 * Usage: php provision.php [--recovery] [--prune-listeners]
 * Writes a secret-free settings snapshot to var/spike/engine-settings-<mode>.json
 */

use AtLocal\Spike\Engine\StalwartProvisioner;

require __DIR__ . '/_bootstrap.php';

$recovery = in_array('--recovery', $argv, true);
$prune = in_array('--prune-listeners', $argv, true);

$certPem = (string) file_get_contents($config->varDir('certs/server.pem'));
$keyPem = (string) file_get_contents($config->varDir('certs/server.key'));
if ($certPem === '' || $keyPem === '') {
    fwrite(STDERR, "Certificates missing; run init.php first\n");
    exit(1);
}

$provisioner = new StalwartProvisioner(spike_admin($config, $secrets, $recovery), $config);
try {
    $log = $provisioner->apply($secrets->read()['users'], $certPem, $keyPem, $prune);
} catch (Throwable $e) {
    fwrite(STDERR, 'PROVISIONING FAILED: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($log as $line) {
    echo "  {$line}\n";
}

$snapshot = $provisioner->inspect();
spike_write_json($config->varDir('spike/engine-settings-' . ($recovery ? 'recovery' : 'normal') . '.json'), $snapshot);
echo 'settings snapshot: var/spike/engine-settings-' . ($recovery ? 'recovery' : 'normal') . ".json\n";
