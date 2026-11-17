<?php

declare(strict_types=1);

namespace AtLocal\Spike\Mail;

use AtLocal\Spike\Jmap\JmapClient;
use AtLocal\Spike\Jmap\JmapMethodError;
use AtLocal\Spike\Jmap\SetError;
use AtLocal\Spike\Jmap\SetResult;

/**
 * Prototype of the atLocal MailProvider (RFC 8621 JMAP Mail + Submission).
 *
 * Authorisation model: the JmapClient carries ONE user's credential and the
 * accountId is taken from that user's own JMAP session. No method accepts an
 * accountId from the caller, so a UI cannot ask for another user's mailbox.
 */
final class UserMailbox
{
    private const USING = [JmapClient::CORE, JmapClient::MAIL, JmapClient::SUBMISSION];
    private const LIST_PROPERTIES = ['id', 'blobId', 'threadId', 'messageId', 'inReplyTo', 'subject', 'from', 'to', 'receivedAt', 'keywords', 'mailboxIds', 'preview'];

    private ?string $accountId = null;
    /** @var array<string, string>|null role => mailboxId */
    private ?array $roles = null;

    public function __construct(private readonly JmapClient $jmap, public readonly string $address)
    {
    }

    public function accountId(): string
    {
        return $this->accountId ??= $this->jmap->primaryAccountId(JmapClient::MAIL);
    }

    public function mailboxId(string $role): string
    {
        if ($this->roles === null) {
            $this->roles = [];
            $list = $this->jmap->invoke(self::USING, 'Mailbox/get', ['accountId' => $this->accountId(), 'ids' => null, 'properties' => ['id', 'role', 'name']])['list'];
            foreach ($list as $mailbox) {
                if (is_string($mailbox['role'] ?? null)) {
                    $this->roles[$mailbox['role']] = $mailbox['id'];
                }
            }
        }
        return $this->roles[$role] ?? throw new \RuntimeException("{$this->address}: no mailbox with role '{$role}'");
    }

    /**
     * Creates the message in Drafts and submits it in ONE JMAP request; on success the
     * server moves it to Sent. If submission fails, the draft stays in Drafts and an
     * exception is thrown: the caller must never report "sent".
     *
     * @param list<string> $to
     * @param list<string> $references
     * @return array{emailId: string, submissionId: string}
     */
    public function send(array $to, string $subject, string $text, ?string $inReplyTo = null, array $references = []): array
    {
        $accountId = $this->accountId();
        $drafts = $this->mailboxId('drafts');
        $sent = $this->mailboxId('sent');
        $email = [
            'mailboxIds' => [$drafts => true],
            'keywords' => ['$draft' => true, '$seen' => true],
            'from' => [['email' => $this->address]],
            'to' => array_map(static fn (string $a) => ['email' => $a], $to),
            'subject' => $subject,
            'bodyValues' => ['body' => ['value' => $text]],
            'textBody' => [['partId' => 'body', 'type' => 'text/plain']],
        ];
        if ($inReplyTo !== null) {
            $email['inReplyTo'] = [$inReplyTo];
            $email['references'] = array_values(array_unique([...$references, $inReplyTo]));
        }

        $responses = $this->jmap->call(self::USING, [
            ['Email/set', ['accountId' => $accountId, 'create' => ['draft' => $email]], 'e'],
            ['EmailSubmission/set', [
                'accountId' => $accountId,
                'create' => ['sub' => ['identityId' => $this->identityId(), 'emailId' => '#draft']],
                'onSuccessUpdateEmail' => ['#sub' => [
                    'mailboxIds/' . $drafts => null,
                    'mailboxIds/' . $sent => true,
                    'keywords/$draft' => null,
                ]],
            ], 's'],
        ]);
        $byId = [];
        foreach ($responses as [$name, $args, $callId]) {
            if ($name === 'error') {
                throw JmapMethodError::fromArguments($callId === 'e' ? 'Email/set' : 'EmailSubmission/set', $args);
            }
            $byId[$callId] ??= $args;
        }
        $created = SetResult::created('Email/set', $byId['e'] ?? [], 'draft');
        $submission = SetResult::created('EmailSubmission/set', $byId['s'] ?? [], 'sub');
        return ['emailId' => (string) $created['id'], 'submissionId' => (string) $submission['id']];
    }

    /** @return list<array<string, mixed>> newest first */
    public function list(string $role, int $limit = 20): array
    {
        $responses = $this->jmap->call(self::USING, [
            ['Email/query', [
                'accountId' => $this->accountId(),
                'filter' => ['inMailbox' => $this->mailboxId($role)],
                'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit' => $limit,
            ], 'q'],
            ['Email/get', [
                'accountId' => $this->accountId(),
                '#ids' => ['resultOf' => 'q', 'name' => 'Email/query', 'path' => '/ids'],
                'properties' => self::LIST_PROPERTIES,
            ], 'g'],
        ]);
        foreach ($responses as [$name, $args]) {
            if ($name === 'error') {
                throw JmapMethodError::fromArguments('Email/query+get', $args);
            }
            if ($name === 'Email/get') {
                return $args['list'];
            }
        }
        return [];
    }

    /** @return array<string, mixed>|null message including plain-text body */
    public function get(string $emailId): ?array
    {
        $args = $this->jmap->invoke(self::USING, 'Email/get', [
            'accountId' => $this->accountId(),
            'ids' => [$emailId],
            'properties' => [...self::LIST_PROPERTIES, 'references', 'bodyValues', 'textBody'],
            'fetchTextBodyValues' => true,
        ]);
        return $args['list'][0] ?? null;
    }

    /** Polls a mailbox for a subject; delivery is asynchronous. */
    public function waitForSubject(string $role, string $subject, float $timeoutSeconds = 15.0): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            foreach ($this->list($role, 50) as $message) {
                if (($message['subject'] ?? null) === $subject) {
                    return $message;
                }
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);
        return null;
    }

    public function markSeen(string $emailId): void
    {
        $response = $this->jmap->invoke(self::USING, 'Email/set', [
            'accountId' => $this->accountId(),
            'update' => [$emailId => ['keywords/$seen' => true]],
        ]);
        SetResult::updated('Email/set', $response, $emailId);
    }

    public static function bodyText(array $message): string
    {
        $parts = [];
        foreach ($message['textBody'] ?? [] as $part) {
            $parts[] = $message['bodyValues'][$part['partId']]['value'] ?? '';
        }
        return implode("\n", $parts);
    }

    private function identityId(): string
    {
        $list = $this->jmap->invoke(self::USING, 'Identity/get', ['accountId' => $this->accountId(), 'ids' => null])['list'];
        foreach ($list as $identity) {
            if (strcasecmp((string) ($identity['email'] ?? ''), $this->address) === 0) {
                return (string) $identity['id'];
            }
        }
        throw new SetError("{$this->address}: no submission identity for own address");
    }
}
