<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\SouthAfrica;

use CattoPHP\SouthAfrica\IdNumber;
use CattoPHP\SouthAfrica\Provinces;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** South African ID numbers checked as well formed, and the nine provinces (cattolms's checks, ported). */
final class IdNumberTest extends TestCase
{
    private const TODAY = '2026-10-27 15:30';

    public function testAValidNumberGivesItsDateOfBirth(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $number = IdNumber::fromInput('8001015009087', $today);
        self::assertSame('1980-01-01', $number->birthdate);
        self::assertTrue($number->isCitizen());
        self::assertSame('8001015009087', IdNumber::fromInput(' 800101 5009-087 ', $today)->digits, 'Spaces and hyphens as people type them are fine.');
        self::assertSame('800101 **** ***', IdNumber::masked($number->digits));
        self::assertSame('', IdNumber::masked('123'));
    }

    public function testPermanentResidentsHoldValidNumbersToo(): void
    {
        $made = IdNumber::make(new DateTimeImmutable('1985-07-04'), 5123, false);
        self::assertSame('1', $made[10]);
        self::assertFalse(IdNumber::fromInput($made, new DateTimeImmutable(self::TODAY))->isCitizen());
    }

    public function testAnyMistakeIsRefusedInPlainWords(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $cases = ['' => IdNumber::ENTER, '80010150090' => IdNumber::ENTER, '80010150090871' => IdNumber::ENTER, '80010A5009087' => IdNumber::ENTER,
            '8001015009088' => IdNumber::INVALID, '8013015009087' => IdNumber::INVALID, '8002305009087' => IdNumber::INVALID];
        foreach ($cases as $typed => $words) {
            try {
                IdNumber::fromInput($typed, $today);
                self::fail('Accepted: ' . $typed);
            } catch (InvalidArgumentException $refused) {
                self::assertSame($words, $refused->getMessage(), (string) $typed);
            }
        }
        // A citizenship digit other than 0 or 1, even with a checksum that fits.
        for ($check = 0; $check <= 9; $check++) {
            try {
                IdNumber::fromInput('800101500928' . $check, $today);
                self::fail('Accepted a citizenship digit of 2.');
            } catch (InvalidArgumentException $refused) {
                self::assertSame(IdNumber::INVALID, $refused->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        IdNumber::fromInput(IdNumber::make(new DateTimeImmutable('2026-10-28'), 1234), $today);
    }

    public function testTheCenturyIsTheOneThatMakesSense(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        self::assertSame('1999-12-31', IdNumber::fromInput(IdNumber::make(new DateTimeImmutable('1999-12-31'), 9), $today)->birthdate);
        self::assertSame('2001-02-28', IdNumber::fromInput(IdNumber::make(new DateTimeImmutable('2001-02-28'), 9), $today)->birthdate);
        self::assertSame('1927-06-01', IdNumber::fromInput(IdNumber::make(new DateTimeImmutable('1927-06-01'), 9), $today)->birthdate, 'Not 2027, which is to come.');
        self::assertSame('2026-10-27', IdNumber::fromInput(IdNumber::make(new DateTimeImmutable('2026-10-27'), 9), $today)->birthdate, 'Born today; how old is the project\'s to judge.');
    }

    public function testEachMadeNumberIsValidAndTheFingerprintFindsTheSameNumber(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $number = IdNumber::fromInput(IdNumber::make((new DateTimeImmutable('1960-01-01'))->modify('+' . ($i * 60) . ' days'), $i * 41), $today);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $number->fingerprint('key'));
            $seen[$number->fingerprint('key')] = true;
        }
        self::assertCount(200, $seen);
        self::assertSame(IdNumber::fromInput('8001015009087', $today)->fingerprint('key'), IdNumber::fromInput('800101 5009 087', $today)->fingerprint('key'));
        self::assertSame(hash_hmac('sha256', 'sa-id:8001015009087', 'key'), IdNumber::fromInput('8001015009087', $today)->fingerprint('key'), 'The stored form projects already keep.');
        self::assertNotSame(IdNumber::fromInput('8001015009087', $today)->fingerprint('key'), IdNumber::fromInput('8001015009087', $today)->fingerprint('another key'));
    }

    public function testTheNineProvinces(): void
    {
        self::assertCount(9, Provinces::ALL);
        self::assertSame(['KwaZulu-Natal', 'Western Cape', null, null], [Provinces::province(' kwazulu-natal '), Provinces::province('WESTERN CAPE'), Provinces::province('Transvaal'), Provinces::province(null)]);
    }
}
