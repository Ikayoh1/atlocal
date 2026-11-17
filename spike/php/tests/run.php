<?php

declare(strict_types=1);

/**
 * Offline unit tests for the spike library (no mail engine required).
 * Run: php spike/php/tests/run.php
 */

namespace AtLocal\Spike\Tests;

use AtLocal\Spike\Config\SpikeConfig;
use AtLocal\Spike\Engine\StalwartProvisioner;
use AtLocal\Spike\Http\HttpResponse;
use AtLocal\Spike\Jmap\JmapClient;
use AtLocal\Spike\Jmap\JmapException;
use AtLocal\Spike\Jmap\JmapMethodError;
use AtLocal\Spike\Jmap\SetError;
use AtLocal\Spike\Jmap\SetResult;
use AtLocal\Spike\Mail\UserMailbox;
use AtLocal\Spike\Tls\LocalCa;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/Fakes.php';

const BASE = 'https://mail.companyname.local:8443';

$tests = [];
$assert = static function (bool $cond, string $message): void {
    if (!$cond) {
        throw new \AssertionError($message);
    }
};
$expectThrows = static function (string $class, callable $fn) use ($assert): \Throwable {
    try {
        $fn();
    } catch (\Throwable $e) {
        $assert($e instanceof $class, 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        return $e;
    }
    throw new \AssertionError("expected {$class}, nothing thrown");
};

// --- JmapClient ---------------------------------------------------------------------

$tests['jmap: session-advertised foreign apiUrl never receives credentials'] = function () use ($assert) {
    $http = new FakeHttp(fn ($m, $url) => str_ends_with($url, '/jmap/session')
        ? json(200, session('acctA', 'https://attacker.example.org/jmap/'))
        : json(200, ['methodResponses' => [['Core/echo', [], 'c1']]]));
    $client = JmapClient::basic($http, BASE, 'alice@companyname.local', 'pw');
    $client->invoke([JmapClient::CORE], 'Core/echo', []);
    foreach ($http->requests as $r) {
        $assert(str_starts_with($r['url'], BASE . '/'), "request left pinned base: {$r['url']}");
    }
};

$tests['jmap: raw get refuses URLs outside the pinned base'] = function () use ($expectThrows) {
    $client = JmapClient::basic(new FakeHttp(fn () => json(200, [])), BASE, 'a', 'b');
    $expectThrows(JmapException::class, fn () => $client->get('https://attacker.example.org/x'));
};

$tests['jmap: empty arguments are sent as a JSON object, not an array'] = function () use ($assert) {
    $http = new FakeHttp(fn ($m, $url) => str_ends_with($url, '/session') ? json(200, session()) : json(200, ['methodResponses' => [['x:Account/query', ['ids' => []], 'c1']]]));
    JmapClient::basic($http, BASE, 'a', 'b')->invoke([JmapClient::CORE], 'x:Account/query', []);
    $assert(str_contains((string) end($http->requests)['body'], '"x:Account/query",{}'), 'arguments not encoded as {}');
};

$tests['jmap: method error becomes typed exception'] = function () use ($assert, $expectThrows) {
    $http = new FakeHttp(fn ($m, $url) => str_ends_with($url, '/session') ? json(200, session()) : json(200, ['methodResponses' => [['error', ['type' => 'accountNotFound'], 'c1']]]));
    $e = $expectThrows(JmapMethodError::class, fn () => JmapClient::basic($http, BASE, 'a', 'b')->invoke([JmapClient::CORE], 'Email/query', ['accountId' => 'other']));
    $assert($e->errorType === 'accountNotFound', 'wrong error type');
};

$tests['jmap: 401 on session is surfaced with status'] = function () use ($assert, $expectThrows) {
    $http = new FakeHttp(fn () => json(401, ['type' => 'about:blank', 'detail' => 'unauthorized']));
    $e = $expectThrows(JmapException::class, fn () => JmapClient::basic($http, BASE, 'a', 'wrong')->session());
    $assert($e->httpStatus === 401, 'status not recorded');
};

// --- SetResult ----------------------------------------------------------------------

$tests['set: notCreated is an error with its type'] = function () use ($assert, $expectThrows) {
    $e = $expectThrows(SetError::class, fn () => SetResult::created('EmailSubmission/set', ['notCreated' => ['s' => ['type' => 'forbiddenToSend']]], 's'));
    $assert($e->errorType === 'forbiddenToSend', 'type lost');
};

$tests['set: unconfirmed update is not treated as success'] = function () use ($expectThrows) {
    $expectThrows(JmapException::class, fn () => SetResult::updated('x:Domain/set', ['updated' => []], 'd1'));
};

// --- UserMailbox --------------------------------------------------------------------

$mailboxFake = static function (array $submissionResult): FakeHttp {
    return new FakeHttp(function ($m, $url, $body) use ($submissionResult) {
        if (str_ends_with($url, '/jmap/session')) {
            return json(200, session('acctAlice'));
        }
        $out = [];
        foreach ($body['methodCalls'] as [$name, $args, $id]) {
            $out[] = match ($name) {
                'Mailbox/get' => [$name, ['list' => [['id' => 'mbD', 'role' => 'drafts'], ['id' => 'mbS', 'role' => 'sent'], ['id' => 'mbI', 'role' => 'inbox']]], $id],
                'Identity/get' => [$name, ['list' => [['id' => 'idA', 'email' => 'alice@companyname.local']]], $id],
                'Email/set' => [$name, ['created' => ['draft' => ['id' => 'e1']]], $id],
                'EmailSubmission/set' => [$name, $submissionResult, $id],
            };
        }
        return json(200, ['methodResponses' => $out]);
    });
};

$tests['mailbox: failed submission throws (never reported as sent)'] = function () use ($mailboxFake, $expectThrows) {
    $http = $mailboxFake(['notCreated' => ['sub' => ['type' => 'forbiddenToSend', 'description' => 'Relay not allowed']]]);
    $box = new UserMailbox(JmapClient::basic($http, BASE, 'alice@companyname.local', 'pw'), 'alice@companyname.local');
    $expectThrows(SetError::class, fn () => $box->send(['someone@example.org'], 's', 'b'));
};

$tests['mailbox: every call uses the accountId from the user own session'] = function () use ($mailboxFake, $assert) {
    $http = $mailboxFake(['created' => ['sub' => ['id' => 'sub1']]]);
    $box = new UserMailbox(JmapClient::basic($http, BASE, 'alice@companyname.local', 'pw'), 'alice@companyname.local');
    $result = $box->send(['bob@companyname.local'], 'hello', 'body');
    $assert($result === ['emailId' => 'e1', 'submissionId' => 'sub1'], 'unexpected result');
    foreach ($http->methodCalls() as [$name, $args]) {
        $assert(($args['accountId'] ?? null) === 'acctAlice', "{$name} used accountId " . var_export($args['accountId'] ?? null, true));
    }
};

$tests['mailbox: successful send moves draft to Sent and clears $draft'] = function () use ($mailboxFake, $assert) {
    $http = $mailboxFake(['created' => ['sub' => ['id' => 'sub1']]]);
    (new UserMailbox(JmapClient::basic($http, BASE, 'a@companyname.local', 'pw'), 'alice@companyname.local'))->send(['bob@companyname.local'], 's', 'b');
    foreach ($http->methodCalls() as [$name, $args]) {
        if ($name === 'EmailSubmission/set') {
            $patch = $args['onSuccessUpdateEmail']['#sub'];
            $assert($patch === ['mailboxIds/mbD' => null, 'mailboxIds/mbS' => true, 'keywords/$draft' => null], 'wrong onSuccessUpdateEmail');
        }
    }
};

// --- Provisioner --------------------------------------------------------------------

$tests['provision: hardening plan disables relay, spam-rule download and WebUI download'] = function () use ($assert) {
    $engine = new FakeStalwart();
    $http = new FakeHttp(fn ($m, $u, $b) => $engine->handle($m, $u, $b));
    (new StalwartProvisioner(JmapClient::basic($http, BASE, 'admin', 'pw'), SpikeConfig::load()))
        ->apply(['alice' => 'p1', 'bob' => 'p2'], 'CERT', 'KEY');
    $assert($engine->singletons['x:MtaStageRcpt']['allowRelaying'] === ['else' => 'false'], 'relay not disabled');
    $assert($engine->singletons['x:SpamSettings']['spamFilterRulesUrl'] === null, 'spam rules URL still set');
    $assert($engine->singletons['x:SpamSettings']['enable'] === false, 'spam filter not disabled');
    $assert($engine->objects['x:Application']['app1']['enable'] === false, 'WebUI download not disabled');
    $domain = array_values($engine->objects['x:Domain'])[0];
    $assert($domain['allowRelaying'] === false && $domain['certificateManagement'] === ['@type' => 'Manual'], 'domain not internal-only');
};

$tests['provision: listeners bind only to configured bindIp'] = function () use ($assert) {
    $engine = new FakeStalwart();
    $http = new FakeHttp(fn ($m, $u, $b) => $engine->handle($m, $u, $b));
    $config = SpikeConfig::load();
    (new StalwartProvisioner(JmapClient::basic($http, BASE, 'admin', 'pw'), $config))->apply([], 'CERT', 'KEY');
    $assert(count($engine->objects['x:NetworkListener']) === 4, 'expected 4 listeners');
    foreach ($engine->objects['x:NetworkListener'] as $l) {
        foreach (array_keys($l['bind']) as $bind) {
            $assert(str_starts_with($bind, $config->bindIp() . ':'), "listener bound to {$bind}");
        }
        $assert($l['useTls'] === true, 'listener without TLS');
    }
};

$tests['provision: re-applying is idempotent (no duplicate objects)'] = function () use ($assert) {
    $engine = new FakeStalwart();
    $http = new FakeHttp(fn ($m, $u, $b) => $engine->handle($m, $u, $b));
    $p = new StalwartProvisioner(JmapClient::basic($http, BASE, 'admin', 'pw'), SpikeConfig::load());
    $p->apply(['alice' => 'p1'], 'CERT', 'KEY');
    $before = array_map('count', $engine->objects);
    $p->apply(['alice' => 'p1-rotated'], 'CERT', 'KEY');
    $assert(array_map('count', $engine->objects) === $before, 'object counts changed on re-apply');
    $alice = array_values($engine->objects['x:Account'])[0];
    $assert($alice['credentials']['0']['secret'] === 'p1-rotated', 'password not updated in place');
};

$tests['provision: unexpected listener is reported, not silently destroyed'] = function () use ($assert) {
    $engine = new FakeStalwart();
    $engine->objects['x:NetworkListener'] = ['old' => ['id' => 'old', 'protocol' => 'smtp', 'bind' => ['0.0.0.0:25' => true]]];
    $http = new FakeHttp(fn ($m, $u, $b) => $engine->handle($m, $u, $b));
    $log = (new StalwartProvisioner(JmapClient::basic($http, BASE, 'admin', 'pw'), SpikeConfig::load()))->apply([], 'C', 'K');
    $assert(isset($engine->objects['x:NetworkListener']['old']), 'listener destroyed without --prune-listeners');
    $assert((bool) preg_grep('/WARNING listener smtp 0\.0\.0\.0:25/', $log), 'no warning logged');
};

// --- Dev PKI ------------------------------------------------------------------------

$tests['tls: server certificate chains to dev CA with hostname SAN'] = function () use ($assert) {
    $dir = sys_get_temp_dir() . '/atlocal-ca-test-' . bin2hex(random_bytes(4));
    try {
        $paths = (new LocalCa($dir))->ensureServerCertificate('mail.companyname.local');
        $cert = openssl_x509_read((string) file_get_contents($paths['serverCert']));
        $info = openssl_x509_parse($cert);
        $assert(str_contains($info['extensions']['subjectAltName'] ?? '', 'DNS:mail.companyname.local'), 'SAN missing');
        $assert(openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER, [$paths['caCert']]) === true, 'does not verify against CA');
        $assert(openssl_x509_check_private_key($cert, (string) file_get_contents($paths['serverKey'])), 'key mismatch');
        $assert((new LocalCa($dir))->ensureServerCertificate('mail.companyname.local')['created'] === false, 'not idempotent');
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
};

$tests['config: rejects an invalid mail domain'] = function () use ($expectThrows) {
    $root = sys_get_temp_dir() . '/atlocal-cfg-' . bin2hex(random_bytes(4));
    mkdir($root . '/spike', 0700, true);
    file_put_contents($root . '/spike/spike.config.json', json_encode(['mailDomain' => 'Company Name.local', 'bindIp' => '127.0.0.1']));
    try {
        $expectThrows(\RuntimeException::class, fn () => SpikeConfig::load($root));
    } finally {
        unlink($root . '/spike/spike.config.json');
        rmdir($root . '/spike');
        rmdir($root);
    }
};

// --- runner -------------------------------------------------------------------------

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "ok   {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "FAIL {$name}\n     " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
echo "\n" . (count($tests) - $failed) . '/' . count($tests) . " passed\n";
exit($failed > 0 ? 1 : 0);
