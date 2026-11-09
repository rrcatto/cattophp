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
}
