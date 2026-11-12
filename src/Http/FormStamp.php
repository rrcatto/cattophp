<?php

declare(strict_types=1);

namespace CattoPHP\Http;

/**
 * When a public form was opened, signed with the application key so a browser cannot make it up: the form carries the
 * stamp in a hidden field, and the seconds it was open are known when it is sent, without trusting the browser. People
 * take longer than a script. The sign-up check and the contact form use the same signature.
 */
final class FormStamp
{
    public function __construct(private readonly string $applicationKey)
    {
    }

    /** When the form was opened, signed. */
    public function stamp(?int $now = null): string
    {
        $now ??= time();

        return $now . '.' . $this->signature((string) $now);
    }

    /** The seconds the form was open, or null without a genuine stamp, or one from the future or older than a day. */
    public function secondsOpen(string $stamp, ?int $now = null): ?int
    {
        if (preg_match('/^(\d{9,11})\.([0-9a-f]{16})$/', trim($stamp), $match) !== 1) {
            return null;
        }
        if (!hash_equals($this->signature($match[1]), $match[2])) {
            return null;
        }
        $seconds = ($now ?? time()) - (int) $match[1];

        return $seconds >= 0 && $seconds < 86400 ? $seconds : null;
    }

    private function signature(string $time): string
    {
        return substr(hash_hmac('sha256', 'signup:' . $time, $this->applicationKey), 0, 16);
    }
}
