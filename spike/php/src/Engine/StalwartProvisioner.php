<?php

declare(strict_types=1);

namespace AtLocal\Spike\Engine;

use AtLocal\Spike\Config\SpikeConfig;
use AtLocal\Spike\Jmap\JmapClient;
use AtLocal\Spike\Jmap\SetResult;

/**
 * Prototype of the ProvisioningProvider for Stalwart 1.x.
 *
 * Uses only object types and fields documented in the Stalwart schema reference
 * (https://stalw.art/docs/ref/) and the management JMAP capability
 * urn:stalwart:jmap. Every operation is an idempotent upsert so the plan can be
 * re-applied. It runs with an ADMIN credential and must never be reachable from
 * browser-facing code paths.
 */
final class StalwartProvisioner
{
    private const USING = [JmapClient::CORE, JmapClient::STALWART];

    /** @var list<string> */
    private array $log = [];

    public function __construct(private readonly JmapClient $admin, private readonly SpikeConfig $config)
    {
    }

    /**
     * @param array<string, string> $userPasswords localPart => password
     * @return list<string> human-readable action log (no secrets)
     */
    public function apply(array $userPasswords, string $certPem, string $keyPem, bool $pruneUnknownListeners = false): array
    {
        $this->log = [];
        $domainId = $this->upsertDomain();
        $certId = $this->upsertCertificate($certPem, $keyPem);
        $this->updateSingleton('x:SystemSettings', [
            'defaultHostname' => $this->config->hostname(),
            'defaultDomainId' => $domainId,
            'defaultCertificateId' => $certId,
        ]);
        $this->ensureListeners($pruneUnknownListeners);
        $this->hardenForIsolatedLan();
        foreach ($userPasswords as $localPart => $password) {
            $this->upsertUser($localPart, $domainId, $password);
        }
        return $this->log;
    }

    /** Snapshot of security-relevant engine settings for the spike report (no secrets). */
    public function inspect(): array
    {
        $out = [];
        foreach (['x:MtaStageRcpt', 'x:MtaOutboundStrategy', 'x:SpamSettings'] as $type) {
            $out[$type] = $this->admin->invoke(self::USING, $type . '/get', ['ids' => ['singleton']])['list'][0] ?? null;
        }
        $out['x:Application'] = $this->getAll('x:Application');
        $out['x:NetworkListener'] = $this->getAll('x:NetworkListener');
        return $out;
    }

    private function upsertDomain(): string
    {
        $domain = $this->config->mailDomain();
        $properties = [
            'name' => $domain,
            'certificateManagement' => ['@type' => 'Manual'], // no ACME on an isolated LAN
            'dnsManagement' => ['@type' => 'Manual'],          // no public DNS provider
            'dkimManagement' => ['@type' => 'Manual'],         // internal-only mail; see SPIKE-RESULTS
            'allowRelaying' => false,
            'reportAddressUri' => null,                        // no DMARC/TLS-RPT report generation
        ];
        $existing = $this->findOne('x:Domain', ['name' => $domain]);
        if ($existing !== null) {
            $this->update('x:Domain', $existing['id'], $properties);
            $this->log[] = "domain {$domain}: updated ({$existing['id']})";
            return $existing['id'];
        }
        $id = $this->create('x:Domain', $properties);
        $this->log[] = "domain {$domain}: created ({$id})";
        return $id;
    }

    private function upsertCertificate(string $certPem, string $keyPem): string
    {
        $properties = [
            'certificate' => ['@type' => 'Text', 'value' => $certPem],
            'privateKey' => ['@type' => 'Text', 'secret' => $keyPem],
        ];
        foreach ($this->getAll('x:Certificate') as $cert) {
            // subjectAlternativeNames is server-computed from the PEM; also match on the stored
            // PEM so re-applying never duplicates the certificate object.
            $sans = array_keys((array) ($cert['subjectAlternativeNames'] ?? []));
            $samePem = trim((string) ($cert['certificate']['value'] ?? '')) === trim($certPem);
            if ($samePem || in_array($this->config->hostname(), $sans, true)) {
                $this->update('x:Certificate', $cert['id'], $properties);
                $this->log[] = "certificate for {$this->config->hostname()}: updated ({$cert['id']})";
                return $cert['id'];
            }
        }
        $id = $this->create('x:Certificate', $properties);
        $this->log[] = "certificate for {$this->config->hostname()}: created ({$id})";
        return $id;
    }

