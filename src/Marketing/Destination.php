<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * Where a tracking link leads: a path on the site (/, /offers/spring), or, typed by a person who manages campaigns, a
 * whole address on another site (https://example.com/page). Nothing else is ever forwarded to, so a tracking link can
 * never be made to send someone somewhere nobody chose.
 */
final class Destination
{
    public const LONGEST = 2000;

    private function __construct()
    {
    }

    /** What was typed, trimmed, with an empty one the site's front page. */
    public static function normalise(string $typed): string
    {
        $typed = trim($typed);

        return $typed === '' ? '/' : $typed;
    }

    /** Why a destination as typed cannot be used, or null when it can. */
    public static function problem(string $typed): ?string
    {
        $destination = self::normalise($typed);
        if (strlen($destination) > self::LONGEST || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $destination) === 1) {
            return 'Type a path on the site, such as /offers, or a whole address starting with https://, with no spaces.';
        }
        if (self::isPath($destination) || self::isOutside($destination)) {
            return null;
        }

        return 'Type a path on the site starting with /, such as /offers, or a whole address starting with https://.';
    }

    /** A path on the site: one slash first, never two (which a browser reads as another site). */
    public static function isPath(string $destination): bool
    {
        return str_starts_with($destination, '/') && !str_starts_with($destination, '//');
    }

    /** A whole address on another site, http or https, with a host. */
    public static function isOutside(string $destination): bool
    {
        $parts = parse_url($destination);
        if ($parts === false) {
            return false;
        }

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '' && !isset($parts['user']) && !isset($parts['pass']);
    }
}
