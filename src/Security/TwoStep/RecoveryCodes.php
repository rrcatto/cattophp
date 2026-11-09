<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use CattoPHP\Security\KeyedHash;

/**
 * Two-step sign-in's recovery codes: eight of them, each XXXX-XXXX from an alphabet with nothing that reads as something
 * else (no 0 and O, no 1 and I), each working once. Only a keyed hash of each is kept, tied to its account, so the codes
 * are shown once, when they are made.
 */
final class RecoveryCodes
{
    public const COUNT = 8;
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const SHAPE = '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/';
    public const PURPOSE = 'two-step-recovery';

    /** @return list<string> */
    public static function make(): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[substr($code, 0, 4) . '-' . substr($code, 4)] = true;
        }

        return array_keys($codes);
    }

    /** What was typed, in the codes' own form, or null when it cannot be one: spaces, a missing hyphen and small letters allowed. */
    public static function normalise(string $typed): ?string
    {
        $plain = strtoupper((string) preg_replace('/[\s-]+/', '', $typed));
        if (strlen($plain) !== 8) {
            return null;
        }
        $code = substr($plain, 0, 4) . '-' . substr($plain, 4);

        return preg_match(self::SHAPE, $code) === 1 ? $code : null;
    }

    /** The keyed hash kept of a code, tied to its account. */
    public static function hash(KeyedHash $hash, int|string $account, string $code): string
    {
        return $hash->of(self::PURPOSE, $account . ':' . $code);
    }
}
