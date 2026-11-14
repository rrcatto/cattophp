<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\TrackedOpening;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\MarketingSalesShop;
use DateTimeImmutable;

/**
 * Which campaign gets a sale, against the database: none; one touch; two campaigns, first and last; touches outside the
 * counting period or after the payment; touches the links did not count; two visitors of one account; the placing
 * browser's visitor; a campaign's promotion code with no link, and several campaigns carrying one code; the period as it
 * was kept, and a later change of a setting leaving a paid sale as it was; the provisional attribution of a waiting
 * sale; and MARKETING_PROMOTION_COUNTS both ways (a link and another campaign's code under no_link and under always,
 * the sale keeping both facts either way).
 */
final class MarketingSalesAttributionTest extends DatabaseTestCase
{
    private MarketingHarness $marketing;
    private MarketingSalesShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection);
        $this->shop = new MarketingSalesShop($this->marketing);
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testASaleWithNoTouchCountsForNoCampaign(): void
    {
        $sale = $this->pay('ORD-2001');
        self::assertSame([null, null, null, null, null, null, null, 'no_link', '30d', true],
            [$sale->firstTouchId, $sale->lastTouchId, $sale->firstCampaignId, $sale->lastCampaignId, $sale->firstBy, $sale->lastBy, $sale->promotionCampaignId, $sale->promotionCounts,
                $sale->countedWithin, $sale->fixed]);
        self::assertNotNull($sale->attributedAt);
    }

    public function testOneTouchIsTheFirstAndTheLast(): void
    {
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale');
        $publication = $this->marketing->publication('spring-sale', 'facebook', 'Page post');
        $click = $this->shop->click('spring-sale', 'fb1');
        $this->shop->later('+1 day');
        $sale = $this->pay('ORD-2002', $click);
        self::assertSame([$click->touchId, $click->touchId, $campaign->id, $campaign->id, $publication->id, $publication->id, 'link', 'link'],
            [$sale->firstTouchId, $sale->lastTouchId, $sale->firstCampaignId, $sale->lastCampaignId, $sale->firstPublicationId, $sale->lastPublicationId, $sale->firstBy, $sale->lastBy]);
        self::assertSame([$campaign->id, $campaign->id, 'link'], [$sale->campaignId('first'), $sale->campaignId('last'), $sale->by('last')]);
    }

    public function testTwoCampaignsGiveADifferentFirstAndLastTouch(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $winter = $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $first = $this->shop->click('spring-sale');
        $this->shop->later('+3 days');
        $last = $this->shop->click('winter-warmers', token: MarketingSalesShop::token($first));
        $this->shop->later('+1 hour');
        $sale = $this->pay('ORD-2003', $first);
        self::assertSame([$first->touchId, $spring->id, $last->touchId, $winter->id], [$sale->firstTouchId, $sale->firstCampaignId, $sale->lastTouchId, $sale->lastCampaignId]);
    }

    public function testTouchesOutsideThePeriodOrAfterThePaymentDoNotCount(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $winter = $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $old = $this->shop->click('spring-sale');
        $this->shop->later('+31 days');
        $recent = $this->shop->click('winter-warmers', token: MarketingSalesShop::token($old));
        $this->shop->later('+1 hour');
        $sale = $this->pay('ORD-2004', $old);
        self::assertSame([$recent->touchId, $recent->touchId, $winter->id], [$sale->firstTouchId, $sale->lastTouchId, $sale->firstCampaignId], 'The spring click was 31 days before.');

        $paidAt = $this->shop->now();
        $this->shop->later('+1 hour');
        $this->shop->click('spring-sale', token: MarketingSalesShop::token($old));
        $this->marketing->sales->placed($this->shop->report('ORD-2005'), $this->shop->browser(MarketingSalesShop::token($old)));
        $late = $this->marketing->sales->paid($this->shop->report('ORD-2005'), $paidAt);
        self::assertSame([$recent->touchId, $winter->id], [$late?->lastTouchId, $late?->lastCampaignId], 'The click an hour after the payment is not counted for it.');
        self::assertNotNull($late);
        self::assertNotSame($spring->id, $late->firstCampaignId);
    }

    public function testTouchesTheLinksDidNotCountAreSetAside(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $counted = $this->shop->click('spring-sale');
        $token = MarketingSalesShop::token($counted);
        $this->shop->later('+10 minutes');
        $sameVisit = $this->shop->click('spring-sale', token: $token);
        $this->marketing->campaigns->pause('winter-warmers');
        $this->shop->later('+1 day');
        $paused = $this->shop->click('winter-warmers', token: $token);
        $this->shop->click('winter-warmers', token: $token, agent: MarketingSalesShop::PREVIEW);
        self::assertSame([false, false], [$this->marketing->touches->byId($sameVisit->touchId)?->counted, $this->marketing->touches->byId($paused->touchId)?->counted]);
        $sale = $this->pay('ORD-2006', $counted);
        self::assertSame([$counted->touchId, $counted->touchId, $spring->id, $spring->id], [$sale->firstTouchId, $sale->lastTouchId, $sale->firstCampaignId, $sale->lastCampaignId]);
    }

    public function testEveryVisitorOfTheBuyersAccountCounts(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $winter = $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $phone = $this->shop->click('spring-sale', agent: MarketingSalesShop::PHONE);
        $this->shop->later('+2 days');
        $computer = $this->shop->click('winter-warmers');
        self::assertNotNull($phone->visitorId);
        self::assertNotNull($computer->visitorId);
        self::assertNotSame($phone->visitorId, $computer->visitorId);
        $this->marketing->visitors->identify($phone->visitorId, '1');
        $this->marketing->visitors->identify($computer->visitorId, '1');
        $this->shop->later('+1 hour');
        // Paid with no browser of its own, such as by a payment provider's notice: the account's visitors count.
        $sale = $this->marketing->sales->paid($this->shop->report('ORD-2007'), $this->shop->now());
        self::assertSame([$phone->touchId, $spring->id, $computer->touchId, $winter->id], [$sale?->firstTouchId, $sale?->firstCampaignId, $sale?->lastTouchId, $sale?->lastCampaignId]);
        self::assertNotNull($sale);
        self::assertNull($sale->visitorId);
    }

    public function testThePlacingBrowsersVisitorCountsThoughItIsTiedToNoAccount(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $click = $this->shop->click('spring-sale');
        $this->shop->later('+1 hour');
        $guest = $this->pay('ORD-2008', $click, null);
        self::assertSame([$spring->id, $click->visitorId, null], [$guest->lastCampaignId, $guest->visitorId, $guest->account]);
        $other = $this->pay('ORD-2009', $click, '5');
        self::assertSame($spring->id, $other->lastCampaignId, 'The browser\'s touches count for whoever buys from it.');
        self::assertNull($this->marketing->visitors->byId((int) $click->visitorId)?->account, 'Buying ties nobody.');
    }

    public function testACampaignsPromotionCodeCountsWhenNoLinkWasFollowed(): void
    {
        $promoted = $this->marketing->campaigns->activate($this->marketing->campaigns->create(new CampaignInput('Spring sale', 'spring-sale', promotion: 'SPRING10'))->code);
        $sale = $this->pay('ORD-2010', promotion: 'spring10');
        self::assertSame([null, null, $promoted->id, $promoted->id, null, null, 'promotion', 'promotion', $promoted->id],
            [$sale->firstTouchId, $sale->lastTouchId, $sale->firstCampaignId, $sale->lastCampaignId, $sale->firstPublicationId, $sale->lastPublicationId, $sale->firstBy, $sale->lastBy,
                $sale->promotionCampaignId]);
        self::assertSame('The campaign\'s promotion code', Sale::BY[(string) $sale->lastBy]);
        $none = $this->pay('ORD-2011', promotion: 'AUTUMN5');
        self::assertSame([null, null], [$none->lastCampaignId, $none->promotionCampaignId], 'A code no campaign carries.');
    }

    public function testOfSeveralCampaignsCarryingOneCodeTheRunningOneElseTheLatestStartedGetsIt(): void
    {
        $campaigns = $this->marketing->campaigns;
        $september = $campaigns->activate($campaigns->create(new CampaignInput('Spring tea', 'spring-tea', startsAt: '2026-09-01', promotion: 'SPRING10'))->code);
        $october = $campaigns->activate($campaigns->create(new CampaignInput('Spring mugs', 'spring-mugs', startsAt: '2026-10-01', endsAt: '2026-10-31', promotion: 'SPRING10'))->code);
        $campaigns->create(new CampaignInput('Spring gifts', 'spring-gifts', promotion: 'SPRING10'));
        $now = $this->shop->now();
        self::assertSame($september->id, $this->marketing->saleAttribution->promotionCampaign('SPRING10', $now), 'The one running now.');
        self::assertSame($october->id, $this->marketing->saleAttribution->promotionCampaign('SPRING10', new DateTimeImmutable('2026-10-15 12:00:00 UTC')), 'Both ran then: the one running wins, either way.');
        $campaigns->pause('spring-tea');
        self::assertSame($october->id, $this->marketing->saleAttribution->promotionCampaign('SPRING10', $now), 'None running: the one that started most recently; a draft never.');
        self::assertSame($october->id, $this->pay('ORD-2012', promotion: 'SPRING10')->lastCampaignId);
    }

    public function testThePeriodAsItWasIsKeptAndALaterChangeLeavesAPaidSaleAsItWas(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $click = $this->shop->click('spring-sale');
        $this->shop->later('+10 days');
        $paid = $this->pay('ORD-2013', $click);
        self::assertSame(['30d', $spring->id], [$paid->countedWithin, $paid->lastCampaignId]);

        $this->marketing->site->settings->set('MARKETING_SALE_COUNTS_WITHIN', '7d');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM cattophp_marketing_sales ORDER BY id');
        $this->marketing->sales->paid($this->shop->report('ORD-2013'), $this->shop->now());
        $this->marketing->sales->placed($this->shop->report('ORD-2013'), $this->shop->browser(MarketingSalesShop::token($click)));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM cattophp_marketing_sales ORDER BY id'), 'Fixed when it was paid.');

        $later = $this->pay('ORD-2014', $click);
        self::assertSame(['7d', null], [$later->countedWithin, $later->lastCampaignId], 'Ten days after the click, outside a week: counted from now on.');
    }

    public function testAWaitingSaleIsCountedProvisionallyAndPaymentCountsBackFromItself(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $winter = $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $first = $this->shop->click('spring-sale');
        $this->shop->later('+1 hour');
        $waiting = $this->marketing->sales->placed($this->shop->report('ORD-2015'), $this->shop->browser(MarketingSalesShop::token($first)));
        self::assertSame([$spring->id, false], [$waiting?->lastCampaignId, $waiting?->fixed]);
        $this->shop->later('+25 days');
        $second = $this->shop->click('winter-warmers', token: MarketingSalesShop::token($first));
        $this->shop->later('+10 days');
        self::assertNotNull($waiting);
        $paid = $this->marketing->sales->paid($this->shop->report('ORD-2015', placedAt: $waiting->placedAt), $this->shop->now());
        self::assertSame([$second->touchId, $winter->id, $second->touchId, $winter->id, true],
            [$paid?->firstTouchId, $paid?->firstCampaignId, $paid?->lastTouchId, $paid?->lastCampaignId, $paid?->fixed], 'The spring click was 35 days before the payment.');
    }

    public function testUnderNoLinkALinkWinsOverAnotherCampaignsCodeAndTheCodeIsKept(): void
    {
        [$linked, $promoted, $click] = $this->linkAndCode();
        $sale = $this->pay('ORD-2016', $click, promotion: 'SPRING10');
        self::assertSame([$click->touchId, $linked->id, 'link', $click->touchId, $linked->id, 'link', $promoted->id, 'no_link'],
            [$sale->firstTouchId, $sale->firstCampaignId, $sale->firstBy, $sale->lastTouchId, $sale->lastCampaignId, $sale->lastBy, $sale->promotionCampaignId, $sale->promotionCounts]);
        self::assertNotNull($sale->lastPublicationId);
    }

    public function testUnderAlwaysTheCodesCampaignWinsOverAnotherCampaignsLinkAndTheLinkIsKept(): void
    {
        [, $promoted, $click] = $this->linkAndCode();
        $this->marketing->site->settings->set('MARKETING_PROMOTION_COUNTS', 'always');
        $sale = $this->pay('ORD-2017', $click, promotion: 'SPRING10');
        self::assertSame([$click->touchId, $promoted->id, null, 'promotion', $click->touchId, $promoted->id, null, 'promotion', $promoted->id, 'always'],
            [$sale->firstTouchId, $sale->firstCampaignId, $sale->firstPublicationId, $sale->firstBy, $sale->lastTouchId, $sale->lastCampaignId, $sale->lastPublicationId, $sale->lastBy,
                $sale->promotionCampaignId, $sale->promotionCounts]);
        $paid = $this->marketing->events->byKey('paid:ORD-2017');
        self::assertSame([$promoted->id, null, null], [$paid?->campaignId, $paid?->publicationId, $paid?->touchId], 'The journey shows the campaign the sale counts for.');
    }

    public function testUnderAlwaysALinkOfTheCodesOwnCampaignStaysALink(): void
    {
        [$linked, $promoted, $click] = $this->linkAndCode();
        $this->marketing->site->settings->set('MARKETING_PROMOTION_COUNTS', 'always');
        $this->shop->later('+1 day');
        $own = $this->shop->click('spring-sale', 'ig1', MarketingSalesShop::token($click));
        $this->shop->later('+1 hour');
        $sale = $this->pay('ORD-2018', $click, promotion: 'SPRING10');
        self::assertSame([$click->touchId, $promoted->id, 'promotion', $own->touchId, $promoted->id, 'link', $own->publicationId],
            [$sale->firstTouchId, $sale->firstCampaignId, $sale->firstBy, $sale->lastTouchId, $sale->lastCampaignId, $sale->lastBy, $sale->lastPublicationId]);
        self::assertNotSame($linked->id, $sale->firstCampaignId);
    }

    public function testChangingThePromotionSettingAfterAPaymentLeavesThatSaleAsItWas(): void
    {
        [$linked, $promoted, $click] = $this->linkAndCode();
        $before = $this->pay('ORD-2019', $click, promotion: 'SPRING10');
        $this->marketing->site->settings->set('MARKETING_PROMOTION_COUNTS', 'always');
        $this->marketing->sales->paid($this->shop->report('ORD-2019', promotion: 'SPRING10'), $this->shop->now());
        $after = $this->marketing->sales->get('ORD-2019');
        self::assertSame([$linked->id, 'link', 'no_link', $promoted->id], [$after?->lastCampaignId, $after?->lastBy, $after?->promotionCounts, $after?->promotionCampaignId]);
        self::assertNotNull($after);
        self::assertEquals($before->attributedAt, $after->attributedAt);
        $next = $this->pay('ORD-2020', $click, promotion: 'SPRING10');
        self::assertSame([$promoted->id, 'always'], [$next->lastCampaignId, $next->promotionCounts], 'Counted from now on.');
    }

    /**
     * A person who followed one campaign's link (Winter warmers, on Facebook) while another campaign carries the code they
     * pay with (Spring sale, SPRING10).
     *
     * @return array{0:\CattoPHP\Marketing\Campaign,1:\CattoPHP\Marketing\Campaign,2:TrackedOpening}
     */
    private function linkAndCode(): array
    {
        $linked = $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $this->marketing->publication('winter-warmers', 'facebook', 'Page post');
        $promoted = $this->marketing->campaigns->activate($this->marketing->campaigns->create(new CampaignInput('Spring sale', 'spring-sale', promotion: 'SPRING10'))->code);
        $this->marketing->publication('spring-sale', 'instagram', 'Story');
        $click = $this->shop->click('winter-warmers', 'fb1');
        $this->shop->later('+1 hour');

        return [$linked, $promoted, $click];
    }

    /** An order placed from a browser (the one a click was made in, when given) and paid now. */
    private function pay(string $reference, ?TrackedOpening $from = null, ?string $account = '1', ?string $promotion = null): Sale
    {
        $report = $this->shop->report($reference, $account, $promotion);
        if ($from !== null) {
            $this->marketing->sales->placed($report, $this->shop->browser(MarketingSalesShop::token($from)));
        }
        $sale = $this->marketing->sales->paid($report, $this->shop->now(), 'Card');
        self::assertNotNull($sale, implode("\n", $this->marketing->logger->lines));

        return $sale;
    }
}
