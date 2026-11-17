<?php

declare(strict_types=1);

namespace AtLocal\Spike\Config;

use RuntimeException;

/**
 * Generates and reads spike-only credentials in var/secrets/spike-secrets.json.
 * Values are random per checkout, never committed and never printed by the scripts.
 */
final class SecretStore
{
    public function __construct(private readonly string $file)
    {
    }

    /** @param list<string> $users */
    public function initialise(array $users): bool
    {
        if (is_file($this->file)) {
            return false;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create secrets directory {$dir}");
        }
        $secrets = [
            'recoveryAdmin' => ['user' => 'admin', 'password' => self::password()],
            'users' => [],
        ];
        foreach ($users as $user) {
            $secrets['users'][$user] = self::password();
        }
        $written = file_put_contents($this->file, json_encode($secrets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException("Cannot write {$this->file}");
        }
        @chmod($this->file, 0600);
        return true;
    }

    /** @return array{recoveryAdmin: array{user: string, password: string}, users: array<string, string>} */
    public function read(): array
    {
        $json = @file_get_contents($this->file);
        if ($json === false) {
            throw new RuntimeException("Secrets not initialised; run spike/php/bin/init.php first ({$this->file})");
        }
        return json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    }

    public function userPassword(string $user): string
    {
        $pw = $this->read()['users'][$user] ?? null;
        if (!is_string($pw)) {
            throw new RuntimeException("No generated credential for user '{$user}'");
        }
        return $pw;
    }

    private static function password(): string
    {
        // 24 random bytes -> 32 URL-safe chars; avoids ':' so it is safe in user:password env values.
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
