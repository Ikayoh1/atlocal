<?php

declare(strict_types=1);

namespace AtLocal\Spike\Jmap;

/** Interprets a Foo/set response and refuses to report success after a partial failure. */
final class SetResult
{
    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed> created object (server-set properties, including id)
     */
    public static function created(string $method, array $response, string $creationId): array
    {
        if (isset($response['notCreated'][$creationId])) {
            throw self::failure($method, 'create', $creationId, $response['notCreated'][$creationId]);
        }
        $created = $response['created'][$creationId] ?? null;
        if (!is_array($created) || !isset($created['id'])) {
            throw new JmapException("{$method}: no created entry for {$creationId}");
        }
        return $created;
    }

    /** @param array<string, mixed> $response */
    public static function updated(string $method, array $response, string $id): void
    {
        if (isset($response['notUpdated'][$id])) {
            throw self::failure($method, 'update', $id, $response['notUpdated'][$id]);
        }
        if (!array_key_exists($id, $response['updated'] ?? [])) {
            throw new JmapException("{$method}: update of {$id} not confirmed");
        }
    }

    /** @param mixed $error */
    private static function failure(string $method, string $op, string $id, mixed $error): SetError
    {
        $type = is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : 'unknown';
        $desc = is_array($error) && is_string($error['description'] ?? null) ? $error['description'] : '';
        $props = is_array($error) && is_array($error['properties'] ?? null) ? implode(',', $error['properties']) : '';
        $e = new SetError(trim("{$method} {$op} {$id} rejected: {$type} {$desc}" . ($props !== '' ? " [properties: {$props}]" : '')));
        $e->errorType = $type;
        return $e;
    }
}
