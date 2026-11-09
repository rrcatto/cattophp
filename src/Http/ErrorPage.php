<?php

declare(strict_types=1);

namespace CattoPHP\Http;

/**
 * What an error page says: its kind (not_found, forbidden, broken or other), its title, the status, the message in plain
 * words, the request's reference for the log, and the address to try again (a GET's own; empty otherwise).
 */
final class ErrorPage
{
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly int $status,
        public readonly string $message,
        public readonly string $reference,
        public readonly string $retryUrl,
    ) {
    }
}
