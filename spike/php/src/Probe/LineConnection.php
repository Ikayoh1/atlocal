<?php

declare(strict_types=1);

namespace AtLocal\Spike\Probe;

use RuntimeException;

/** Line-oriented TCP/TLS connection with verified TLS against the spike CA. */
final class LineConnection
{
    /** @var resource */
    private $stream;

    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function open(string $ip, int $port, bool $implicitTls, string $peerName, string $caFile, float $timeout = 10.0): self
    {
        $context = stream_context_create(['ssl' => self::sslOptions($peerName, $caFile)]);
        $scheme = $implicitTls ? 'tls' : 'tcp';
        $stream = @stream_socket_client("{$scheme}://{$ip}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException("Connect {$scheme}://{$ip}:{$port} failed: [{$errno}] {$errstr}");
        }
        stream_set_timeout($stream, (int) ceil($timeout));
        return new self($stream);
    }

    public function startTls(string $peerName, string $caFile): void
    {
        foreach (self::sslOptions($peerName, $caFile) as $k => $v) {
            stream_context_set_option($this->stream, 'ssl', $k, $v);
        }
        if (@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
            throw new RuntimeException('STARTTLS handshake failed');
        }
    }

    public function write(string $line): void
    {
        if (fwrite($this->stream, $line . "\r\n") === false) {
            throw new RuntimeException('Write failed');
        }
    }

    public function readLine(): string
    {
        $line = fgets($this->stream, 8192);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new RuntimeException($meta['timed_out'] ? 'Read timed out' : 'Connection closed');
        }
        return rtrim($line, "\r\n");
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private static function sslOptions(string $peerName, string $caFile): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peerName,
            'cafile' => $caFile,
            'SNI_enabled' => true,
        ];
    }
}
