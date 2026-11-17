<?php

declare(strict_types=1);

namespace AtLocal\Spike\Http;

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }
}
