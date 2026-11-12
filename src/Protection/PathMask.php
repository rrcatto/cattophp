<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

/**
 * A page's address as the Site Log keeps it: no query string and no secrets. Anything that looks like a key (32 or more
 * hexadecimal or URL-safe characters together, holding a digit) is masked wherever it comes up; words joined by hyphens
 * are a record's name and are kept. A project names the paths whose next part is always a secret, such as an
 * unsubscribe link's token (`protection.secret_paths`: '/m/[a-z]/', '/join/').
 */
final class PathMask
{
    public const MASK = '••••';
    public const LONGEST = 500;

    private readonly ?string $secretPaths;

    /** @param list<string> $secretPaths each a path prefix ending in a slash, written as a regular expression's text */
    public function __construct(array $secretPaths = [])
    {
        $prefixes = array_values(array_filter(array_map(static fn(string $prefix): string => '/' . trim(trim($prefix), '/'), $secretPaths), static fn(string $prefix): bool => $prefix !== '/'));
        $this->secretPaths = $prefixes === [] ? null : '#^((?:' . implode('|', array_map(static fn(string $prefix): string => str_replace('#', '\#', $prefix), $prefixes)) . ')/)[^/.]+#';
    }

    /** The page asked for: no query string, no secrets. */
    public function masked(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') $path = '/';
        if ($this->secretPaths !== null) $path = (string) preg_replace($this->secretPaths, '$1' . self::MASK, $path);
        // A part such as module-12-practical-work-and-model-answers is a name: lower case, a hyphen between words, and a
        // letter after f, which no hexadecimal id or UUID holds.
        $path = (string) preg_replace_callback('#(?<=/)(?=[A-Za-z0-9_-]*[0-9])[A-Za-z0-9_-]{32,}#', static fn(array $key): string => self::isName($key[0]) ? $key[0] : self::MASK, $path);

        return mb_substr($path, 0, self::LONGEST);
    }

    private static function isName(string $segment): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/', $segment) === 1 && preg_match('/[g-z]/', $segment) === 1;
    }
}
