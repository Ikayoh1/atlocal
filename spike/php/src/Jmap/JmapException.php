<?php

declare(strict_types=1);

namespace AtLocal\Spike\Jmap;

/** Request-level JMAP failure (HTTP status / RFC 7807 problem details). */
class JmapException extends \RuntimeException
{
    public ?int $httpStatus = null;
    public ?string $problemType = null;

    public static function fromHttp(int $status, string $body, string $context): self
    {
        $problem = json_decode($body, true);
        $type = is_array($problem) ? ($problem['type'] ?? null) : null;
        $detail = is_array($problem) ? ($problem['detail'] ?? $problem['title'] ?? '') : '';
        // Do not echo arbitrary response bodies (could contain message content) into logs.
        $e = new self(sprintf('JMAP %s request failed: HTTP %d%s', $context, $status, $detail !== '' ? " ({$detail})" : ''));
        $e->httpStatus = $status;
        $e->problemType = is_string($type) ? $type : null;
        return $e;
    }
}
