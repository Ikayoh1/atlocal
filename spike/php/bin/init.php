<?php

declare(strict_types=1);

/**
 * Prepares git-ignored spike material (idempotent):
 *  - var/secrets/spike-secrets.json  random recovery-admin and user passwords
 *  - var/certs/                      dev CA + server certificate for the mail hostname
 *  - var/stalwart/etc/config.json    engine data-store pointer (RocksDB on local disk)
 * Prints paths only, never secret values.
 */

use AtLocal\Spike\Tls\LocalCa;

require __DIR__ . '/_bootstrap.php';

$created = $secrets->initialise($config->users());
echo ($created ? 'created ' : 'kept    ') . "var/secrets/spike-secrets.json\n";

$certs = (new LocalCa($config->varDir('certs')))->ensureServerCertificate($config->hostname());
echo ($certs['created'] ? 'created ' : 'kept    ') . "var/certs/ (CA + server certificate for {$config->hostname()})\n";

$dataDir = str_replace('\\', '/', $config->varDir('stalwart/data')) . '/';
foreach ([$config->varDir('stalwart/etc'), $dataDir, $config->varDir('stalwart/logs'), $config->varDir('spike')] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(1);
    }
}
$engineConfig = $config->varDir('stalwart/etc/config.json');
if (!is_file($engineConfig)) {
    // Documented minimal form: only the data store; everything else lives in the store
    // and is applied through the management API (docs: configuration/declarative-deployments).
    spike_write_json($engineConfig, ['@type' => 'RocksDb', 'path' => $dataDir]);
    echo "created var/stalwart/etc/config.json (RocksDB at {$dataDir})\n";
} else {
    echo "kept    var/stalwart/etc/config.json\n";
}
