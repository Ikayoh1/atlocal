<?php

declare(strict_types=1);

/**
 * Phase 0 acceptance scenario against the running engine (normal mode).
 *
 *   php scenario.php run                 send / receive / reply, authorisation, relay, IMAP
 *   php scenario.php verify-persistence  after an engine restart: earlier mail still present
 *
 * Results: var/spike/results-<phase>.json ; state for the persistence phase: var/spike/state.json
 * Exit code 1 if any check FAILs. FINDINGs are reported but need a human decision.
 */

use AtLocal\Spike\Jmap\JmapClient;
use AtLocal\Spike\Jmap\JmapException;
use AtLocal\Spike\Jmap\JmapMethodError;
use AtLocal\Spike\Jmap\SetError;
use AtLocal\Spike\Jmap\SetResult;
use AtLocal\Spike\Mail\UserMailbox;
use AtLocal\Spike\Probe\ImapProbe;
use AtLocal\Spike\Probe\SmtpProbe;
use AtLocal\Spike\Scenario\CheckRecorder;

require __DIR__ . '/_bootstrap.php';

$phase = $argv[1] ?? 'run';
if (!in_array($phase, ['run', 'verify-persistence'], true)) {
    fwrite(STDERR, "usage: php scenario.php run|verify-persistence\n");
    exit(2);
}

$rec = new CheckRecorder();
$stateFile = $config->varDir('spike/state.json');
$ca = $config->caCertFile();
$host = $config->hostname();
$ip = $config->bindIp();
$external = 'someone@example.org';

$clients = [];
$boxes = [];
foreach ($config->users() as $user) {
    $clients[$user] = spike_user($config, $secrets, $user);
    $boxes[$user] = new UserMailbox($clients[$user], $config->address($user));
}
[$alice, $bob, $mallory] = [$boxes['alice'], $boxes['bob'], $boxes['mallory']];

$imapCount = static function (string $user) use ($config, $secrets, $ip, $host, $ca): ?int {
    $imap = ImapProbe::connect($ip, $config->port('imaps'), $host, $ca);
    try {
        $login = $imap->login($config->address($user), $secrets->userPassword($user));
        return $login['ok'] ? $imap->selectCount('INBOX') : null;
    } finally {
        $imap->logout();
    }
};

