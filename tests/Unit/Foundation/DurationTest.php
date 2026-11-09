<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Foundation;

use CattoPHP\Foundation\Duration;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function testMinutesAndSecondsFollowHours(): void
    {
        self::assertSame('1 hour 30 minutes', Duration::parse('1h30m')->words());
        self::assertSame('90 seconds', Duration::parse('90s')->words());
        self::assertSame('5 minutes', Duration::parse('5m')->words(), 'm is minutes, never months.');
        self::assertSame('2 days 3 hours 4 minutes 5 seconds', Duration::parse('2d3h4m5s')->words());
        $at = new DateTimeImmutable('2026-10-26T10:00:00+02:00');
        self::assertSame('2026-10-26T11:30:00+02:00', Duration::parse('1h30m')->after($at)->format(DATE_ATOM));
        self::assertSame('2026-10-26T10:01:30+02:00', Duration::parse('90s')->after($at)->format(DATE_ATOM));
        self::assertSame('2026-10-26T09:55:00+02:00', Duration::parse('5m')->before($at)->format(DATE_ATOM));
        self::assertTrue(Duration::parse('59m59s')->isShorterThan(Duration::parse('1h'), $at));
    }

    public function testAFixedLengthCountsAYearAs365DaysWhileAYearAfterADateIsACalendarYear(): void
    {
        self::assertSame(365 * 86400, Duration::parse('1y')->lengthInSeconds());
        self::assertSame([86400, 1800, 5400, 90, 604800 + 2 * 86400], array_map(static fn(string $text): int => Duration::parse($text)->lengthInSeconds(), ['1d', '30m', '1h30m', '90s', '1w2d']));
        $march = new DateTimeImmutable('2027-03-01T09:00:00+02:00');
        self::assertSame('2028-03-01T09:00:00+02:00', Duration::parse('1y')->after($march)->format(DATE_ATOM), 'The same date next year, across a leap day.');
        self::assertSame('2028-02-29T09:00:00+02:00', $march->modify('+' . Duration::parse('1y')->lengthInSeconds() . ' seconds')->format(DATE_ATOM), 'As a fixed length, 365 days.');
        $leapDay = new DateTimeImmutable('2028-02-29T09:00:00+02:00');
        self::assertSame('2029-02-28T09:00:00+02:00', Duration::parse('1y')->after($leapDay)->format(DATE_ATOM), 'A 29 February the next year lacks is 28 February.');
        self::assertSame('2027-02-28T09:00:00+02:00', Duration::parse('1y')->before($leapDay)->format(DATE_ATOM));
    }

    public function testALengthInSecondsIsWrittenInTheLargestUnitsThatStateItExactly(): void
    {
        foreach ([1800 => '30m', 86400 => '1d', 300 => '5m', 900 => '15m', 5400 => '1h30m', 604800 => '1w', 31536000 => '1y', 90 => '1m30s', 1 => '1s'] as $seconds => $text) {
            self::assertSame($text, Duration::ofSeconds($seconds)->text, $seconds . ' seconds');
            self::assertSame($seconds, Duration::parse($text)->lengthInSeconds(), $text . ' reads back');
        }
        self::assertSame('30 minutes', Duration::ofSeconds(1800)->words());
        self::assertSame('1 day', Duration::ofSeconds(86400)->words());
        $this->expectException(InvalidArgumentException::class);
        Duration::ofSeconds(0);
    }

    public function testAnythingElseIsRefusedWithHowToWriteIt(): void
    {
        foreach (['', '   ', '30', '1h 30m', '30m1h', '1mo', '0d', '1x', '-1d'] as $wrong) {
            self::assertFalse(Duration::isValid($wrong), var_export($wrong, true));
        }
        self::assertTrue(Duration::isValid(' 4w2D '), 'Spaces around it and capital letters are allowed.');
        try {
            Duration::parse('1 week');
            self::fail('A length in words is refused.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame(Duration::HOW, $refused->getMessage());
        }
    }
}
