<?php

declare(strict_types=1);

namespace AtLocal\Spike\Jmap;

/** A Foo/set create/update/destroy was rejected (notCreated/notUpdated/notDestroyed). */
final class SetError extends JmapException
{
    public string $errorType = 'unknown';
}
