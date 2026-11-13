<?php

declare(strict_types=1);

namespace CattoPHP\Document;

use CattoPHP\Installation\Installation;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use NumberFormatter;
use Throwable;

/**
 * Turns one placeholder's value into HTML according to its kind, and decides whether a value is
 * present for {{#if}}. Every value is escaped; the only markup in the output is the line breaks the
 * multiline and address kinds produce themselves. Money is formatted from integer minor units, never from a float, in
 * the installation's locale (APP_LOCALE, a setting: Installation::locale(), en_GB when it names none) by intl's
 * NumberFormatter, or as the code and the amount without intl. Dates are shown as "6 October 2026" in the installation's time zone.
 */
final class ValueFormatter
{
    public const DATE_FORMAT = 'j F Y';

    private readonly DateTimeZone $zone;

    /** @param string|null $locale the locale amounts are written in; null asks the installation each time (APP_LOCALE) */
    public function __construct(?DateTimeZone $zone = null, private readonly ?string $locale = null, private readonly ?Installation $installation = null)
    {
        $this->zone = $zone ?? new DateTimeZone(date_default_timezone_get());
    }

    /** Whether a supplied value has the shape its kind needs; null when it does. */
    public function problem(PlaceholderDefinition $definition, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $ok = match ($definition->kind) {
            PlaceholderKind::Text, PlaceholderKind::Multiline => is_string($value) || is_int($value),
            PlaceholderKind::Money => is_array($value) && is_int($value['minor'] ?? null) && is_string($value['currency'] ?? null)
                && preg_match('/^[A-Z]{3}$/D', $value['currency']) === 1,
            PlaceholderKind::Date => $value instanceof DateTimeInterface || (is_string($value) && self::date($value) !== null),
            PlaceholderKind::Address => is_array($value) && array_is_list($value) && array_reduce($value, static fn(bool $ok, mixed $line): bool => $ok && is_string($line), true),
            PlaceholderKind::Url => is_string($value) && ($value === '' || preg_match('#^https?://[^\s<>"]+$#iD', $value) === 1),
            PlaceholderKind::Integer => is_int($value),
            PlaceholderKind::Percent => is_string($value) && preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value) === 1,
        };
        return $ok ? null : $definition->name . ' must be ' . self::expected($definition->kind) . '.';
    }

    /** A value counts as present when there is something to show: not null, blank, empty or zero money. */
    public function present(PlaceholderDefinition $definition, mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            $definition->kind === PlaceholderKind::Money => is_array($value) && (int) ($value['minor'] ?? 0) !== 0,
            $definition->kind === PlaceholderKind::Address => is_array($value) && array_filter($value, static fn(mixed $line): bool => trim((string) $line) !== '') !== [],
            is_string($value) => trim($value) !== '',
            default => true,
        };
    }

    public function format(PlaceholderDefinition $definition, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return match ($definition->kind) {
            PlaceholderKind::Text, PlaceholderKind::Url => self::escape((string) $value),
            PlaceholderKind::Multiline => nl2br(self::escape(str_replace("\r\n", "\n", (string) $value)), false),
            PlaceholderKind::Money => self::escape($this->money((int) $value['minor'], (string) $value['currency'])),
            PlaceholderKind::Date => self::escape(($value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : self::date((string) $value))?->setTimezone($this->zone)->format(self::DATE_FORMAT) ?? ''),
            PlaceholderKind::Address => implode('<br>', array_map(self::escape(...), array_values(array_filter(array_map('trim', (array) $value), static fn(string $line): bool => $line !== '')))),
            PlaceholderKind::Integer => (string) (int) $value,
            PlaceholderKind::Percent => self::escape((string) $value) . '%',
        };
    }

    /** An amount in minor units as a reader of this installation sees it, such as R 1 200,00. */
    public function money(int $minor, string $currency): string
    {
        $locale = $this->locale ?? $this->installation?->locale() ?? Installation::LOCALE;
        $locale = $locale !== '' ? $locale : Installation::LOCALE;
        $major = intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
        if (!class_exists(NumberFormatter::class)) {
            return $currency . ' ' . number_format($minor / 100, 2, '.', ',');
        }
        $formatted = (new NumberFormatter($locale, NumberFormatter::CURRENCY))->formatCurrency($minor / 100, $currency);

        return $formatted === false ? $currency . ' ' . $major : $formatted;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ][0-9:.]+(?:Z|[+-]\d{2}(?::?\d{2})?)?)?$/D', $value) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function expected(PlaceholderKind $kind): string
    {
        return match ($kind) {
            PlaceholderKind::Text, PlaceholderKind::Multiline => 'text',
            PlaceholderKind::Money => 'an amount: ["minor" => integer minor units, "currency" => three-letter code]',
            PlaceholderKind::Date => 'a date (DateTimeInterface or YYYY-MM-DD)',
            PlaceholderKind::Address => 'a list of address lines',
            PlaceholderKind::Url => 'an http(s) address',
            PlaceholderKind::Integer => 'a whole number',
            PlaceholderKind::Percent => 'a decimal string such as "84.50"',
        };
    }
}
