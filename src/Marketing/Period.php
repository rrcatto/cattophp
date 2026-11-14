<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/**
 * A period the figures are counted over: from one moment (included) up to another (not included), either open. Named
 * periods are worked out in the time zone of the moment given as now: today, the last 7 or 30 days (today among them),
 * this month, last month, this year, or all.
 */
final class Period
{
    public const NAMES = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year', 'all' => 'All'];

    public function __construct(public readonly ?DateTimeImmutable $from = null, public readonly ?DateTimeImmutable $to = null)
    {
        if ($from !== null && $to !== null && $to < $from) {
            throw new \InvalidArgumentException('A period ends after it starts.');
        }
    }

    public static function all(): self
    {
        return new self();
    }

    /** A named period (NAMES), or all for a name it does not know. */
    public static function named(string $name, DateTimeImmutable $now): self
    {
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');

        return match ($name) {
            'today' => new self($today, $tomorrow),
            '7d' => new self($today->modify('-6 days'), $tomorrow),
            '30d' => new self($today->modify('-29 days'), $tomorrow),
            'month' => new self($today->modify('first day of this month'), $tomorrow),
            'last_month' => new self($today->modify('first day of last month'), $today->modify('first day of this month')),
            'year' => new self($today->setDate((int) $today->format('Y'), 1, 1), $tomorrow),
            default => self::all(),
        };
    }

    /**
     * Two dates as typed (2026-11-01 and 2026-11-30, both included), in a time zone; either may be empty for an open end.
     * Null when either cannot be read or the end comes first.
     */
    public static function dates(string $from, string $to, \DateTimeZone $zone): ?self
    {
        $start = self::date($from, $zone);
        $end = self::date($to, $zone);
        if ($start === false || $end === false) {
            return null;
        }
        // The end date is included: the period runs to the start of the day after it.
        $end = $end?->modify('+1 day');
        if ($start !== null && $end !== null && $end <= $start) {
            return null;
        }

        return new self($start, $end);
    }

    /** A date as typed: null when empty, false when it cannot be read. */
    private static function date(string $typed, \DateTimeZone $zone): DateTimeImmutable|false|null
    {
        $typed = trim($typed);
        if ($typed === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $typed, $zone);

        return $date !== false && $date->format('Y-m-d') === $typed ? $date : false;
    }

    public function isAll(): bool
    {
        return $this->from === null && $this->to === null;
    }

    public function contains(DateTimeImmutable $at): bool
    {
        return ($this->from === null || $at >= $this->from) && ($this->to === null || $at < $this->to);
    }
}
