<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\Sales\ReportedSale;
use CattoPHP\Marketing\Sales\SaleChanged;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleMovement;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\TrackedOpening;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\MarketingSalesShop;
use DateTimeImmutable;

/**
 * A connected site's reports about the sales a click here brought it (Sales::reported()), against the database: a click
 * this site does not know; a sale placed, paid, refunded and charged back there, each report kept once and the sale
 * counted for this site's click as the report's flags say; a report older than what is kept; another currency for a
 * sale kept; a sale of the same reference here and there; a report made by ReportedSale::of() read back from its body;
 * and a fault rolled back to its own savepoint and thrown for the receiver to answer with.
 */
final class MarketingSalesReportedTest extends DatabaseTestCase
{
    private MarketingHarness $marketing;
    private MarketingSalesShop $shop;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection);
        $this->shop = new MarketingSalesShop($this->marketing);
        $this->site = new Site('example-org', 'Example Org', 'https://example.org', 'stored-secret');
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testAReportNamingAClickThisSiteDoesNotKnowWritesNothing(): void
    {
        self::assertSame(Sales::UNKNOWN_CLICK, $this->marketing->sales->reported($this->site, $this->report('order_placed', 'waiting', 'k2m9q4d7xa')));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_sales'));
        self::assertStringContainsString('names a click this site does not know', implode("\n", $this->marketing->logger->lines));
    }

    public function testASalePlacedPaidRefundedAndChargedBackThereIsKeptOnceForThisSitesClick(): void
    {
        $campaign = $this->marketing->campaign('Law in November', 'law-nov26');
        $this->marketing->publication('law-nov26', 'email', 'Newsletter');
        $click = $this->shop->click('law-nov26', 'em1');
        $reference = $this->reference($click);
        $sales = $this->marketing->sales;

        self::assertSame(Sales::RECORDED, $sales->reported($this->site, $this->report('order_placed', 'waiting', $reference)));
        $placed = $sales->get('EX-1001', 'example-org');
        self::assertSame(['waiting', 'example-org', $click->visitorId, 'ZA', 19800, $click->touchId, $campaign->id, $click->publicationId, 'site', false],
            [$placed?->state, $placed?->originSite, $placed?->visitorId, $placed?->country, $placed?->totalMinor, $placed?->lastTouchId, $placed?->lastCampaignId, $placed?->lastPublicationId,
                $placed?->lastBy, $placed?->fixed]);
        self::assertNull($sales->get('EX-1001'), 'Not a sale made here.');
        self::assertNotNull($placed);
        self::assertTrue($placed->isFromSite());

        $snapshot = $this->snapshot();
        self::assertSame(Sales::RECORDED, $sales->reported($this->site, $this->report('order_placed', 'waiting', $reference)));
        self::assertSame($snapshot, $this->snapshot(), 'A repeat changes nothing.');

        $paidAt = $this->shop->later('+1 hour');
        $sales->reported($this->site, $this->report('sale_paid', 'paid', $reference, paid: 19800));
        $sales->reported($this->site, $this->report('sale_refunded', 'paid', $reference, paid: 19800, refunded: 4800, movement: ['refund:12', 4800]));
        $sales->reported($this->site, $this->report('sale_refunded', 'paid', $reference, paid: 19800, refunded: 4800, movement: ['refund:12', 4800]));
        $sales->reported($this->site, $this->report('sale_charged_back', 'paid', $reference, paid: 19800, refunded: 4800, chargedBack: 15000, movement: ['chargeback:3', 15000]));
        $sale = $sales->get('EX-1001', 'example-org');
        self::assertNotNull($sale);
        self::assertSame(['paid', true, 19800, 4800, 15000, 0, 'charged_back'], [$sale->state, $sale->fixed, $sale->paidMinor, $sale->refundedMinor, $sale->chargedBackMinor, $sale->netMinor(),
            $sale->shownState()]);
        self::assertEquals($paidAt, $sale->paidAt);
        self::assertSame([['paid', 'paid', 19800], ['refund', 'refund:12', 4800], ['chargeback', 'chargeback:3', 15000]],
            array_map(static fn(SaleMovement $m): array => [$m->kind, $m->key, $m->amountMinor], $sale->movements));
        self::assertSame([[3636, 11364], [1164, 3636]], [[$sale->lines[0]->refundedMinor, $sale->lines[0]->chargedBackMinor], [$sale->lines[1]->refundedMinor, $sale->lines[1]->chargedBackMinor]],
            'The money taken back shared over the lines by what each was paid.');
        self::assertSame(['order_created', 'paid', 'refunded', 'charged_back'], array_map(static fn($event): string => $event->kind, $this->marketing->events->forSale($sale->id)));
        $event = $this->marketing->events->byKey('site:example-org:sale_paid:EX-1001');
        self::assertSame([$click->visitorId, $campaign->id, $click->publicationId, $click->touchId, 'example-org'],
            [$event?->visitorId, $event?->campaignId, $event?->publicationId, $event?->touchId, $event?->originSite]);
        self::assertSame(['reported', 'reported', 'reported', 'reported'],
            array_map(static fn(SaleChanged $change): string => $change->change, $this->marketing->dispatcher->of(SaleChanged::class)), 'Each report announced, its repeats not.');
    }

    public function testAnOlderReportChangesNoStateOrMoneyAndAClearedFlagClearsOnlyUntilPaid(): void
    {
        $this->marketing->campaign('Law in November', 'law-nov26');
        $click = $this->shop->click('law-nov26');
        $reference = $this->reference($click);
        $sales = $this->marketing->sales;
        $sales->reported($this->site, $this->report('order_placed', 'waiting', $reference));
        $sales->reported($this->site, $this->report('order_placed', 'waiting', $reference, first: true, last: false, key: 'order_placed:EX-1001:again'));
        $moved = $sales->get('EX-1001', 'example-org');
        self::assertSame([$click->touchId, null], [$moved?->firstTouchId, $moved?->lastTouchId], 'No longer its last touch there.');

        $sales->reported($this->site, $this->report('sale_paid', 'paid', $reference, paid: 19800, first: true, last: false));
        $sales->reported($this->site, $this->report('order_placed', 'waiting', $reference, key: 'order_placed:EX-1001:late'));
        $sales->reported($this->site, $this->report('order_cancelled', 'cancelled', $reference));
        $paid = $sales->get('EX-1001', 'example-org');
        self::assertSame(['paid', 19800, $click->touchId, $click->touchId, 'site', true], [$paid?->state, $paid?->paidMinor, $paid?->firstTouchId, $paid?->lastTouchId, $paid?->lastBy, $paid?->fixed],
            'Paid stays paid; once fixed a side is only filled in.');
    }

    public function testAnotherCurrencyForASaleKeptIsRefusedAndTheSameReferenceHereIsItsOwn(): void
    {
        $this->marketing->campaign('Law in November', 'law-nov26');
        $reference = $this->reference($this->shop->click('law-nov26'));
        $sales = $this->marketing->sales;
        $sales->reported($this->site, $this->report('order_placed', 'waiting', $reference));
        self::assertSame(Sales::REFUSED, $sales->reported($this->site, $this->report('sale_paid', 'paid', $reference, paid: 19800, currency: 'USD')));
        self::assertSame('waiting', $sales->get('EX-1001', 'example-org')?->state);
        self::assertStringContainsString('its currency is not the one the sale was kept in', implode("\n", $this->marketing->logger->lines));
        $here = $sales->paid($this->shop->report('EX-1001'), $this->shop->now());
        self::assertSame([null, 'paid'], [$here?->originSite, $here?->state]);
        $mine = $sales->get('EX-1001');
        $theirs = $sales->get('EX-1001', 'example-org');
        self::assertSame(['paid', 'waiting'], [$mine?->state, $theirs->state]);
        self::assertTrue($sales->has('EX-1001', 'waiting', 'example-org'));
    }

    public function testAReportMadeOfASaleHereIsReadBackFromItsBody(): void
    {
        $this->marketing->campaign('Law in November', 'law-nov26');
        $click = $this->shop->click('law-nov26');
        $sales = $this->marketing->sales;
        $sales->placed($this->shop->report('ORD-4001', lines: [MarketingSalesShop::tea(), MarketingSalesShop::giftBox()]), $this->shop->browser(MarketingSalesShop::token($click)));
        $sale = $sales->paid($this->shop->report('ORD-4001', lines: [MarketingSalesShop::tea(), MarketingSalesShop::giftBox()]), $this->shop->now());
        self::assertNotNull($sale);
        $sent = ReportedSale::of($sale, 'sale_paid', 'sale_paid:ORD-4001', $this->shop->now(), $this->reference($click), true, true);
        $received = ReportedSale::fromBody(json_decode((string) json_encode($sent->body()), true));
        self::assertSame(Sales::RECORDED, $sales->reported($this->site, $received));
        $copy = $sales->get('ORD-4001', 'example-org');
        self::assertSame([$sale->paidMinor, $sale->listMinor, $sale->discountMinor, ['line:1', 'line:2'], ['Rooibos tea, 100 bags', 'Gift box']],
            [$copy?->paidMinor, $copy?->listMinor, $copy?->discountMinor, array_map(static fn(SaleLine $line): string => $line->key, $copy->lines ?? []),
                array_map(static fn(SaleLine $line): string => $line->label, $copy->lines ?? [])]);
    }

    public function testAFaultRollsBackToItsOwnSavepointAndIsThrownForTheReceiver(): void
    {
        $this->marketing->campaign('Law in November', 'law-nov26');
        $reference = $this->reference($this->shop->click('law-nov26'));
        $this->connection->executeStatement('CREATE TABLE reports_received (key text PRIMARY KEY)');
        $this->marketing->dispatcher->listener = function (object $event): void {
            $this->connection->executeQuery('SELECT * FROM no_such_table');
        };
        $this->connection->beginTransaction();
        $this->connection->executeStatement("INSERT INTO reports_received VALUES ('before')");
        try {
            $this->marketing->sales->reported($this->site, $this->report('order_placed', 'waiting', $reference));
            self::fail('The fault is thrown.');
        } catch (\Throwable) {
            $this->connection->executeStatement("INSERT INTO reports_received VALUES ('after')");
        }
        $this->connection->commit();
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reports_received'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_sales'));
        self::assertStringContainsString('could not be kept; the other site will send it again', implode("\n", $this->marketing->logger->lines));
    }

    private function reference(TrackedOpening $click): string
    {
        return (string) $this->marketing->touches->byId($click->touchId)?->reference;
    }

    /** @param array{0:string,1:int}|null $movement */
    private function report(string $kind, string $state, string $click, int $paid = 0, int $refunded = 0, int $chargedBack = 0, ?array $movement = null, bool $first = true,
        bool $last = true, string $currency = 'ZAR', ?string $key = null): ReportedSale
    {
        $now = $this->shop->now();
        $lines = [new SaleLine('line:1', 'course', 'law-101', 'Labour law for employers', 'Course', 1, 16000, discountMinor: 1000),
            new SaleLine('line:2', 'course', 'law-102', 'Contracts in plain words', 'Course', 1, 4800)];

        return new ReportedSale($key ?? ($kind . ':EX-1001' . ($movement === null ? '' : ':' . $movement[0])), $kind, $now, $click, $first, $last, 'EX-1001', $currency, $state,
            new DateTimeImmutable('2026-11-09 09:00:00 UTC'), $state === 'paid' ? $now : null, $paid, $refunded, $chargedBack, $lines, $movement[0] ?? null, $movement[1] ?? null);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function snapshot(): array
    {
        $rows = [];
        foreach (['cattophp_marketing_sales', 'cattophp_marketing_sale_lines', 'cattophp_marketing_sale_movements', 'cattophp_marketing_events'] as $table) {
            $rows[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }

        return $rows;
    }
}
