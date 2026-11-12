<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use RuntimeException;

/**
 * The transport could not be reached or is busy: the copies stay queued and the next run tries again. retryAfter is how
 * many seconds it asked to wait, when it said.
 */
final class TransportUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
