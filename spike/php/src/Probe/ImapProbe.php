<?php

declare(strict_types=1);

namespace AtLocal\Spike\Probe;

/** Minimal IMAP4rev1/rev2 client to prove standard-client interoperability. */
final class ImapProbe
{
    private int $tag = 0;

    private function __construct(private readonly LineConnection $conn)
    {
    }

    public static function connect(string $ip, int $port, string $peerName, string $caFile): self
    {
        $probe = new self(LineConnection::open($ip, $port, true, $peerName, $caFile));
        $greeting = $probe->conn->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new \RuntimeException("IMAP greeting: {$greeting}");
        }
        return $probe;
    }

    /** @return array{ok: bool, status: string, untagged: list<string>} */
    public function command(string $command): array
    {
        $tag = 'a' . (++$this->tag);
        $this->conn->write("{$tag} {$command}");
        $untagged = [];
        while (true) {
            $line = $this->conn->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                $status = substr($line, strlen($tag) + 1);
                return ['ok' => str_starts_with($status, 'OK'), 'status' => $status, 'untagged' => $untagged];
            }
            $untagged[] = $line;
        }
    }

    public function login(string $user, string $password): array
    {
        return $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
    }

    /** @return int|null message count from "* N EXISTS" */
    public function selectCount(string $mailbox): ?int
    {
        $r = $this->command('SELECT ' . self::quote($mailbox));
        if (!$r['ok']) {
            return null;
        }
        foreach ($r['untagged'] as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\RuntimeException) {
        }
        $this->conn->close();
    }

    private static function quote(string $s): string
    {
        return '"' . addcslashes($s, "\\\"") . '"';
    }
}
