<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\Sales\Attribution;
use CattoPHP\Marketing\Sales\OfferSales;
use CattoPHP\Marketing\Sales\SaleTotals;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** The sales' totals, an offer's sales and an attribution as values: net revenue, the average, the note, the models. */
final class MarketingSalesTotalsTest extends TestCase
{
    public function testTotalsGiveNetRevenueTheAverageAndANoteForOtherCurrencies(): void
    {
        $totals = new SaleTotals('ZAR', 4, 3, 2, 100000, 5000, 2000, 10000, 3000, leftOut: ['USD' => 2, 'EUR' => 1]);
        self::assertSame([87000, 33333, 3], [$totals->netMinor(), $totals->averageMinor(), $totals->leftOutSales()]);
        self::assertSame('3 sales in other currencies (EUR, USD) are listed with their own currency and left out of these totals.', $totals->leftOutNote());
        self::assertSame('1 sale in USD is listed with its own currency and left out of these totals.', (new SaleTotals('ZAR', leftOut: ['USD' => 1]))->leftOutNote());
        $none = new SaleTotals(null);
        self::assertSame([0, 0, ''], [$none->netMinor(), $none->averageMinor(), $none->leftOutNote()]);
    }

    public function testAnOffersSalesKeepItsKeyAndNet(): void
    {
        $offer = new OfferSales('bundle', 'gift-box', 'Gift box', 'Bundle', 2, 3, 96000, 32000, 0);
        self::assertSame(['bundle:gift-box', 64000], [$offer->offerKey(), $offer->netMinor()]);
    }

    public function testAnAttributionGivesTheCampaignOfEachModelAndRefusesWhatCannotBe(): void
    {
        $at = new DateTimeImmutable('2026-11-14 09:30:00 UTC');
        $attribution = new Attribution(4, 9, 1, 2, 7, null, 'link', 'promotion', 2, 'always', '30d', $at);
        self::assertSame([1, 2, true], [$attribution->campaignId('first'), $attribution->campaignId('last'), $attribution->hasCampaign()]);
        self::assertFalse((new Attribution(null, null, null, null, null, null, null, null, null, 'no_link', '30d', $at))->hasCampaign());
        $this->expectException(\InvalidArgumentException::class);
        new Attribution(null, null, null, null, null, null, null, null, null, 'sometimes', '30d', $at);
    }
}
