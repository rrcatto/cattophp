<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Country;

use CattoPHP\Country\Countries;
use PHPUnit\Framework\TestCase;

/** Every country by its code, named in British English, with a project's own country first in a drop-down. */
final class CountriesTest extends TestCase
{
    public function testEveryCountryHasItsName(): void
    {
        self::assertCount(249, Countries::CODES);
        self::assertSame(['South Africa', 'United Kingdom', 'XX'], [Countries::name('za'), Countries::name(' GB '), Countries::name('XX')]);
        self::assertSame("Côte d'Ivoire", Countries::name('CI'), 'Straight quotes.');
        self::assertSame(['ZA', null, null], [Countries::code(' za '), Countries::code('ZZ'), Countries::code(null)]);
    }

    public function testADropDownListsAProjectsOwnCountriesFirstThenTheRestByName(): void
    {
        $plain = Countries::options();
        self::assertCount(249, $plain);
        self::assertSame('AF', $plain[0]['code'], 'Afghanistan first, A to Z.');
        $own = Countries::options('ZA', 'na', 'not-a-country');
        self::assertSame(['ZA', 'NA'], [$own[0]['code'], $own[1]['code']]);
        self::assertCount(249, $own, 'Each country once.');
        self::assertSame(['code' => 'ZA', 'name' => 'South Africa'], $own[0]);
        self::assertSame($plain, Countries::options(), 'The same list each time.');
        self::assertSame(array_slice($plain, 0, 3), array_slice(array_values(array_filter(Countries::options('ZA'), static fn(array $c): bool => $c['code'] !== 'ZA')), 0, 3), 'The rest keep their order.');
    }
}
