<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\MarketingDetailWords;
use CattoPHP\Marketing\MarketingMenu;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\Pages\CampaignsController;
use CattoPHP\Marketing\Pages\MarketingPages;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Tests\Kernel\App\ExampleMarketingProject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/** What the Campaign Marketing Manager's pages are joined by: its menu section, the words of its log's details, and their small helpers. */
final class MarketingPagePartsTest extends TestCase
{
    public function testTheMenuGivesItsSixPagesEachBehindItsPermissionOnlyWhenTheirRoutesAreThere(): void
    {
        self::assertSame([], iterator_to_array((new MarketingMenu(new UrlGenerator(new RouteCollection(), new RequestContext())))->menuSections(), false),
            'A project that has not imported the pages\' routes gets no menu linking to them.');
        $routes = new RouteCollection();
        foreach (['cattophp_marketing_overview' => '/marketing', 'cattophp_marketing_campaigns' => '/marketing/campaigns',
            'cattophp_marketing_landing_pages' => '/marketing/landing-pages', 'cattophp_marketing_visitors' => '/marketing/visitors', 'cattophp_marketing_sales' => '/marketing/sales',
            'cattophp_marketing_settings' => '/marketing/settings'] as $name => $path) {
            $routes->add($name, new Route($path));
        }
        $sections = iterator_to_array((new MarketingMenu(new UrlGenerator($routes, new RequestContext())))->menuSections(), false);
        self::assertCount(1, $sections);
        self::assertSame(['marketing', 'Marketing', 'nav-marketing'], [$sections[0]->key, $sections[0]->label, $sections[0]->icon]);
        self::assertSame([['Overview', 'cattophp_marketing_overview', MarketingPermissions::VIEW], ['Campaigns', 'cattophp_marketing_campaigns', MarketingPermissions::VIEW],
            ['Landing pages', 'cattophp_marketing_landing_pages', MarketingPermissions::VIEW], ['Visitors', 'cattophp_marketing_visitors', MarketingPermissions::PEOPLE],
            ['Sales', 'cattophp_marketing_sales', MarketingPermissions::VIEW], ['Settings', 'cattophp_marketing_settings', MarketingPermissions::SETTINGS]],
            array_map(static fn ($entry): array => [$entry->label, $entry->route, $entry->permission], $sections[0]->entries));
        self::assertSame(['', '', '', 'nav-visitors', 'nav-sales', ''], array_map(static fn ($entry): string => $entry->icon, $sections[0]->entries),
            'Visitors and Sales name their own symbols; a project without them draws the section\'s.');
    }

    public function testTheLogsDetailsAreInWords(): void
    {
        $words = new MarketingDetailWords(new ExampleMarketingProject());
        self::assertSame(['Amount', 'R1,250.50', 'Active', 'Status before', 'Picture', 'Code before'], [$words->label('marketing.spend_added', 'amount_minor'),
            $words->value('marketing.spend_added', 'amount_minor', 125050), $words->value('marketing.campaign_activated', 'status', 'active'),
            $words->label('marketing.campaign_activated', 'status_before'), $words->value('marketing.advert_created', 'kind', 'picture'), $words->label('marketing.campaign_changed', 'code_before')]);
        self::assertNull($words->label('example.note_written', 'amount_minor'), 'Another action\'s details keep their own words.');
        self::assertNull($words->value('marketing.channel_added', 'channel', 'facebook'), 'Without the channels, the log\'s own wording.');
    }

    public function testAProjectsWordsReadInsideASentenceKeepingAnAbbreviation(): void
    {
        self::assertSame(['card', 'simulated card', 'EFT', 'EFT and wallet credit', 'iDEAL', 'PayPal'],
            array_map(MarketingPages::inSentence(...), ['Card', 'Simulated card', 'EFT', 'EFT and wallet credit', 'iDEAL', 'PayPal']));
    }

    public function testMoneyForASpreadsheetANumberInWordsAndWhyAFormWasRefused(): void
    {
        self::assertSame(['4150.00', '0.05', '-12.30'], [MarketingPages::decimal(415000), MarketingPages::decimal(5), MarketingPages::decimal(-1230)]);
        self::assertSame(['1 click', '1,204 clicks'], [MarketingPages::count(1, 'click', 'clicks'), MarketingPages::count(1204, 'click', 'clicks')]);
        self::assertSame('The campaign was not saved: see why beside each field.', CampaignsController::why(new MarketingRefused(['name' => 'Type its name.']), 'The campaign was not saved'));
        self::assertSame('There is no such campaign.', CampaignsController::why(MarketingRefused::because('There is no such campaign.'), 'Not saved'));
        self::assertSame(['period' => '7d'], MarketingPages::periodQuery(['name' => '7d', 'from' => '', 'to' => '']));
        self::assertSame(['period' => 'dates', 'from' => '2026-11-01', 'to' => ''], MarketingPages::periodQuery(['name' => 'dates', 'from' => '2026-11-01', 'to' => '']));
    }
}
