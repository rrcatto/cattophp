<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use CattoPHP\Installation\Installation;
use DateTimeImmutable;
use Exception;

/**
 * The details of an activity log entry as lines a person reads, for a page that lists the log: each "Name: value", the
 * name and the value worded by the project's DetailWords where it has words for them, else by the log's own wording.
 *
 * The log's own wording: a key is turned into words (due_at is "Due at", needs_repair is "Needs repair"); an ISO 8601 date
 * is "7 November 2026" and an ISO 8601 time is "7 November 2026 at 07:01", in the installation's time zone; true and
 * false are yes and no; a list of words is one line with commas; anything else as JSON; a long value cut short. A detail
 * with no value is left out. A secret is never in the log (Redactor), so nothing here hides one.
 */
final class ActivityDetails
{
    /** The longest value shown, in characters. */
    private const LONGEST = 160;

    /** A key: lower-case letters and digits joined by underscores, at least two parts (needs_repair). */
    private const KEY = '/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/';

    /** An ISO 8601 date, or a date and time with or without seconds, fractions and an offset. */
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const TIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /** @var list<DetailWords> */
    private readonly array $words;

    /** @param iterable<DetailWords> $words the project's own wording */
    public function __construct(private readonly Installation $installation, iterable $words = [])
    {
        $this->words = is_array($words) ? array_values($words) : iterator_to_array($words, false);
    }

    /**
     * Each detail of an entry for $action as a line: its name, then its value.
     *
     * @param array<string,mixed> $details
     * @return list<string>
     */
    public function lines(string $action, array $details): array
    {
        $lines = [];
        foreach ($details as $key => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }
            $key = (string) $key;
            $text = trim((string) preg_replace('/\s+/u', ' ', $this->value($action, $key, $value)));
            $lines[] = $this->label($action, $key) . ': ' . (mb_strlen($text) > self::LONGEST ? mb_substr($text, 0, self::LONGEST - 3) . '...' : $text);
        }

        return $lines;
    }

    /** A detail's name in words: the project's, else the key turned into words. */
    public function label(string $action, string $key): string
    {
        foreach ($this->words as $words) {
            $said = $words->label($action, $key);
            if ($said !== null && trim($said) !== '') {
                return trim($said);
            }
        }

        return self::sentence($key);
    }

    /** A detail's value in words: the project's, else the log's own wording. */
    public function value(string $action, string $key, mixed $value): string
    {
        foreach ($this->words as $words) {
            $said = $words->value($action, $key, $value);
            if ($said !== null) {
                return $said;
            }
        }

        return $this->worded($value);
    }

    /** A key in words, its first letter a capital: due_at is "Due at"; anything else as it is. */
    public static function sentence(string $key): string
    {
        $words = trim(str_replace('_', ' ', $key));

        return $words === '' ? $key : mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1);
    }

    private function worded(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_string($value)) {
            return $this->moment($value) ?? (preg_match(self::KEY, $value) === 1 ? self::sentence($value) : $value);
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value) && array_is_list($value) && array_filter($value, static fn (mixed $item): bool => !is_scalar($item)) === []) {
            return implode(', ', array_map(fn (mixed $item): string => $this->worded($item), $value));
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** An ISO 8601 date or time in words, in the installation's time zone; null when it is not one. */
    private function moment(string $value): ?string
    {
        $date = preg_match(self::DATE, $value) === 1;
        if (!$date && preg_match(self::TIME, $value) !== 1) {
            return null;
        }
        try {
            // A date alone is a day, whatever the time zone; a time without an offset is the installation's own.
            $moment = new DateTimeImmutable($value, $this->installation->timeZone());
        } catch (Exception) {
            return null;
        }

        return $date ? $moment->format('j F Y') : $this->installation->localTime($moment)->format('j F Y \a\t H:i');
    }
}