    /**
     * Loopback/LAN-bound listeners only. Implicit TLS everywhere; the
     * unauthenticated SMTP listener exists solely to run relay/spoofing probes.
     */
    private function ensureListeners(bool $prune): void
    {
        $ip = $this->config->bindIp();
        $desired = [
            ['protocol' => 'http', 'port' => $this->config->port('https'), 'implicitTls' => true],
            ['protocol' => 'smtp', 'port' => $this->config->port('submissions'), 'implicitTls' => true],
            ['protocol' => 'smtp', 'port' => $this->config->port('smtpUnauthenticatedProbe'), 'implicitTls' => false],
            ['protocol' => 'imap', 'port' => $this->config->port('imaps'), 'implicitTls' => true],
        ];
        $existing = $this->getAll('x:NetworkListener');
        $matched = [];
        foreach ($desired as $want) {
            $bind = "{$ip}:{$want['port']}";
            $found = null;
            foreach ($existing as $listener) {
                $binds = array_keys((array) ($listener['bind'] ?? []));
                if (($listener['protocol'] ?? null) === $want['protocol'] && in_array($bind, $binds, true)) {
                    $found = $listener;
                    break;
                }
            }
            $properties = [
                'protocol' => $want['protocol'],
                'bind' => [$bind => true],
                'useTls' => true,
                'implicitTls' => $want['implicitTls'],
            ];
            if ($found !== null) {
                $matched[] = $found['id'];
                $this->update('x:NetworkListener', $found['id'], $properties);
                $this->log[] = "listener {$want['protocol']} {$bind}: updated";
            } else {
                $matched[] = $this->create('x:NetworkListener', $properties);
                $this->log[] = "listener {$want['protocol']} {$bind}: created";
            }
        }
        foreach ($existing as $listener) {
            if (in_array($listener['id'], $matched, true)) {
                continue;
            }
            $desc = ($listener['protocol'] ?? '?') . ' ' . implode(',', array_keys((array) ($listener['bind'] ?? [])));
            if ($prune) {
                $response = $this->admin->invoke(self::USING, 'x:NetworkListener/set', ['destroy' => [$listener['id']]]);
                if (!in_array($listener['id'], $response['destroyed'] ?? [], true)) {
                    throw new \RuntimeException("Could not remove unexpected listener {$desc}");
                }
                $this->log[] = "listener {$desc}: REMOVED (not in spike plan)";
            } else {
                $this->log[] = "WARNING listener {$desc}: present but not in spike plan (re-run with --prune-listeners)";
            }
        }
    }

    /**
     * Settings that otherwise cause internet access or external delivery.
     * See docs/SPIKE-RESULTS.md "Offline hardening".
     */
    private function hardenForIsolatedLan(): void
    {
        // Default is "!is_empty(authenticated_as)": ANY authenticated user may relay to
        // external domains. atLocal is internal-only, so reject non-local recipients at RCPT.
        $this->updateSingleton('x:MtaStageRcpt', ['allowRelaying' => ['else' => 'false']]);

        // Spam filter downloads rules from GitHub and follows URLs found in message bodies.
        $this->updateSingleton('x:SpamSettings', ['enable' => false, 'spamFilterRulesUrl' => null]);

        // WebUI bundle is downloaded from GitHub. The spike does not use it; disable.
        foreach ($this->getAll('x:Application') as $app) {
            $this->update('x:Application', $app['id'], ['enable' => false]);
            $this->log[] = "application {$app['id']} ({$app['description']}): disabled (resourceUrl was {$app['resourceUrl']})";
        }
    }

    private function upsertUser(string $localPart, string $domainId, string $password): void
    {
        $properties = [
            'credentials' => ['0' => ['@type' => 'Password', 'secret' => $password]],
            'roles' => ['@type' => 'User'],
        ];
        $existing = $this->findOne('x:Account', ['name' => $localPart, 'domainId' => $domainId]);
        if ($existing !== null) {
            $this->update('x:Account', $existing['id'], $properties);
            $this->log[] = "user {$localPart}: updated ({$existing['id']})";
            return;
        }
        $id = $this->create('x:Account', ['@type' => 'User', 'name' => $localPart, 'domainId' => $domainId] + $properties);
        $this->log[] = "user {$localPart}: created ({$id})";
    }

    // ----- generic JMAP helpers -------------------------------------------------

    /** @return array<string, mixed>|null */
    private function findOne(string $type, array $filter): ?array
    {
        $ids = $this->admin->invoke(self::USING, $type . '/query', ['filter' => $filter])['ids'] ?? [];
        if ($ids === []) {
            return null;
        }
        if (count($ids) > 1) {
            throw new \RuntimeException("{$type}: more than one object matches " . json_encode($filter));
        }
        return $this->admin->invoke(self::USING, $type . '/get', ['ids' => $ids])['list'][0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function getAll(string $type): array
    {
        $ids = $this->admin->invoke(self::USING, $type . '/query', [])['ids'] ?? [];
        if ($ids === []) {
            return [];
        }
        return $this->admin->invoke(self::USING, $type . '/get', ['ids' => $ids])['list'] ?? [];
    }

    private function create(string $type, array $properties): string
    {
        $response = $this->admin->invoke(self::USING, $type . '/set', ['create' => ['new' => $properties]]);
        return (string) SetResult::created($type . '/set', $response, 'new')['id'];
    }

    private function update(string $type, string $id, array $patch): void
    {
        $response = $this->admin->invoke(self::USING, $type . '/set', ['update' => [$id => $patch]]);
        SetResult::updated($type . '/set', $response, $id);
    }

    private function updateSingleton(string $type, array $patch): void
    {
        $this->update($type, 'singleton', $patch);
        $this->log[] = "{$type}: updated " . implode(', ', array_keys($patch));
    }
}
