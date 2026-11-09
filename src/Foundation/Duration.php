<?php

declare(strict_types=1);

namespace CattoPHP\Foundation;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A length of time, written one way everywhere a person types one (a setting, a lifetime in .env): a whole number and
 * a letter for each unit, largest first, no spaces: y years, w weeks, d days, h hours, m minutes (never months),
 * s seconds, such as 3y, 4w2d, 1h30m or 90s. Stated to a person in words ("1 hour 30 minutes"), never abbreviated.
 * A year after a date is a calendar year; as a fixed length (a lifetime, an expiry) a year is 365 days.
 */
final class Duration
{
    /** How to write a duration, for every field and setting that takes one. */
    public const HOW = 'Write a length of time with y, w, d, h, m and s in that order, such as 3y, 4w2d, 1h30m or 90s (m is minutes).';

    private const PATTERN = '/^(?:(\d{1,3})y)?(?:(\d{1,4})w)?(?:(\d{1,5})d)?(?:(\d{1,6})h)?(?:(\d{1,7})m)?(?:(\d{1,9})s)?$/i';

    /** The units, largest first, with their fixed lengths in seconds (a year as 365 days). */
    private const UNITS = ['year' => 31536000, 'week' => 604800, 'day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1];

    private function __construct(
        public readonly string $text,
        public readonly int $years,
        public readonly int $weeks,
        public readonly int $days,
        public readonly int $hours,
        public readonly int $minutes = 0,
        public readonly int $seconds = 0,
    ) {
    }

    /** Reads a duration as typed, or explains how to write one. */
    public static function parse(string $text): self
    {
        $trimmed = trim($text);
        if ($trimmed === '' || preg_match(self::PATTERN, $trimmed, $match) !== 1 || $match[0] === '') {
            throw new InvalidArgumentException(self::HOW);
        }
        $parts = [];
        foreach ([1, 2, 3, 4, 5, 6] as $index) {
            $value = $match[$index] ?? '';
            if ($value === '') {
                $parts[] = 0;
                continue;
            }
            if ((int) $value < 1) {
                throw new InvalidArgumentException('Each part of a length of time is a whole number from 1 up, such as 3y, 4w2d or 1h30m.');
            }
            $parts[] = (int) $value;
        }
        if (array_sum($parts) === 0) {
            throw new InvalidArgumentException(self::HOW);
        }

        return new self($trimmed, ...$parts);
    }

    public static function isValid(string $text): bool
    {
        try {
            self::parse($text);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * A length given in seconds, in the largest units that state it exactly: 1800 is 30m, 86400 is 1d, 5400 is 1h30m,
     * 31536000 (365 days) is 1y.
     */
    public static function ofSeconds(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('A length of time is at least one second.');
        }
        $parts = [];
        $text = '';
        $left = $seconds;
        foreach (self::UNITS as $unit => $length) {
            $count = intdiv($left, $length);
            $left -= $count * $length;
            $parts[] = $count;
            if ($count > 0) {
                $text .= $count . $unit[0];
            }
        }

        return new self($text, ...$parts);
    }

    /** "3 years", "4 weeks 2 days", "1 hour 30 minutes", "90 seconds". */
    public function words(): string
    {
        $words = [];
        foreach ($this->counts() as $unit => $count) {
            if ($count > 0) {
                $words[] = $count . ' ' . $unit . ($count === 1 ? '' : 's');
            }
        }

        return implode(' ', $words);
    }

    /** The fixed length in seconds, a year counted as 365 days (a lifetime in .env, a link's expiry). */
    public function lengthInSeconds(): int
    {
        $total = 0;
        foreach ($this->counts() as $unit => $count) {
            $total += $count * self::UNITS[$unit];
        }

        return $total;
    }

    /** The moment this long after $at, in $at's own time zone: years as calendar years, days as calendar days. */
    public function after(DateTimeImmutable $at): DateTimeImmutable
    {
        $result = $this->years > 0 ? self::calendarYears($at, $this->years) : $at;
        $days = $this->weeks * 7 + $this->days;
        if ($days > 0) {
            $result = $result->modify('+' . $days . ' days');
        }
        $clock = $this->hours * 3600 + $this->minutes * 60 + $this->seconds;

        return $clock > 0 ? $result->modify('+' . $clock . ' seconds') : $result;
    }

    /** The moment this long before $at (a reminder some time before an expiry). */
    public function before(DateTimeImmutable $at): DateTimeImmutable
    {
        $clock = $this->hours * 3600 + $this->minutes * 60 + $this->seconds;
        $result = $clock > 0 ? $at->modify('-' . $clock . ' seconds') : $at;
        $days = $this->weeks * 7 + $this->days;
        if ($days > 0) {
            $result = $result->modify('-' . $days . ' days');
        }

        return $this->years > 0 ? self::calendarYears($result, -$this->years) : $result;
    }

    /** True when this is shorter than $other, measured from the same moment. */
    public function isShorterThan(self $other, DateTimeImmutable $from): bool
    {
        return $this->after($from) < $other->after($from);
    }

    /** @return array<string,int> each unit's count, largest first */
    private function counts(): array
    {
        return ['year' => $this->years, 'week' => $this->weeks, 'day' => $this->days, 'hour' => $this->hours, 'minute' => $this->minutes, 'second' => $this->seconds];
    }

    /** The same date $years on (or back), a 29 February that the target year lacks becoming 28 February. */
    private static function calendarYears(DateTimeImmutable $at, int $years): DateTimeImmutable
    {
        $target = (int) $at->format('Y') + $years;
        $month = (int) $at->format('n');
        $last = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $target, $month), $at->getTimezone()))->format('t');

        return $at->setDate($target, $month, min((int) $at->format('j'), $last));
    }
}
