<?php

declare(strict_types=1);

namespace AtLocal\Spike\Http;

/** The mail engine could not be reached (network/TLS failure). Never treat as success. */
final class TransportException extends \RuntimeException
{
}
