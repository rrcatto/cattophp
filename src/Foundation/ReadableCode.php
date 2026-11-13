<?php

declare(strict_types=1);

namespace CattoPHP\Foundation;

use InvalidArgumentException;

/**
 * A short code a person can read out, write down and type back, such as a loan slip's or a tag's: lower-case letters and
 * digits without the ones that look alike (no 0 or o, no 1, l or i), chosen at random by random_int, 8 characters unless
 * asked for another length. 31 characters to choose from give 8 characters about 39.6 bits, so a code is not a secret and
 * not certain to be unique: a project stores it with a unique index and makes another when one is taken.
 *
 *     ReadableCode::make();      // such as "k7qxm2pa"
 *     ReadableCode::make(6);     // such as "h4rw9c"
 *     ReadableCode::read(' K7QX-M2PA ');  // "k7qxm2pa": typed in capitals, with spaces or hyphens, read back
 */
final class ReadableCode
{
    /** The characters a code is made of: a to z without i, l and o, and 2 to 9. */
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** A code's characters in a regular expression, such as a route's requirement: [a-hjkmnp-z2-9]. */
    public const CHARACTER = '[a-hjkmnp-z2-9]';

    /** The shortest and the longest code made. */
    public const SHORTEST = 4;
    public const LONGEST = 64;

    private function __construct()
    {
    }

    /** A new code of $length characters, from 4 to 64. */
    public static function make(int $length = 8): string
    {
        if ($length < self::SHORTEST || $length > self::LONGEST) {
            throw new InvalidArgumentException(sprintf('A readable code has %d to %d characters: %d is not that.', self::SHORTEST, self::LONGEST, $length));
        }
        $code = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * A code as a person typed it, read back: in lower case, without spaces and hyphens; null when it holds anything a
     * code never does, or is not $length characters long (any length from 4 to 64 when null).
     */
    public static function read(string $typed, ?int $length = 8): ?string
    {
        $code = strtolower((string) preg_replace('/[\s-]+/', '', $typed));
        $size = $length === null ? self::SHORTEST . ',' . self::LONGEST : (string) $length;

        return preg_match('/^' . self::CHARACTER . '{' . $size . '}$/', $code) === 1 ? $code : null;
    }
}
