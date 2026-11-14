<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Sales\OfferSales;
use CattoPHP\Marketing\Sales\ReportedSale;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\TrackedOpening;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\MarketingSalesShop;

/**
 * The money read back, against the database: the totals of what a list holds with waiting and cancelled sales, wallet
 * credit, discounts, refunds and chargebacks; a connected site's sale in another currency left out with its note; each
 * offer's orders, units and money, a bundle's offers never counted; the filters by campaign (one, any, none) and model,
 * channel, publication, country, offer, where sold, buyer and reference; the states as shown; sorting and paging; the
 * latest sales, a campaign's, some accounts' and one person's.
 */
final class MarketingSalesFiguresTest extends DatabaseTestCase
{
    private MarketingHarness $marketing;
    private MarketingSalesShop $shop;
    private Sales $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection);
        $this->shop = new MarketingSalesShop($this->marketing);
        $this->sales = $this->marketing->sales;
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testTheTotalsCountOnlyWhatWasPaidAndShowEveryAmountBesideIt(): void
    {
        $this->trading();
        $totals = $this->sales->totals(new SaleFilter(where: SaleFilter::HERE));
        self::assertSame(['ZAR', 5, 5, 4, 238600, 1900, 5000, 12000, 15000, 211600, 47720, 0, 0, 0, []],
            [$totals->currency, $totals->sales, $totals->paidSales, $totals->payingCustomers, $totals->grossMinor, $totals->discountMinor, $totals->walletMinor, $totals->refundedMinor,
                $totals->chargedBackMinor, $totals->netMinor(), $totals->averageMinor(), $totals->waitingSales, $totals->waitingMinor, $totals->cancelledSales, $totals->leftOut]);
        self::assertSame('', $totals->leftOutNote());

        $every = $this->sales->totals(new SaleFilter(state: 'all', where: SaleFilter::HERE));
        self::assertSame([7, 5, 238600, 1, 32100, 1], [$every->sales, $every->paidSales, $every->grossMinor, $every->waitingSales, $every->waitingMinor, $every->cancelledSales],
            'Waiting is counted with what it is to pay, never added to the revenue; a cancelled sale adds nothing.');
    }

    public function testASaleInAnotherCurrencyIsListedAndLeftOutOfTheTotalsWithANote(): void
    {
        $trading = $this->trading();
        $everywhere = $this->sales->totals(new SaleFilter());
        self::assertSame(['ZAR', 238600, ['USD' => 1]], [$everywhere->currency, $everywhere->grossMinor, $everywhere->leftOut]);
        self::assertSame('1 sale in USD is listed with its own currency and left out of these totals.', $everywhere->leftOutNote());
        self::assertSame(6, $this->sales->count(new SaleFilter()), 'It is listed all the same.');
        $spring = $this->sales->totals(new SaleFilter(campaign: $trading['spring']->id));
        self::assertSame([64100, 12000, 1], [$spring->grossMinor, $spring->refundedMinor, $spring->leftOutSales()], 'Sold on the connected site through the spring click.');
        self::assertSame(['example-org'], array_map(static fn(Sale $sale): ?string => $sale->originSite, $this->sales->list(new SaleFilter(where: 'example-org'))));
        self::assertSame('ZAR', $this->sales->currency());
    }

    public function testEachOffersSalesCountUnitsAndOrdersAndABundlesOffersAreNotCounted(): void
    {
        $this->trading();
        $offers = $this->sales->offers(new SaleFilter(state: 'all'));
        self::assertSame([['product:mug-01', 'Enamel mug', 3, 12, 180000, 0, 15000, 165000], ['product:tea-100', 'Rooibos tea, 100 bags', 2, 3, 26600, 0, 0, 26600],
            ['bundle:gift-box', 'Gift box', 1, 1, 32000, 12000, 0, 20000]],
            array_map(static fn(OfferSales $offer): array => [$offer->offerKey(), $offer->label, $offer->orders, $offer->units, $offer->grossMinor, $offer->refundedMinor,
                $offer->chargedBackMinor, $offer->netMinor()], $offers), 'Only paid sales sell; the company\'s ten places are ten units of one order.');
        self::assertSame(['Product credits'], array_values(array_unique(array_map(static fn(OfferSales $offer): string => $offer->kindLabel, array_slice($offers, 0, 1)))));
    }

    public function testTheFiltersNarrowBySaleAndByTheTouchItCountsFor(): void
    {
        $trading = $this->trading();
        $references = fn(SaleFilter $filter): array => array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->list($filter->withWhere(SaleFilter::HERE), sort: 'reference', direction: 'asc'));
        self::assertSame(['ORD-3001', 'ORD-3002'], $references(new SaleFilter(campaign: $trading['spring']->id)));
        self::assertSame(['ORD-3001', 'ORD-3002', 'ORD-3005'], $references(new SaleFilter(state: 'all', campaign: $trading['spring']->id)));
        self::assertSame(['ORD-3003'], $references(new SaleFilter(campaign: $trading['winter']->id)));
        self::assertSame(['ORD-3001', 'ORD-3002', 'ORD-3003'], $references(new SaleFilter(campaign: SaleFilter::ANY_CAMPAIGN)));
        self::assertSame(['ORD-3004', 'ORD-3007'], $references(new SaleFilter(campaign: SaleFilter::NO_CAMPAIGN)));
        self::assertSame(['ORD-3001'], $references(new SaleFilter(channel: 'facebook')));
        self::assertSame(['ORD-3002'], $references(new SaleFilter(publicationId: $trading['ig1']->publicationId)));
        self::assertSame(['ORD-3001'], $references(new SaleFilter(place: ' page POST ')));
        self::assertSame(['ORD-3001', 'ORD-3002', 'ORD-3003'], $references(new SaleFilter(country: 'ZA')));
        self::assertSame(['ORD-3002'], $references(new SaleFilter(offerType: 'bundle', offerReference: 'gift-box')));
        self::assertSame([], $references(new SaleFilter(offerType: 'product', offerReference: 'tea-100', campaign: $trading['winter']->id)), 'A bundle\'s tea is not a sale of tea.');
        self::assertSame(['ORD-3001', 'ORD-3004'], $references(new SaleFilter(account: '1')));
        self::assertSame(['ORD-3007'], $references(new SaleFilter(organisation: 'ORG-0001')));
        self::assertSame(['ORD-3006'], $references(new SaleFilter(state: 'cancelled')));
        self::assertSame(['ORD-3005'], $references(new SaleFilter(state: 'waiting', search: '3005')));
        self::assertSame(['ORD-3007', 'ORD-3001', 'ORD-3002'], array_map(static fn(Sale $sale): string => $sale->reference,
            $this->sales->list(new SaleFilter(where: SaleFilter::HERE), 3, 0, 'amount')), 'The largest first, a page of three.');
        self::assertSame(['ORD-3004'], array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->list(new SaleFilter(where: SaleFilter::HERE), 1, 4, 'amount')));
        self::assertSame(['waiting' => 1, 'paid' => 4, 'cancelled' => 1, 'partly_refunded' => 1, 'refunded' => 0, 'charged_back' => 1], $this->sales->stateCounts(new SaleFilter()),
            'Each sale once, whatever the filter\'s state.');
        $listed = $this->sales->list(new SaleFilter(search: 'ORD-3002'));
        self::assertSame(['Gift box'], array_map(static fn(SaleLine $line): string => $line->label, $listed[0]->lines), 'Listed with its lines.');
        self::assertSame([], $listed[0]->movements);
        $this->expectException(\InvalidArgumentException::class);
        $this->sales->list(new SaleFilter(), sort: 'customer');
    }

    public function testASaleCountsForItsFirstOrLastTouchOneAtATimeAndThePeriodIsWhenItWasPaid(): void
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $winter = $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $start = $this->shop->now();
        $first = $this->shop->click('spring-sale');
        $this->shop->later('+2 days');
        $this->shop->click('winter-warmers', token: MarketingSalesShop::token($first));
        $this->shop->later('+1 hour');
        $this->paid('ORD-3101', $first, '1');
        $this->shop->later('+5 days');
        $middle = $this->shop->now();
        $this->paid('ORD-3102', $first, '1');
        $count = fn(int $campaign, ?string $model): int => $this->sales->count(new SaleFilter(campaign: $campaign, model: $model));
        self::assertSame([0, 2, 2, 0, 0, 2], [$count($spring->id, null), $count($winter->id, null), $count($spring->id, 'first'), $count($winter->id, 'first'), $count($spring->id, 'last'),
            $count($winter->id, 'last')]);
        $this->marketing->site->settings->set('MARKETING_SALE_COUNTS_FOR', 'first');
        self::assertSame([2, 0], [$count($spring->id, null), $count($winter->id, null)], 'As the setting says.');
        self::assertSame(64200, $this->sales->totals(new SaleFilter(campaign: SaleFilter::ANY_CAMPAIGN))->grossMinor, 'Every campaign together counts each sale once.');

        self::assertSame(['ORD-3101'], array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->list(new SaleFilter(from: $start, until: $middle))));
        self::assertSame(['ORD-3102'], array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->latest($middle)));
        self::assertSame(['ORD-3102', 'ORD-3101'], array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->latest($start, 10, SaleFilter::ANY_CAMPAIGN)));
        self::assertSame([], $this->sales->latest($start, 10, SaleFilter::NO_CAMPAIGN));
        self::assertSame(['ORD-3102', 'ORD-3101'], array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->forCampaign($spring->id)));
        self::assertSame([], $this->sales->forCampaign($spring->id, 'last'));
    }

    public function testTheSalesOfAccountsAndOfOnePersonAreRead(): void
    {
        $trading = $this->trading();
        self::assertSame(['ORD-3004', 'ORD-3001'], array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->forAccounts(['1'])));
        self::assertSame(['ORD-3007', 'ORD-3006', 'ORD-3005', 'ORD-3004', 'ORD-3003', 'ORD-3002', 'ORD-3001'],
            array_map(static fn(Sale $sale): string => $sale->reference, $this->sales->forAccounts(['1', '2', '3', '4', '5', '6'])));
        self::assertSame([], $this->sales->forAccounts([]));
        $person = $this->sales->forPerson([(int) $trading['fb1']->visitorId], '1');
        self::assertSame(['ORD-3001', 'ORD-3004', 'EX-77'], array_map(static fn(Sale $sale): string => $sale->reference, $person), 'With what they bought on the connected site.');
        self::assertSame(['paid'], array_map(static fn($movement): string => $movement->kind, $person[0]->movements), 'With its money.');
        self::assertSame([], $this->sales->forPerson([], null));
    }

    /**
     * A week of the example shop's trading. Paid: ORD-3001 through the spring campaign's Facebook post (wallet credit and
     * the tea's discount), ORD-3002 through its Instagram story (the gift box, partly refunded), ORD-3003 through the
     * winter campaign (a mug, charged back), ORD-3004 with no campaign, ORD-3007 a company's ten mugs. ORD-3005 is waiting
     * (spring), ORD-3006 cancelled. And a connected site's sale in dollars through a spring click.
     *
     * @return array{spring:Campaign,winter:Campaign,fb1:TrackedOpening,ig1:TrackedOpening}
     */
    private function trading(): array
    {
        $spring = $this->marketing->campaign('Spring sale', 'spring-sale');
        $winter = $this->marketing->campaign('Winter warmers', 'winter-warmers');
        $this->marketing->publication('spring-sale', 'facebook', 'Page post');
        $this->marketing->publication('spring-sale', 'instagram', 'Story');
        $fb1 = $this->shop->click('spring-sale', 'fb1');
        $ig1 = $this->shop->click('spring-sale', 'ig1', agent: MarketingSalesShop::PHONE);
        $cold = $this->shop->click('winter-warmers');
        $waiting = $this->shop->click('spring-sale');
        $this->shop->later('+1 hour');
        $this->paid('ORD-3001', $fb1, '1', $this->shop->report('ORD-3001', '1', wallet: 5000));
        $this->shop->later('+1 hour');
        $this->paid('ORD-3002', $ig1, '2', $this->shop->report('ORD-3002', '2', lines: [MarketingSalesShop::giftBox()]));
        $this->sales->refunded('ORD-3002', 'refund:1', ['item:3' => 12000], $this->shop->later('+1 day'), 'Money back');
        $this->paid('ORD-3003', $cold, '3', $this->shop->report('ORD-3003', '3', lines: [MarketingSalesShop::mug()]));
        $this->sales->chargedBack('ORD-3003', 'chargeback:1', 15000, $this->shop->later('+1 day'));
        $this->shop->later('+1 hour');
        $this->paid('ORD-3004', null, '1', $this->shop->report('ORD-3004', '1', lines: [MarketingSalesShop::tea(1, 0)]));
        $this->shop->later('+1 hour');
        $this->sales->placed($this->shop->report('ORD-3005', '4'), $this->shop->browser(MarketingSalesShop::token($waiting)));
        $this->sales->placed($this->shop->report('ORD-3006', '5'));
        $this->sales->cancelled('ORD-3006', $this->shop->later('+1 hour'));
        $this->shop->later('+1 hour');
        $this->paid('ORD-3007', null, '6', $this->shop->report('ORD-3007', '6', lines: [new SaleLine('item:9', 'product', 'mug-01', 'Enamel mug', 'Product credits', 10, 150000)],
            organisation: 'ORG-0001'));
        $site = new Site('example-org', 'Example Org', 'https://example.org', 'stored-secret');
        $this->shop->later('+1 hour');
        $reported = new ReportedSale('sale_paid:EX-77', 'sale_paid', $this->shop->now(), (string) $this->marketing->touches->byId($fb1->touchId)?->reference, true, true, 'EX-77', 'USD', 'paid',
            $this->shop->now(), $this->shop->now(), 4900, 0, 0, [new SaleLine('line:1', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 1, 4900)]);
        self::assertSame(Sales::RECORDED, $this->sales->reported($site, $reported));

        return ['spring' => $spring, 'winter' => $winter, 'fb1' => $fb1, 'ig1' => $ig1];
    }

    private function paid(string $reference, ?TrackedOpening $from, string $account, ?\CattoPHP\Marketing\Sales\SaleReport $report = null): void
    {
        $report ??= $this->shop->report($reference, $account);
        if ($from !== null) {
            $this->sales->placed($report, $this->shop->browser(MarketingSalesShop::token($from)));
        }
        self::assertNotNull($this->sales->paid($report, $this->shop->now(), 'Card'), implode("\n", $this->marketing->logger->lines));
    }
}
