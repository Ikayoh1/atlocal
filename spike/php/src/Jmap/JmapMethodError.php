<?php

declare(strict_types=1);

namespace AtLocal\Spike\Jmap;

/** A JMAP method-level error response, e.g. ["error", {"type": "accountNotFound"}, "c1"]. */
final class JmapMethodError extends JmapException
{
    public string $errorType = 'unknown';

    /** @param array<string, mixed> $arguments */
    public static function fromArguments(string $method, array $arguments): self
    {
        $type = is_string($arguments['type'] ?? null) ? $arguments['type'] : 'unknown';
        $description = is_string($arguments['description'] ?? null) ? ': ' . $arguments['description'] : '';
        $e = new self("{$method} failed with {$type}{$description}");
        $e->errorType = $type;
        return $e;
    }
}
