<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use CattoPHP\Foundation\Base32;

/**
 * The one-time code of two-step sign-in: RFC 6238 as every authenticator app makes it, SHA1, 30 seconds, 6 digits, a
 * phone's clock allowed one step either way. Only a step later than the last one used matches, so a code used once
 * cannot be used again: the caller keeps the last step.
 */
final class OneTimeCode
{
    public const STEP = 30;
    public const DIGITS = 6;

    /** A new key: 20 random bytes, as 32 Base32 letters. */
    public static function newSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    /** What an authenticator app reads from the QR code: the key, and the names it lists the account under. */
    public static function appAddress(string $secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?'
            . http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP], '', '&', PHP_QUERY_RFC3986);
    }

    /** The key in groups of four, as a person types it. */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /** The code for a moment, in seconds since 1970. */
    public static function codeAt(string $secret, int $time): string
    {
        return self::forCounter(Base32::decode($secret), intdiv($time, self::STEP));
    }

    public static function forCounter(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The step the code matches, allowing a phone's clock to be half a minute out, later than $notBefore, or null. */
    public static function matchingCounter(string $secret, string $code, int $time, int $notBefore = 0): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $key = Base32::decode($secret);
        $now = intdiv($time, self::STEP);
        for ($counter = $now - 1; $counter <= $now + 1; $counter++) {
            if ($counter > $notBefore && hash_equals(self::forCounter($key, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }
}