if ($phase === 'run') {
    $runId = gmdate('Ymd-His');
    $subject = "[spike {$runId}] Stock count for Accra warehouse";
    $body = "Hi Bob,\n\nPlease confirm the stock count for the Accra warehouse by Friday.\n\nAlice";
    $state = ['runId' => $runId, 'subject' => $subject];

    // ---- 1. Authentication ---------------------------------------------------------
    $rec->run('auth.users-have-own-accounts', 'each user session exposes a distinct mail account', function (CheckRecorder $r) use ($boxes) {
        $ids = array_map(static fn (UserMailbox $b) => $b->accountId(), $boxes);
        $r->assert(count(array_unique($ids)) === count($ids), 'auth.users-have-own-accounts', 'each user session exposes a distinct mail account', json_encode($ids));
    });
    $rec->run('auth.wrong-password-rejected', 'wrong password is rejected with HTTP 401', function (CheckRecorder $r) use ($config, $secrets) {
        try {
            spike_user($config, $secrets, 'alice', 'not-the-password')->session();
            $r->fail('auth.wrong-password-rejected', 'wrong password is rejected with HTTP 401', 'session was returned');
        } catch (JmapException $e) {
            $r->assert($e->httpStatus === 401, 'auth.wrong-password-rejected', 'wrong password is rejected with HTTP 401', "HTTP {$e->httpStatus}");
        }
    });

    // ---- 2. Alice -> Bob ------------------------------------------------------------
    $rec->run('mail.alice-sends', 'Alice submits a message to Bob via JMAP', function (CheckRecorder $r) use ($alice, $config, $subject, $body, &$state) {
        $sent = $alice->send([$config->address('bob')], $subject, $body);
        $state['alice.sentEmailId'] = $sent['emailId'];
        $r->pass('mail.alice-sends', 'Alice submits a message to Bob via JMAP', 'submission ' . $sent['submissionId']);
    });
    $rec->run('mail.bob-receives', 'Bob finds the message in his Inbox with intact sender/body', function (CheckRecorder $r) use ($bob, $config, $subject, $body, &$state) {
        $msg = $bob->waitForSubject('inbox', $subject);
        if ($msg === null) {
            $r->fail('mail.bob-receives', 'Bob finds the message in his Inbox with intact sender/body', 'not delivered within timeout');
            return;
        }
        $full = $bob->get($msg['id']);
        $state['bob.receivedEmailId'] = $msg['id'];
        $state['bob.receivedBlobId'] = $msg['blobId'];
        $state['original.messageId'] = $full['messageId'][0] ?? null;
        $fromOk = strcasecmp($full['from'][0]['email'] ?? '', $config->address('alice')) === 0;
        $bodyOk = str_contains(UserMailbox::bodyText($full), 'Accra warehouse');
        $r->assert($fromOk && $bodyOk, 'mail.bob-receives', 'Bob finds the message in his Inbox with intact sender/body', "from ok: " . var_export($fromOk, true) . ", body ok: " . var_export($bodyOk, true));
    });

    // ---- 3. Bob replies -------------------------------------------------------------
    $rec->run('mail.bob-replies', 'Bob marks it read and replies in-thread', function (CheckRecorder $r) use ($bob, $config, $subject, &$state) {
        $bob->markSeen($state['bob.receivedEmailId']);
        $sent = $bob->send([$config->address('alice')], 'Re: ' . $subject, "Hi Alice,\n\nConfirmed: 1,284 cartons counted.\n\nBob", $state['original.messageId']);
        $state['bob.replyEmailId'] = $sent['emailId'];
        $r->pass('mail.bob-replies', 'Bob marks it read and replies in-thread', 'submission ' . $sent['submissionId']);
    });
    $rec->run('mail.alice-sees-reply', 'Alice receives the reply, threaded to her original', function (CheckRecorder $r) use ($alice, $subject, &$state) {
        $reply = $alice->waitForSubject('inbox', 'Re: ' . $subject);
        if ($reply === null) {
            $r->fail('mail.alice-sees-reply', 'Alice receives the reply, threaded to her original', 'not delivered within timeout');
            return;
        }
        $state['alice.replyEmailId'] = $reply['id'];
        $original = $alice->get($state['alice.sentEmailId']);
        $inReplyOk = ($reply['inReplyTo'][0] ?? null) === $state['original.messageId'];
        $threadOk = $original !== null && $original['threadId'] === $reply['threadId'];
        $inSent = $original !== null && isset($original['mailboxIds'][$alice->mailboxId('sent')]) && !isset($original['keywords']['$draft']);
        $r->assert($inReplyOk && $threadOk && $inSent, 'mail.alice-sees-reply', 'Alice receives the reply, threaded to her original',
            'inReplyTo ok: ' . var_export($inReplyOk, true) . ', same thread: ' . var_export($threadOk, true) . ', original in Sent: ' . var_export($inSent, true));
    });

    // ---- 4. Horizontal authorisation (PRD acceptance 3) ------------------------------
    $expectDenied = static function (CheckRecorder $r, string $id, string $what, callable $attempt): void {
        try {
            $result = $attempt();
            $leaked = is_array($result) && (($result['list'] ?? []) !== [] || ($result['ids'] ?? []) !== []);
            $leaked ? $r->fail($id, $what, 'DATA RETURNED: ' . substr(json_encode($result), 0, 200))
                    : $r->pass($id, $what, 'empty result, nothing disclosed');
        } catch (JmapMethodError $e) {
            $r->pass($id, $what, "method error {$e->errorType}");
        } catch (JmapException $e) {
            $r->pass($id, $what, "request rejected HTTP {$e->httpStatus}");
        }
    };
    $mailUsing = [JmapClient::CORE, JmapClient::MAIL];
    $rec->run('authz.query-other-account', "Alice cannot query Bob's account by its id", function (CheckRecorder $r) use ($expectDenied, $clients, $bob, $mailUsing) {
        $expectDenied($r, 'authz.query-other-account', "Alice cannot query Bob's account by its id",
            fn () => $clients['alice']->invoke($mailUsing, 'Email/query', ['accountId' => $bob->accountId()]));
    });
    $rec->run('authz.mailboxes-other-account', "Alice cannot list Bob's mailboxes", function (CheckRecorder $r) use ($expectDenied, $clients, $bob, $mailUsing) {
        $expectDenied($r, 'authz.mailboxes-other-account', "Alice cannot list Bob's mailboxes",
            fn () => $clients['alice']->invoke($mailUsing, 'Mailbox/get', ['accountId' => $bob->accountId(), 'ids' => null]));
    });
    $rec->run('authz.get-other-message', "Mallory cannot read Bob's message id in any account", function (CheckRecorder $r) use ($expectDenied, $clients, $bob, $mallory, $mailUsing, &$state) {
        foreach ([$mallory->accountId(), $bob->accountId()] as $acct) {
            $expectDenied($r, 'authz.get-other-message', "Mallory cannot read Bob's message id (accountId {$acct})",
                fn () => $clients['mallory']->invoke($mailUsing, 'Email/get', ['accountId' => $acct, 'ids' => [$state['bob.receivedEmailId']]]));
        }
    });
    $rec->run('authz.download-other-blob', "Alice cannot download Bob's message blob", function (CheckRecorder $r) use ($clients, $bob, &$state) {
        $url = $clients['alice']->pinnedUrl('downloadUrl', ['accountId' => $bob->accountId(), 'blobId' => $state['bob.receivedBlobId'], 'name' => 'm.eml', 'type' => 'message/rfc822']);
        $resp = $clients['alice']->get($url);
        $r->assert($resp->status >= 400, 'authz.download-other-blob', "Alice cannot download Bob's message blob", "HTTP {$resp->status}");
    });
    $rec->run('authz.user-cannot-admin', 'A normal user cannot call management (x:Account/query)', function (CheckRecorder $r) use ($expectDenied, $clients) {
        $expectDenied($r, 'authz.user-cannot-admin', 'A normal user cannot call management (x:Account/query)',
            fn () => $clients['alice']->invoke([JmapClient::CORE, JmapClient::STALWART], 'x:Account/query', []));
    });
    $rec->run('authz.mallory-inbox-clean', "Mallory's inbox does not contain Alice/Bob's thread", function (CheckRecorder $r) use ($mallory, $subject) {
        $leak = array_filter($mallory->list('inbox', 50), static fn ($m) => str_contains((string) ($m['subject'] ?? ''), $subject));
        $r->assert($leak === [], 'authz.mallory-inbox-clean', "Mallory's inbox does not contain Alice/Bob's thread");
    });
    $rec->run('authz.jmap-from-spoof', 'Alice cannot submit JMAP mail with From: bob', function (CheckRecorder $r) use ($clients, $alice, $config, $runId) {
        $using = [JmapClient::CORE, JmapClient::MAIL, JmapClient::SUBMISSION];
        $acct = $alice->accountId();
        $identities = $clients['alice']->invoke($using, 'Identity/get', ['accountId' => $acct, 'ids' => null])['list'];
        $responses = $clients['alice']->call($using, [
            ['Email/set', ['accountId' => $acct, 'create' => ['d' => [
                'mailboxIds' => [$alice->mailboxId('drafts') => true],
                'from' => [['email' => $config->address('bob')]],
                'to' => [['email' => $config->address('mallory')]],
                'subject' => "[spike {$runId}] spoof probe (JMAP)",
                'bodyValues' => ['b' => ['value' => 'spoof probe']],
                'textBody' => [['partId' => 'b', 'type' => 'text/plain']],
            ]]], 'e'],
            ['EmailSubmission/set', ['accountId' => $acct, 'create' => ['s' => ['identityId' => $identities[0]['id'], 'emailId' => '#d']]], 's'],
        ]);
        $sub = null;
        foreach ($responses as [$name, $args, $cid]) {
            if ($cid === 's') {
                $sub = [$name, $args];
            }
        }
        if ($sub === null || $sub[0] === 'error') {
            $r->pass('authz.jmap-from-spoof', 'Alice cannot submit JMAP mail with From: bob', 'method error ' . ($sub[1]['type'] ?? 'none'));
            return;
        }
        try {
            SetResult::created('EmailSubmission/set', $sub[1], 's');
            $r->finding('authz.jmap-from-spoof', 'Alice cannot submit JMAP mail with From: bob', 'ACCEPTED: engine allowed a From header not matching the identity');
        } catch (SetError $e) {
            $r->pass('authz.jmap-from-spoof', 'Alice cannot submit JMAP mail with From: bob', $e->errorType);
        }
    });

    // ---- 5. Internal-only routing (PRD acceptance 2) ---------------------------------
    $rec->run('relay.jmap-external-rejected', 'JMAP submission to an external domain is rejected', function (CheckRecorder $r) use ($alice, $external, $runId) {
        try {
            $alice->send([$external], "[spike {$runId}] external relay probe", 'should never leave the LAN');
            $r->fail('relay.jmap-external-rejected', 'JMAP submission to an external domain is rejected', 'submission ACCEPTED (message may be queued for external delivery)');
        } catch (SetError | JmapMethodError $e) {
            $r->pass('relay.jmap-external-rejected', 'JMAP submission to an external domain is rejected', $e->getMessage());
        }
    });
    $smtpAuthed = static function () use ($config, $secrets, $ip, $host, $ca): SmtpProbe {
        $smtp = SmtpProbe::connect($ip, $config->port('submissions'), true, $host, $ca);
        $smtp->ehlo();
        [$code, $text] = $smtp->authPlain($config->address('alice'), $secrets->userPassword('alice'));
        if ($code !== 235) {
            throw new RuntimeException("AUTH failed: {$code} {$text}");
        }
        return $smtp;
    };
    $rec->run('relay.smtp-auth-external-rejected', 'Authenticated SMTP RCPT to external domain gets 5xx', function (CheckRecorder $r) use ($smtpAuthed, $config, $external) {
        $smtp = $smtpAuthed();
        $smtp->command('MAIL FROM:<' . $config->address('alice') . '>');
        [$code, $text] = $smtp->command("RCPT TO:<{$external}>");
        $smtp->quit();
        $r->assert($code >= 500, 'relay.smtp-auth-external-rejected', 'Authenticated SMTP RCPT to external domain gets 5xx', "{$code} {$text}");
    });
    $rec->run('spoof.smtp-auth-mail-from-other', 'Authenticated Alice cannot use MAIL FROM bob', function (CheckRecorder $r) use ($smtpAuthed, $config) {
        $smtp = $smtpAuthed();
        [$code, $text] = $smtp->command('MAIL FROM:<' . $config->address('bob') . '>');
        $smtp->quit();
        $code >= 500 ? $r->pass('spoof.smtp-auth-mail-from-other', 'Authenticated Alice cannot use MAIL FROM bob', "{$code} {$text}")
                     : $r->finding('spoof.smtp-auth-mail-from-other', 'Authenticated Alice cannot use MAIL FROM bob', "ACCEPTED {$code} {$text}");
    });
    $unauth = static function () use ($config, $ip, $host, $ca): SmtpProbe {
        $smtp = SmtpProbe::connect($ip, $config->port('smtpUnauthenticatedProbe'), false, $host, $ca);
        $smtp->ehlo();
        $smtp->startTls($host, $ca);
        return $smtp;
    };
    $rec->run('relay.smtp-unauth-external-rejected', 'Unauthenticated SMTP cannot relay to external domain', function (CheckRecorder $r) use ($unauth, $external) {
        $smtp = $unauth();
        $smtp->command('MAIL FROM:<probe@spike-client.companyname.local>');
        [$code, $text] = $smtp->command("RCPT TO:<{$external}>");
        $smtp->quit();
        $r->assert($code >= 500, 'relay.smtp-unauth-external-rejected', 'Unauthenticated SMTP cannot relay to external domain', "{$code} {$text}");
    });
    $rec->run('relay.unknown-local-rejected', 'Unknown local recipient is rejected', function (CheckRecorder $r) use ($unauth, $config) {
        $smtp = $unauth();
        $smtp->command('MAIL FROM:<probe@spike-client.companyname.local>');
        [$code, $text] = $smtp->command('RCPT TO:<' . $config->address('nobody-here') . '>');
        $smtp->quit();
        $r->assert($code >= 500, 'relay.unknown-local-rejected', 'Unknown local recipient is rejected', "{$code} {$text}");
    });
    $rec->run('spoof.smtp-unauth-local-sender', 'Unauthenticated SMTP cannot deliver mail claiming a local sender', function (CheckRecorder $r) use ($unauth, $config, $runId) {
        $smtp = $unauth();
        [$c1, $t1] = $smtp->command('MAIL FROM:<' . $config->address('alice') . '>');
        [$c2, $t2] = $c1 < 400 ? $smtp->command('RCPT TO:<' . $config->address('mallory') . '>') : [$c1, $t1];
        $final = [$c2, $t2];
        if ($c2 < 400) {
            $final = $smtp->data(implode("\r\n", [
                'From: ' . $config->address('alice'),
                'To: ' . $config->address('mallory'),
                "Subject: [spike {$runId}] unauthenticated spoof probe",
                'Message-ID: <spoof-' . $runId . '@spike-client.companyname.local>',
                '',
                'If this is in Mallory\'s inbox, unauthenticated internal sender spoofing is possible.',
            ]));
        }
        $smtp->quit();
        $final[0] >= 400
            ? $r->pass('spoof.smtp-unauth-local-sender', 'Unauthenticated SMTP cannot deliver mail claiming a local sender', "{$final[0]} {$final[1]}")
            : $r->finding('spoof.smtp-unauth-local-sender', 'Unauthenticated SMTP cannot deliver mail claiming a local sender', "ACCEPTED {$final[0]} {$final[1]} -> production profile must not expose unauthenticated SMTP");
    });

    // ---- 6. Standard IMAP client interoperability -------------------------------------
    $rec->run('imap.bob-inbox', 'Bob can read his Inbox over IMAPS (standard clients)', function (CheckRecorder $r) use ($imapCount, &$state) {
        $count = $imapCount('bob');
        $state['bob.imapInboxCount'] = $count;
        $r->assert(is_int($count) && $count >= 1, 'imap.bob-inbox', 'Bob can read his Inbox over IMAPS (standard clients)', 'EXISTS=' . var_export($count, true));
    });
    $rec->run('imap.wrong-password', 'IMAP LOGIN with wrong password fails', function (CheckRecorder $r) use ($config, $ip, $host, $ca) {
        $imap = ImapProbe::connect($ip, $config->port('imaps'), $host, $ca);
        $login = $imap->login($config->address('bob'), 'wrong-password');
        $imap->logout();
        $r->assert(!$login['ok'], 'imap.wrong-password', 'IMAP LOGIN with wrong password fails', $login['status']);
    });

    spike_write_json($stateFile, $state);
} else {
    // ---- verify-persistence ------------------------------------------------------------
    $state = json_decode((string) @file_get_contents($stateFile), true);
    if (!is_array($state) || !isset($state['alice.sentEmailId'])) {
        fwrite(STDERR, "No state from a successful 'run' phase\n");
        exit(2);
    }
    $checks = [
        ['persist.alice-sent', $alice, 'alice.sentEmailId', $state['subject']],
        ['persist.bob-inbox', $bob, 'bob.receivedEmailId', $state['subject']],
        ['persist.alice-reply', $alice, 'alice.replyEmailId', 'Re: ' . $state['subject']],
    ];
    foreach ($checks as [$id, $box, $key, $subject]) {
        $rec->run($id, "after restart, {$key} still readable", function (CheckRecorder $r) use ($id, $box, $key, $subject, $state) {
            $msg = isset($state[$key]) ? $box->get($state[$key]) : null;
            $r->assert($msg !== null && $msg['subject'] === $subject, $id, "after restart, {$key} still readable", $msg === null ? 'missing' : 'subject matches');
        });
    }
    $rec->run('persist.read-state', "after restart, Bob's read flag is preserved", function (CheckRecorder $r) use ($bob, $state) {
        $msg = $bob->get($state['bob.receivedEmailId']);
        $r->assert(isset($msg['keywords']['$seen']), 'persist.read-state', "after restart, Bob's read flag is preserved");
    });
    $rec->run('persist.imap-count', 'after restart, IMAP Inbox count did not drop', function (CheckRecorder $r) use ($imapCount, $state) {
        $count = $imapCount('bob');
        $r->assert(is_int($count) && $count >= (int) $state['bob.imapInboxCount'], 'persist.imap-count', 'after restart, IMAP Inbox count did not drop', "before={$state['bob.imapInboxCount']} after=" . var_export($count, true));
    });
    $rec->run('persist.send-after-restart', 'mail flow still works after restart', function (CheckRecorder $r) use ($alice, $bob, $config, $state) {
        $subject = "[spike {$state['runId']}] post-restart check";
        $alice->send([$config->address('bob')], $subject, 'post-restart');
        $r->assert($bob->waitForSubject('inbox', $subject) !== null, 'persist.send-after-restart', 'mail flow still works after restart');
    });
}

spike_write_json($config->varDir("spike/results-{$phase}.json"), ['phase' => $phase, 'at' => gmdate('c'), 'checks' => $rec->all()]);
$counts = array_count_values(array_column($rec->all(), 'outcome'));
echo "\nSummary ({$phase}): " . json_encode($counts) . "\n";
exit($rec->hasFailures() ? 1 : 0);
