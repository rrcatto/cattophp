<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing\Landing;

use CattoPHP\Marketing\Landing\Kinds\AudienceKind;
use CattoPHP\Marketing\Landing\Kinds\BenefitsKind;
use CattoPHP\Marketing\Landing\Kinds\CountdownKind;
use CattoPHP\Marketing\Landing\Kinds\CtaKind;
use CattoPHP\Marketing\Landing\Kinds\FaqKind;
use CattoPHP\Marketing\Landing\Kinds\HeroKind;
use CattoPHP\Marketing\Landing\Kinds\OffersKind;
use CattoPHP\Marketing\Landing\Kinds\OutcomesKind;
use CattoPHP\Marketing\Landing\Kinds\PictureKind;
use CattoPHP\Marketing\Landing\Kinds\VideoKind;
use CattoPHP\Marketing\Landing\Kinds\WordsKind;
use CattoPHP\Marketing\Landing\LandingViewBuilder;
use CattoPHP\Ui\Contract\TemplateContract;
use PHPUnit\Framework\TestCase;

/**
 * The public landing page's templates keep the rules of every CattoPHP page: no inline style, script or event handler
 * (the strict Content-Security-Policy), components drawn by their own templates, an icon on every button, and a template
 * for each of CattoPHP's kinds where the kind names it.
 */
final class MarketingLandingPublicTemplatesTest extends TestCase
{
    public function testTheyHoldNoInlineStyleScriptOrHandlerAndDrawComponentsByTheirOwnTemplates(): void
    {
        $templates = self::templates();
        self::assertGreaterThanOrEqual(17, count($templates));
        foreach ($templates as $name => $source) {
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', $source);
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=|<style\b|<script\b|\son[a-z]+\s*=|javascript:/i', $markup, $name);
        }
        self::assertSame([], TemplateContract::duplicates($templates));
    }

    public function testEveryButtonNamesItsIconAndTheIconsAreTheOnesTheReadmeLists(): void
    {
        $templates = self::templates();
        self::assertSame([], TemplateContract::withoutIcon($templates));
        self::assertSame(['action-back', 'action-jump-down', 'action-play'], array_keys(TemplateContract::namedIcons($templates)), 'Those written in the templates; the rest come with the view.');
        self::assertSame(['view' => 'action-view', 'favourite' => 'action-favourite', 'buy' => 'action-buy', 'request' => 'action-request', 'continue' => 'action-continue',
            'order' => 'action-orders', 'sign_in' => 'action-sign-in'], LandingViewBuilder::ICONS);
    }

    public function testEachOfCattoPHPsKindsHasTheTemplateItNames(): void
    {
        $templates = TemplateContract::cattophpTemplates();
        foreach ([new HeroKind(), new WordsKind(), new PictureKind(), new VideoKind(), new OffersKind(), new AudienceKind(), new OutcomesKind(), new BenefitsKind(), new CountdownKind(),
            new FaqKind(), new CtaKind()] as $kind) {
            self::assertArrayHasKey($kind->template(), $templates, $kind->key());
        }
        self::assertArrayHasKey(LandingViewBuilder::TEMPLATE, $templates);
    }

    public function testAPressIsNamedBySectionAndNumberAlone(): void
    {
        foreach (['abcd1234-1', 'abcd1234-12', 'a1b2-999'] as $press) {
            self::assertMatchesRegularExpression(LandingViewBuilder::PRESS, $press);
        }
        foreach (['abcd1234-0', 'abcd1234', 'ABCD1234-1', 'abcd1234-1000', '../x-1', 'abcd1234-1/'] as $press) {
            self::assertDoesNotMatchRegularExpression(LandingViewBuilder::PRESS, $press);
        }
    }

    /** @return array<string,string> the public page's templates by name, with their source */
    private static function templates(): array
    {
        return array_filter(TemplateContract::cattophpTemplates(), static fn(string $name): bool => str_starts_with($name, '@CattoPHP/marketing/landing/')
            && !str_starts_with($name, '@CattoPHP/marketing/landing/forms/'), ARRAY_FILTER_USE_KEY);
    }
}
