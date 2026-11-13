<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Runtime;

use CattoPHP\Runtime\RunMode;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase
{
    public function testProductionOnlyWhenItSaysSo(): void
    {
        self::assertSame('production', RunMode::environment('production'));
        self::assertSame('production', RunMode::environment(' PRODUCTION '));
        self::assertFalse(RunMode::debug('Production'));
        foreach (['', 'prod', 'dev', 'development', 'test', 'live'] as $value) {
            self::assertSame('development', RunMode::environment($value), $value);
            self::assertTrue(RunMode::debug($value), $value);
        }
    }

    public function testLiveOnlyWhenItSaysSo(): void
    {
        self::assertTrue((new Foundations())->siteMode->isDemo(), 'no value is a demo');
        self::assertTrue((new Foundations(['APP_SITE_MODE' => 'lvie']))->siteMode->isDemo(), 'a typing mistake is a demo');
        self::assertFalse((new Foundations(['APP_SITE_MODE' => 'Live']))->siteMode->isDemo());
        self::assertFalse((new Foundations(siteMode: false))->siteMode->isDemo(), 'a project without the distinction is never a demo');
        self::assertTrue(SiteMode::fixed('demo')->isDemo());
    }

    public function testADemoSitesWordsAreTheOwnersOrTheStandardOnes(): void
    {
        $site = new Foundations(['APP_DEMO_HEADING' => 'Testing']);

        self::assertSame('Testing', $site->siteMode->heading());
        self::assertSame(SiteMode::NOTICE, $site->siteMode->notice());
    }

    public function testTheInstallationNamesItselfAndShowsItsOwnTime(): void
    {
        $site = new Foundations(['APP_URL' => 'https://Example.com/', 'APP_TIMEZONE' => 'Africa/Johannesburg']);

        self::assertSame('https://Example.com', $site->installation->address());
        self::assertSame('https://Example.com/sign-in', $site->installation->addressOf('/sign-in'));
        self::assertSame('example.com', $site->installation->domain());
        self::assertSame('12:00', $site->installation->localTime($site->clock->now())->format('H:i'), '10:00 UTC is 12:00 in Johannesburg');
    }

    public function testTheInstallationsLocaleIsASettingPlainBritishEnglishUnlessItSaysOtherwise(): void
    {
        self::assertSame('en_GB', (new Foundations())->installation->locale(), 'CattoPHP\'s own: none in .env.');
        $site = new Foundations(['APP_LOCALE' => 'en_ZA']);
        self::assertSame('en_ZA', $site->installation->locale(), 'From .env.');
        self::assertSame(\CattoPHP\Settings\Settings::FROM_ENV, $site->settings->source('APP_LOCALE'));
        $site->settings->set('APP_LOCALE', 'en-US');
        self::assertSame('en_US', $site->installation->locale(), 'Settings over .env, written either way.');
        $site->settings->reset('APP_LOCALE');
        self::assertSame('en_ZA', $site->installation->locale(), 'Reset: .env again.');
        self::assertSame('installation', $site->catalogue->groupOf('APP_LOCALE'), 'Beside the time zone.');
        self::assertSame('en_GB', (new Foundations(['APP_LOCALE' => 'not a locale']))->installation->locale(), 'Not a locale: the plain one.');
    }

    public function testThePagesLanguageIsTheLocaleWrittenAsHtmlNamesIt(): void
    {
        self::assertSame('en-GB', (new Foundations())->installation->language(), 'None set: plain British English.');
        self::assertSame('en-ZA', (new Foundations(['APP_LOCALE' => 'en_ZA']))->installation->language());
        self::assertSame('en-ZA', (new Foundations(['APP_LOCALE' => 'en-za']))->installation->language(), 'The region in capitals, however it was written.');
        self::assertSame('af', (new Foundations(['APP_LOCALE' => 'af']))->installation->language(), 'A language without a region.');
        self::assertSame('en-GB', (new Foundations(['APP_LOCALE' => 'not a locale']))->installation->language());
    }
}
