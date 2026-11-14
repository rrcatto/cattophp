<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * How the Campaign Marketing Manager's services read and write the database's values: instants with their offset, dates,
 * true and false as PostgreSQL gives them, a search made safe for ILIKE, and a short reference for a record that has no
 * code of its own (an advert, a cost), so no address ever carries a row number.
 */
final class Rows
{
    /** The letters a reference is made of: no 0, 1, i, l or o, so it can be read aloud. */
    private const REFERENCE_LETTERS = 'abcdefghjkmnpqrstuvwxyz23456789';

    private function __construct()
    {
    }

    public static function instant(mixed $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->setTimezone($zone);
    }

    public static function date(mixed $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), $zone);

        return $date === false ? null : $date;
    }

    /** An instant as the database is given it, with its offset, so the connection's own time zone never matters. */
    public static function sql(?DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d H:i:s.uP');
    }

    public static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function nullableText(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** A part of a word to look for with ILIKE, its wildcards taken as themselves. */
    public static function like(string $search): string
    {
        return '%' . addcslashes($search, '%_\\') . '%';
    }

    /** A new random reference of readable letters and digits, eight unless more are asked for (a touch's has ten). */
    public static function reference(int $length = 8): string
    {
        $reference = '';
        for ($i = 0; $i < max(1, $length); $i++) {
            $reference .= self::REFERENCE_LETTERS[random_int(0, strlen(self::REFERENCE_LETTERS) - 1)];
        }

        return $reference;
    }

    /** What a person typed, trimmed, its inner runs of white space kept. */
    public static function typed(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** A code made from a name: small letters and digits joined by hyphens, as long as allowed at most. */
    public static function slug(string $name, int $longest): string
    {
        // Letters with accents lose them (é to e) where PHP's intl is there; anything else not a letter or digit is a hyphen.
        $ascii = class_exists(\Normalizer::class) ? (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_D)) : $name;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return rtrim(substr($slug, 0, $longest), '-');
    }
}
