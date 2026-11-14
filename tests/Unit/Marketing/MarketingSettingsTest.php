<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\MarketingSettingGroup;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Settings\SettingType;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;

/** Every length of time the Campaign Marketing Manager uses is a setting with a starting value, which .env and Settings override. */
final class MarketingSettingsTest extends TestCase
{
    public function testEachSettingHasItsStartingValue(): void
    {
        $settings = new MarketingSettings((new Foundations(settingsProviders: [new MarketingSettingGroup()]))->settings);
        self::assertSame(['30d', '30d', 'last', '30m', '30d', '365d', '30d', '7d', '7d'], [$settings->visitorRemembered()->text, $settings->saleCountsWithin()->text, $settings->saleCountsFor(),
            $settings->sameVisit()->text, $settings->ipKept()->text, $settings->historyKept()->text, $settings->latestSales()->text, $settings->attentionEnding()->text,
            $settings->attentionNoClicks()->text]);
        self::assertTrue($settings->honoursDoNotTrack());
        self::assertSame(5120 * 1024, $settings->pictureLargest());
        self::assertSame('30m', (new MarketingSettings())->sameVisit()->text, 'Without Settings, the starting values.');
        self::assertSame(['no_link', '5m', '1h', '30d'], [$settings->promotionCounts(), $settings->siteTolerance()->text, $settings->siteRetryLongest()->text,
            $settings->siteReportsKept()->text]);
        self::assertSame(['no_link', '5m', '1h', '30d'], [(new MarketingSettings())->promotionCounts(), (new MarketingSettings())->siteTolerance()->text,
            (new MarketingSettings())->siteRetryLongest()->text, (new MarketingSettings())->siteReportsKept()->text]);
    }

    public function testConnectedSitesAndPromotionCodesReadTheirSettings(): void
    {
        $site = new Foundations(['MARKETING_PROMOTION_COUNTS' => 'always', 'MARKETING_SITE_TOLERANCE' => '2m', 'MARKETING_SITE_RETRY_LONGEST' => '4h', 'MARKETING_SITE_REPORTS_KEPT' => '2w'],
            settingsProviders: [new MarketingSettingGroup()]);
        $settings = new MarketingSettings($site->settings);
        self::assertSame(['always', '2m', '4h', '2w'], [$settings->promotionCounts(), $settings->siteTolerance()->text, $settings->siteRetryLongest()->text, $settings->siteReportsKept()->text]);
        self::assertSame(120, $settings->siteTolerance()->lengthInSeconds());
        $wrong = new MarketingSettings((new Foundations(['MARKETING_PROMOTION_COUNTS' => 'sometimes', 'MARKETING_SITE_TOLERANCE' => 'soon'], settingsProviders: [new MarketingSettingGroup()]))->settings);
        self::assertSame(['no_link', '5m'], [$wrong->promotionCounts(), $wrong->siteTolerance()->text], 'What cannot be read is its starting value.');
        $group = iterator_to_array((new MarketingSettingGroup())->settingGroups(), false)[0];
        self::assertSame(['no_link' => 'Only when the customer followed no link', 'always' => 'Always, even over another campaign\'s link'], $group->settings['MARKETING_PROMOTION_COUNTS']->choices);
        self::assertStringContainsString('A change counts for sales paid from now on.', $group->settings['MARKETING_SALE_COUNTS_WITHIN']->help);
        self::assertStringContainsString('a change counts for sales paid from now on', $group->settings['MARKETING_PROMOTION_COUNTS']->help);
        foreach (['MARKETING_SITE_TOLERANCE', 'MARKETING_SITE_RETRY_LONGEST'] as $name) {
            self::assertSame(SettingType::Duration, $group->settings[$name]->type, $name);
        }
    }

    public function testEnvAndSettingsOverrideThem(): void
    {
        $site = new Foundations(['MARKETING_SAME_VISIT' => '1h', 'MARKETING_SALE_COUNTS_FOR' => 'first', 'MARKETING_HONOUR_DO_NOT_TRACK' => 'false', 'MARKETING_PICTURE_LARGEST' => '200'],
            settingsProviders: [new MarketingSettingGroup()]);
        $settings = new MarketingSettings($site->settings);
        self::assertSame(['1h', 'first', false, 200 * 1024], [$settings->sameVisit()->text, $settings->saleCountsFor(), $settings->honoursDoNotTrack(), $settings->pictureLargest()]);
        $site->settings->set('MARKETING_VISITOR_REMEMBERED', '4w2d');
        self::assertSame('4w2d', $settings->visitorRemembered()->text);
        self::assertSame('env', $site->settings->source('MARKETING_SAME_VISIT'));
    }

    public function testTheGroupNamesEverySettingAndEachLengthIsALengthOfTime(): void
    {
        $groups = iterator_to_array((new MarketingSettingGroup())->settingGroups(), false);
        self::assertCount(1, $groups);
        self::assertSame(['marketing', 'Campaign Marketing Manager'], [$groups[0]->key, $groups[0]->label]);
        self::assertSame(MarketingSettings::NAMES, array_keys($groups[0]->settings));
        foreach ($groups[0]->settings as $setting) {
            if (str_ends_with($setting->name, '_KEPT') || in_array($setting->name, ['MARKETING_VISITOR_REMEMBERED', 'MARKETING_SALE_COUNTS_WITHIN', 'MARKETING_SAME_VISIT', 'MARKETING_LATEST_SALES'], true)) {
                self::assertSame(SettingType::Duration, $setting->type, $setting->name);
            }
        }
    }
}
