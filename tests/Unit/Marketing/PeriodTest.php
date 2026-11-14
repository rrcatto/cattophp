<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\Period;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** The periods the figures are counted over, named or typed, in the installation's time zone. */
final class PeriodTest extends TestCase
{
    public function testANamedPeriodIsWorkedOutFromNow(): void
    {
        $now = new DateTimeImmutable('2026-11-13 15:30:00', new DateTimeZone('Africa/Johannesburg'));
        $days = static fn(Period $period): array => [$period->from?->format('Y-m-d H:i'), $period->to?->format('Y-m-d H:i')];
        self::assertSame(['2026-11-13 00:00', '2026-11-14 00:00'], $days(Period::named('today', $now)));
        self::assertSame(['2026-11-07 00:00', '2026-11-14 00:00'], $days(Period::named('7d', $now)));
        self::assertSame(['2026-10-15 00:00', '2026-11-14 00:00'], $days(Period::named('30d', $now)));
        self::assertSame(['2026-11-01 00:00', '2026-11-14 00:00'], $days(Period::named('month', $now)));
        self::assertSame(['2026-10-01 00:00', '2026-11-01 00:00'], $days(Period::named('last_month', $now)));
        self::assertSame(['2026-01-01 00:00', '2026-11-14 00:00'], $days(Period::named('year', $now)));
        self::assertTrue(Period::named('all', $now)->isAll());
        self::assertTrue(Period::named('no-such-period', $now)->isAll());
        self::assertSame('Africa/Johannesburg', Period::named('today', $now)->from?->getTimezone()->getName());
    }

    public function testTwoTypedDatesIncludeTheLastDay(): void
    {
        $zone = new DateTimeZone('UTC');
        $period = Period::dates('2026-11-01', '2026-11-30', $zone);
        self::assertNotNull($period);
        self::assertSame('2026-12-01', $period->to?->format('Y-m-d'));
        self::assertTrue($period->contains(new DateTimeImmutable('2026-11-30 23:59:59', $zone)));
        self::assertFalse($period->contains(new DateTimeImmutable('2026-12-01 00:00:00', $zone)));
        self::assertNull(Period::dates('2026-11-30', '2026-11-01', $zone), 'The end comes after the start.');
        self::assertNull(Period::dates('30/11/2026', '', $zone));
        self::assertNull(Period::dates('', '', $zone)?->from);
        self::assertSame('2026-11-01', Period::dates('2026-11-01', '', $zone)?->from?->format('Y-m-d'));
    }

    public function testAPeriodEndsAfterItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Period(new DateTimeImmutable('2026-11-02'), new DateTimeImmutable('2026-11-01'));
    }
}
