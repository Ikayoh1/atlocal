<?php

declare(strict_types=1);

namespace AtLocal\Spike\Tls;

use RuntimeException;

/**
 * Development-only internal CA. Models the offline-PKI approach atLocal needs
 * (no ACME / public CA reachable on an isolated LAN): a site CA that clients
 * trust, issuing the mail server's certificate.
 *
 * Production must use the organisation's own CA process; private keys here
 * are spike material only and live in git-ignored var/certs/.
 */
final class LocalCa
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @return array{caCert: string, serverCert: string, serverKey: string, created: bool}
     */
    public function ensureServerCertificate(string $hostname, int $days = 397): array
    {
        $paths = [
            'caCert' => $this->dir . '/atlocal-dev-ca.pem',
            'caKey' => $this->dir . '/atlocal-dev-ca.key',
            'serverCert' => $this->dir . '/server.pem',
            'serverKey' => $this->dir . '/server.key',
        ];
        if (is_file($paths['caCert']) && is_file($paths['serverCert']) && is_file($paths['serverKey'])) {
            return ['caCert' => $paths['caCert'], 'serverCert' => $paths['serverCert'], 'serverKey' => $paths['serverKey'], 'created' => false];
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create {$this->dir}");
        }

        $cnf = $this->writeOpensslConfig($hostname);
        $base = ['config' => $cnf, 'digest_alg' => 'sha256'];
        // RSA-3072: PHP 8.3 + OpenSSL 3.0 on Windows rejects EC generation via openssl_pkey_new
        // (verified during the spike); RSA is also the most broadly compatible for mail clients.
        $keyOpts = $base + ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072];

        $caKey = openssl_pkey_new($keyOpts) ?: throw $this->error('CA key generation');
        $caCsr = openssl_csr_new(['commonName' => 'atLocal Development CA (spike only)', 'organizationName' => 'atLocal spike'], $caKey, $base + ['req_extensions' => 'v3_ca'])
            ?: throw $this->error('CA CSR');
        $caCert = openssl_csr_sign($caCsr, null, $caKey, $days, $base + ['x509_extensions' => 'v3_ca'], random_int(1, PHP_INT_MAX))
            ?: throw $this->error('CA self-sign');

        $srvKey = openssl_pkey_new($keyOpts) ?: throw $this->error('server key generation');
        $srvCsr = openssl_csr_new(['commonName' => $hostname], $srvKey, $base + ['req_extensions' => 'v3_server'])
            ?: throw $this->error('server CSR');
        $srvCert = openssl_csr_sign($srvCsr, $caCert, $caKey, $days, $base + ['x509_extensions' => 'v3_server'], random_int(1, PHP_INT_MAX))
            ?: throw $this->error('server sign');

        $this->export($paths['caCert'], fn (&$out) => openssl_x509_export($caCert, $out));
        $this->export($paths['caKey'], fn (&$out) => openssl_pkey_export($caKey, $out, null, $base));
        $this->export($paths['serverCert'], fn (&$out) => openssl_x509_export($srvCert, $out));
        $this->export($paths['serverKey'], fn (&$out) => openssl_pkey_export($srvKey, $out, null, $base));

        return ['caCert' => $paths['caCert'], 'serverCert' => $paths['serverCert'], 'serverKey' => $paths['serverKey'], 'created' => true];
    }

    private function writeOpensslConfig(string $hostname): string
    {
        if (!preg_match('/^[a-z0-9.-]+$/', $hostname)) {
            throw new RuntimeException('Hostname contains characters not allowed in a certificate SAN');
        }
        $cnf = <<<CNF
            [ req ]
            distinguished_name = dn
            prompt = no
            [ dn ]
            CN = placeholder
            [ v3_ca ]
            basicConstraints = critical, CA:TRUE, pathlen:0
            keyUsage = critical, keyCertSign, cRLSign
            subjectKeyIdentifier = hash
            [ v3_server ]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature, keyEncipherment
            extendedKeyUsage = serverAuth
            subjectAltName = DNS:{$hostname}, IP:127.0.0.1
            subjectKeyIdentifier = hash
            CNF;
        $file = $this->dir . '/openssl-spike.cnf';
        if (file_put_contents($file, $cnf) === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
        return $file;
    }

    private function export(string $file, callable $exporter): void
    {
        $out = '';
        if (!$exporter($out) || file_put_contents($file, $out, LOCK_EX) === false) {
            throw $this->error("export {$file}");
        }
        @chmod($file, 0600);
    }

    private function error(string $step): RuntimeException
    {
        $messages = [];
        while (($m = openssl_error_string()) !== false) {
            $messages[] = $m;
        }
        return new RuntimeException("OpenSSL {$step} failed: " . implode('; ', $messages));
    }
}
