<?php

declare(strict_types=1);

namespace CattoPHP\SouthAfrica;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A South African ID number, checked as well formed: thirteen digits (spaces and hyphens as people type them are fine), a
 * real date of birth in the first six (the century that makes the person no older than 120 and already born), the
 * citizenship digit 0 (a citizen) or 1 (a permanent resident), and the Luhn checksum. Well formed is not proven to be the
 * typist's: only Home Affairs or an identity check can show that. Pages show it masked; a keyed fingerprint (the project's
 * key) finds another account with the same number without keeping the number in plain text.
 */
final class IdNumber
{
    public const ENTER = 'Enter your 13-digit South African ID number.';
    public const INVALID = 'That is not a valid South African ID number. Check each digit and try again.';

    private function __construct(public readonly string $digits, public readonly string $birthdate)
    {
    }

    /**
     * The number as a person types it, checked.
     *
     * @throws InvalidArgumentException in plain words
     */
    public static function fromInput(mixed $value, DateTimeImmutable $today): self
    {
        $digits = (string) preg_replace('/[\s-]+/', '', is_scalar($value) ? (string) $value : '');
        if (preg_match('/^\d{13}$/', $digits) !== 1) throw new InvalidArgumentException(self::ENTER);
        $born = self::birthdateOf($digits, $today);
        if ($born === null || !in_array($digits[10], ['0', '1'], true) || !self::checksumIsRight($digits)) throw new InvalidArgumentException(self::INVALID);

        return new self($digits, $born);
    }

    /** Whether the person is a South African citizen (0) rather than a permanent resident (1). */
    public function isCitizen(): bool
    {
        return $this->digits[10] === '0';
    }

    /** The keyed fingerprint (HMAC-SHA256 with the project's key) that finds another account with the same number. */
    public function fingerprint(string $key): string
    {
        return hash_hmac('sha256', 'sa-id:' . $this->digits, $key);
    }

    /** The number as pages show it: the date part, then stars. */
    public static function masked(string $digits): string
    {
        return strlen($digits) === 13 ? substr($digits, 0, 6) . ' **** ***' : '';
    }

    /**
     * A valid number for someone born on that day (test people), with its checksum.
     *
     * @param int $sequence 0 to 9999: below 5000 is how a woman's number is written, 5000 and above a man's
     */
    public static function make(DateTimeImmutable $born, int $sequence, bool $citizen = true): string
    {
        $first12 = $born->format('ymd') . str_pad((string) max(0, min(9999, $sequence)), 4, '0', STR_PAD_LEFT) . ($citizen ? '0' : '1') . '8';
        for ($check = 0; $check <= 9; $check++) {
            if (self::checksumIsRight($first12 . $check)) return $first12 . $check;
        }

        throw new \LogicException('No check digit fits.');
    }

    /** The YYYY-MM-DD the first six digits give: the century that makes the person no older than 120 and not yet born. */
    private static function birthdateOf(string $digits, DateTimeImmutable $today): ?string
    {
        $year = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        $century = $year + 2000 > (int) $today->format('Y') ? 1900 : 2000;
        if (!checkdate($month, $day, $century + $year)) return null;
        $born = sprintf('%04d-%02d-%02d', $century + $year, $month, $day);
        $date = new DateTimeImmutable($born);
        if ($date > $today->setTime(0, 0) || $date < $today->setTime(0, 0)->modify('-120 years')) return null;

        return $born;
    }

    /** The Luhn formula over all 13 digits, the check digit last. */
    private static function checksumIsRight(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
