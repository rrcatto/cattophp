<?php

declare(strict_types=1);

namespace CattoPHP\Foundation;

use InvalidArgumentException;

/**
 * A permanent account reference for a person or an organisation: a prefix of 1 to 3 capital letters (the installation's
 * setting), the date the account was made as YYYYMMDD, a number of at least 4 digits that keeps rising across days, and
 * one check character. Stored without hyphens (CL202610160001K), shown as CL-20261016-0001-K, accepted typed with or
 * without hyphens and in either case.
 *
 * The check character is ISO/IEC 7064 MOD 37,36 over the prefix and digits: one of 0-9 or A-Z, and it catches every
 * single mistyped character and every swap of two neighbouring characters. A project's database may make the
 * references (so two accounts made at once never share a number); this class formats, reads and checks them, and a
 * database's check character must equal its. A typed O or I where the reference has digits is read as 0 or 1, because
 * they look alike.
 */
final class AccountReference
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const STORED = '/^([A-Z]{1,3})(\d{8})(\d{4,18})([0-9A-Z])$/';

    private function __construct()
    {
    }

    /** The check character for a prefix and its digits (ISO/IEC 7064 MOD 37,36). */
    public static function checkCharacter(string $body): string
    {
        $modulus = 36;
        $product = $modulus;
        foreach (str_split(strtoupper($body)) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) throw new InvalidArgumentException('An account reference holds only letters and digits.');
            $sum = ($product + $value) % $modulus;
            if ($sum === 0) $sum = $modulus;
            $product = ($sum * 2) % ($modulus + 1);
        }

        return self::ALPHABET[($modulus + 1 - $product) % $modulus];
    }

    /** CL-20261016-0001-K from CL202610160001K. */
    public static function format(string $stored): string
    {
        if (preg_match(self::STORED, $stored, $part) !== 1) return $stored;

        return $part[1] . '-' . $part[2] . '-' . $part[3] . '-' . $part[4];
    }

    /**
     * A typed reference in its stored form, checked: hyphens and spaces dropped, upper case, O and I in
     * the digit positions read as 0 and 1.
     */
    public static function parse(string $typed): string
    {
        $compact = strtoupper(preg_replace('/[\s\-]+/', '', $typed) ?? '');
        if (preg_match('/^([A-Z]{1,3})([0-9OI]{12,26})([0-9A-Z])$/', $compact, $part) !== 1) {
            throw new InvalidArgumentException('An account reference looks like CL-20261016-0001-K.');
        }
        $stored = $part[1] . strtr($part[2], ['O' => '0', 'I' => '1']) . $part[3];
        if (preg_match(self::STORED, $stored) !== 1 || self::checkCharacter(substr($stored, 0, -1)) !== substr($stored, -1)) {
            throw new InvalidArgumentException('That account reference has a mistake in it. Check each character.');
        }

        return $stored;
    }

    public static function isValid(string $typed): bool
    {
        try { self::parse($typed); return true; } catch (InvalidArgumentException) { return false; }
    }

    public static function validPrefix(string $prefix): bool
    {
        return preg_match('/^[A-Z]{1,3}$/', $prefix) === 1;
    }
}
