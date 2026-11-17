<?php

declare(strict_types=1);

namespace AtLocal\Spike\Probe;

/**
 * Raw SMTP client used ONLY for negative tests (relay rejection, sender spoofing).
 * Returns server replies verbatim so the spike records actual engine behaviour.
 */
final class SmtpProbe
{
    private function __construct(private readonly LineConnection $conn)
    {
    }

    public static function connect(string $ip, int $port, bool $implicitTls, string $peerName, string $caFile): self
    {
        $probe = new self(LineConnection::open($ip, $port, $implicitTls, $peerName, $caFile));
        $probe->expect($probe->reply(), 220, 'greeting');
        return $probe;
    }

    /** @return array{0: int, 1: string} */
    public function command(string $line): array
    {
        $this->conn->write($line);
        return $this->reply();
    }

    public function ehlo(string $name = 'spike-client.companyname.local'): array
    {
        return $this->expect($this->command("EHLO {$name}"), 250, 'EHLO');
    }

    public function startTls(string $peerName, string $caFile): void
    {
        $this->expect($this->command('STARTTLS'), 220, 'STARTTLS');
        $this->conn->startTls($peerName, $caFile);
        $this->ehlo();
    }

    /** @return array{0: int, 1: string} */
    public function authPlain(string $user, string $password): array
    {
        return $this->command('AUTH PLAIN ' . base64_encode("\0{$user}\0{$password}"));
    }

    /**
     * Sends DATA and returns the final reply. Lines are dot-stuffed.
     * @return array{0: int, 1: string}
     */
    public function data(string $rfc822): array
    {
        [$code, $text] = $this->command('DATA');
        if ($code !== 354) {
            return [$code, $text];
        }
        foreach (preg_split("/\r\n|\n/", $rfc822) as $line) {
            $this->conn->write(str_starts_with($line, '.') ? '.' . $line : $line);
        }
        return $this->command('.');
    }

    public function quit(): void
    {
        try {
            $this->command('QUIT');
        } catch (\RuntimeException) {
            // connection may already be closed by the server after a rejection
        }
        $this->conn->close();
    }

    /** @return array{0: int, 1: string} */
    private function reply(): array
    {
        $lines = [];
        do {
            $line = $this->conn->readLine();
            $lines[] = substr($line, 4);
        } while (strlen($line) >= 4 && $line[3] === '-');
        return [(int) substr($line, 0, 3), implode(' | ', $lines)];
    }

    private function expect(array $reply, int $code, string $step): array
    {
        if ($reply[0] !== $code) {
            throw new \RuntimeException("SMTP {$step}: expected {$code}, got {$reply[0]} {$reply[1]}");
        }
        return $reply;
    }
}
