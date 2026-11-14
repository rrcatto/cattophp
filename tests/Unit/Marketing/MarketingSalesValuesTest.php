<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\Journey\EventInput;
use CattoPHP\Marketing\Journey\JourneyRecorded;
use CattoPHP\Marketing\JourneyEvent;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\Sales\ReportedSale;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\SaleChanged;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleMovement;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Marketing\Sites\Site;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The values step 3's sales and connected sites are described by: a project's report of an order and its lines (never
 * refused as they are made, so building one never stops a purchase; their problems in words), a sale as kept and what it
 * is shown as, its money's movements, the filter of a list of sales, a connected site's report about a sale and its
 * body, a connected site, and the two events announced.
 */
final class MarketingSalesValuesTest extends TestCase
{
    public function testAReportWhoseLinesAddUpHasNoProblem(): void
    {
        $report = $this->report();
        self::assertSame([], $report->problems());
        self::assertSame([45000, 5000], [$report->listMinor(), $report->discountMinor()]);
        self::assertSame(40000, $report->totalMinor);
        self::assertSame('bundle:gift-box', $report->line('item:2')?->offerKey());
        self::assertNull($report->line('item:9'));
        self::assertSame([25000, 25000], [$report->lines[0]->paidMinor(), $report->lines[0]->netMinor()]);
    }

    public function testAReportWhoseLinesDoNotAddUpIsRefusedInWords(): void
    {
        $report = $this->report(total: 41000);
        self::assertSame(['The lines\' prices less their discounts come to 40000, not the 41000 to be paid.'], $report->problems());
        $none = new SaleReport('ORD-1042', '7', null, new DateTimeImmutable('2026-11-14 09:00'), 'ZAR', 0);
        self::assertSame(['A sale has at least one line.'], $none->problems());
        $twice = new SaleReport('ORD-1042', '7', null, new DateTimeImmutable('2026-11-14 09:00'), 'ZAR', 500, lines: [
            new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea', 'Product', 1, 250), new SaleLine('item:1', 'product', 'mug-01', 'Enamel mug', 'Product', 1, 250)]);
        self::assertSame(['Line "item:1" is given twice: each line\'s key is its own.'], $twice->problems());
    }

    public function testEveryValueOfAReportAndALineIsChecked(): void
    {
        $bad = new SaleReport('ORD 1042', '', '', new DateTimeImmutable('2026-11-14 09:00'), 'zar', 100, 200, '', [
            new SaleLine('', 'Product', ' ', '', str_repeat('k', 61), 0, 100, discountMinor: 150, contains: ['not a key'])]);
        self::assertSame([
            'A sale\'s reference is 1 to 60 characters with no spaces.',
            'A sale\'s account is null or names one.',
            'A sale\'s organisation is null or 1 to 120 characters.',
            'A sale\'s currency is three capital letters, such as ZAR: "zar" is not.',
            'A sale\'s promotion code is null or 1 to 120 characters.',
            'A sale\'s wallet credit is more than is to be paid.',
            'A line\'s key is 1 to 120 characters.',
            'A line\'s offer type is small letters, digits, underscores and hyphens, such as product.',
            'A line\'s offer reference is 1 to 120 characters.',
            'A line\'s label is 1 to 200 characters.',
            'A line\'s kind label is at most 60 characters.',
            'A line\'s quantity is 1 or more.',
            'A line\'s discount is more than its price.',
            'A line\'s offers held are each type:reference, such as product:t-100.',
            'The lines\' prices less their discounts come to -50, not the 100 to be paid.',
        ], $bad->problems());
        $negative = new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea', 'Product', 1, -1);
        self::assertSame(['Line "item:1"\'s amounts are nothing or more.'], $negative->problems());
        $wallet = new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea', 'Product', 1, 100, walletMinor: 101);
        self::assertSame(['Line "item:1"\'s wallet credit is more than is paid for it.'], $wallet->problems());
        $takenBack = new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea', 'Product', 1, 100, refundedMinor: 60, chargedBackMinor: 41);
        self::assertSame(['Line "item:1"\'s refunds and chargebacks are more than was paid for it.'], $takenBack->problems());
    }

    public function testTheLinesWalletCreditAddsUpToTheSalesWhenTheyGiveIt(): void
    {
        $at = new DateTimeImmutable('2026-11-14 09:00');
        $line = static fn(int $wallet): SaleLine => new SaleLine('item:' . $wallet, 'product', 'tea-' . $wallet, 'Rooibos tea', 'Product', 1, 1000, walletMinor: $wallet);
        self::assertSame([], (new SaleReport('ORD-1', null, null, $at, 'ZAR', 2000, 300, lines: [$line(0), new SaleLine('b', 'product', 'mug', 'Mug', 'Product', 1, 1000)]))->problems(),
            'The sale alone may say how much wallet credit paid for it.');
        self::assertSame([], (new SaleReport('ORD-1', null, null, $at, 'ZAR', 2000, 300, lines: [$line(100), $line(200)]))->problems());
        self::assertSame(['The lines\' wallet credit comes to 250, not the sale\'s 300.'], (new SaleReport('ORD-1', null, null, $at, 'ZAR', 2000, 300, lines: [$line(100), $line(150)]))->problems());
    }

    public function testASaleIsShownByItsStateAndItsMoney(): void
    {
        self::assertSame(['waiting', 'Waiting for payment'], [$this->sale('waiting')->shownState(), $this->sale('waiting')->stateWords()]);
        self::assertSame('cancelled', $this->sale('cancelled')->shownState());
        self::assertSame(['paid', 40000], [$this->sale('paid')->shownState(), $this->sale('paid')->netMinor()]);
        self::assertSame(['partly_refunded', 30000], [$this->sale('paid', refunded: 10000)->shownState(), $this->sale('paid', refunded: 10000)->netMinor()]);
        self::assertSame('refunded', $this->sale('paid', refunded: 40000)->shownState());
        self::assertSame(['charged_back', 'Charged back'], [$this->sale('paid', refunded: 10000, chargedBack: 5000)->shownState(), $this->sale('paid', chargedBack: 5000)->stateWords()]);
        $sale = $this->sale('paid');
        self::assertSame([3, 4, 30, 40, 'link', 'promotion'], [$sale->campaignId('first'), $sale->campaignId('last'), $sale->publicationId('first'), $sale->publicationId('last'),
            $sale->by('first'), $sale->by('last')]);
        self::assertSame([300, 400], [$sale->touchId('first'), $sale->touchId('last')]);
        self::assertFalse($sale->isFromSite());
        self::assertTrue($sale->isPaid());
        $this->expectException(\InvalidArgumentException::class);
        $this->sale('refunded');
    }

    public function testAMovementsLinesAddUpToItsAmount(): void
    {
        $at = new DateTimeImmutable('2026-11-14 10:00');
        $refund = new SaleMovement(1, 9, 'refund', 'refund:31', 10000, $at, ['item:1' => 6000, 'item:2' => 4000], 'Money back');
        self::assertSame(['Refund', true], [$refund->kindWords(), $refund->takesBack()]);
        self::assertFalse((new SaleMovement(2, 9, 'paid', 'paid', 40000, $at))->takesBack());
        foreach ([['given', 'paid', 1, []], ['refund', '', 1, []], ['refund', 'refund:1', -1, []], ['refund', 'refund:1', 100, ['item:1' => 60]],
            ['refund', 'refund:1', 100, ['item:1' => 120, 'item:2' => -20]]] as [$kind, $key, $amount, $lines]) {
            try {
                new SaleMovement(3, 9, $kind, $key, $amount, $at, $lines);
                self::fail('A movement that cannot be was made: ' . $kind . ' ' . $key);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAFilterRefusesWhatCannotBeAndIsChangedOneThingAtATime(): void
    {
        $filter = new SaleFilter(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-12-01'), campaign: 3);
        self::assertSame(['paid', 3, 'last', 'first', false], [$filter->state, $filter->campaignId(), $filter->model('last'), $filter->withModel('first')->model('last'),
            $filter->narrowsByTouch()]);
        self::assertSame([SaleFilter::NO_CAMPAIGN, null, 'all', 'here'], [$filter->withCampaign('none')->campaign, $filter->withCampaign('none')->campaignId(),
            $filter->withState('all')->state, $filter->withWhere('here')->where]);
        self::assertTrue((new SaleFilter(channel: 'facebook'))->narrowsByTouch());
        self::assertSame('2026-11-01', $filter->withState('waiting')->from?->format('Y-m-d'), 'The rest is kept.');
        foreach ([
            static fn(): SaleFilter => new SaleFilter(new DateTimeImmutable('2026-12-01'), new DateTimeImmutable('2026-11-01')),
            static fn(): SaleFilter => new SaleFilter(state: 'refunded'),
            static fn(): SaleFilter => new SaleFilter(campaign: 'some'),
            static fn(): SaleFilter => new SaleFilter(model: 'middle'),
            static fn(): SaleFilter => new SaleFilter(offerType: 'product'),
            static fn(): SaleFilter => new SaleFilter(country: 'za'),
            static fn(): SaleFilter => new SaleFilter(where: 'Not A Key'),
        ] as $n => $make) {
            try {
                $make();
                self::fail('A filter that cannot be was made: ' . $n);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAReportAboutASaleGoesAsABodyAndComesBackTheSame(): void
    {
        $sale = $this->sale('paid', refunded: 10000);
        $movement = new SaleMovement(5, $sale->id, 'refund', 'refund:31', 10000, new DateTimeImmutable('2026-11-15 08:00:00+00:00'));
        $report = ReportedSale::of($sale, 'sale_refunded', 'sale_refunded:ORD-1042:refund:31', new DateTimeImmutable('2026-11-15 08:00:01+02:00'), 'k7m2q9d4xa', false, true, $movement);
        $body = $report->body();
        self::assertSame(['version' => 1, 'key' => 'sale_refunded:ORD-1042:refund:31', 'kind' => 'sale_refunded', 'at' => '2026-11-15T06:00:01+00:00', 'click' => 'k7m2q9d4xa',
            'first' => false, 'last' => true], array_slice($body, 0, 7));
        self::assertSame(['key' => 'refund:31', 'amount_minor' => 10000], $body['movement']);
        $json = json_decode(json_encode($body, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        $back = ReportedSale::fromBody($json);
        self::assertSame($body, $back->body(), 'What is read is what was sent.');
        self::assertSame(['line:1', 'line:2'], array_map(static fn(SaleLine $line): string => $line->key, $back->lines), 'The lines are named by their place, never by the other site\'s keys.');
        self::assertSame([25000, 0, 20000, 5000], [$back->lines[0]->priceMinor, $back->lines[0]->discountMinor, $back->lines[1]->priceMinor, $back->lines[1]->discountMinor]);
        self::assertStringNotContainsString('"7"', (string) json_encode($body), 'No account goes with it.');
    }

    public function testAReportThatIsNotOneIsRefusedInWords(): void
    {
        $body = ReportedSale::of($this->sale('paid'), 'sale_paid', 'sale_paid:ORD-1042', new DateTimeImmutable('2026-11-15 08:00'), 'k7m2q9d4xa', true, true)->body();
        $refused = static function (array $body): string {
            try {
                ReportedSale::fromBody($body);

                return 'not refused';
            } catch (\InvalidArgumentException $refusal) {
                return $refusal->getMessage();
            }
        };
        self::assertSame('A connected site\'s report about a sale was refused: it is not of version 1.', $refused(['version' => 2] + $body));
        self::assertSame('A connected site\'s report about a sale was refused: it holds no sale with its lines.', $refused(['sale' => 'x'] + $body));
        self::assertSame('A connected site\'s report about a sale was refused: its click reference is 8 to 10 small letters and digits; its click is the sale\'s first touch, its last or both.',
            $refused(['click' => 'Robert', 'first' => false, 'last' => false] + $body));
        $sale = $body['sale'];
        self::assertIsArray($sale);
        self::assertSame('A connected site\'s report about a sale was refused: its paid minor is not a whole number.', $refused(['sale' => ['paid_minor' => '400'] + $sale] + $body));
        self::assertSame('A connected site\'s report about a sale was refused: a paid sale has its moment of payment, and only a paid sale.',
            $refused(['sale' => ['paid_at' => null] + $sale] + $body));
        self::assertSame('A connected site\'s report about a sale was refused: its paid at is not a moment such as 2026-11-14T09:30:00+00:00.',
            $refused(['sale' => ['paid_at' => 'yesterday'] + $sale] + $body));
        self::assertSame('A connected site\'s report about a sale was refused: a refund or chargeback, and only one, has its movement\'s key and amount.',
            $refused(['kind' => 'sale_refunded'] + $body));
        self::assertSame('A connected site\'s report about a sale was refused: its refunds and chargebacks are more than was paid.',
            $refused(['sale' => ['refunded_minor' => 50000] + $sale] + $body));
    }

    public function testAConnectedSiteIsNamedByItsKeyAndKnownByItsHost(): void
    {
        $checked = new DateTimeImmutable('2026-11-14 09:00');
        $site = new Site('example-org', 'Example', 'https://Example.org/', 'sealed', lastCheckedAt: $checked);
        self::assertSame(['example.org', 'https://Example.org', true, false, false], [$site->host(), $site->base(), $site->isConnected(), $site->isChangingSecret(), $site->isRemoved()]);
        $troubled = new Site('example-org', 'Example', 'https://example.org', 'sealed', 'older', lastCheckedAt: $checked, lastProblem: 'The other site could not be reached.',
            lastProblemAt: $checked->modify('+1 minute'));
        self::assertSame([false, true], [$troubled->isConnected(), $troubled->isChangingSecret()]);
        self::assertFalse((new Site('example-org', 'Example', 'https://example.org', 'sealed'))->isConnected(), 'Never checked.');
        self::assertSame('marketing_site', Site::SUBJECT);
        foreach ([['Example Org', 'https://example.org'], ['example-org', 'http://example.org'], ['example-org', 'https://example .org']] as [$key, $address]) {
            try {
                new Site($key, 'Example', $address, 'sealed');
                self::fail('A site that cannot be was made: ' . $key . ' ' . $address);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheEventsAnnouncedCarryWhatChanged(): void
    {
        $sale = $this->sale('paid');
        $movement = new SaleMovement(5, $sale->id, 'chargeback', 'chargeback:3', 5000, new DateTimeImmutable('2026-11-20'));
        $changed = new SaleChanged($sale, SaleChanged::CHARGED_BACK, $movement);
        self::assertSame([$sale, 'charged_back', $movement], [$changed->sale, $changed->change, $changed->movement]);
        $event = new JourneyEvent(1, 'offer_viewed', new DateTimeImmutable('2026-11-14'), 4, null, 3, null, 300, null, 'product', 'tea-100', [], 'ZA', false, saleId: null);
        $offer = new Offer('product', 'tea-100', 'Rooibos tea');
        $recorded = new JourneyRecorded($event, $offer);
        self::assertSame([$event, 'Rooibos tea'], [$recorded->event, $recorded->offer?->label]);
        $input = new EventInput('paid', new DateTimeImmutable('2026-11-14'), account: '7', idempotencyKey: 'paid:ORD-1042', saleId: 9);
        self::assertSame(['paid', '7', 'paid:ORD-1042', 9, ''], [$input->kind, $input->account, $input->idempotencyKey, $input->saleId, $input->country]);
        $this->expectException(\InvalidArgumentException::class);
        new SaleChanged($sale, 'shipped');
    }

    private function report(int $total = 40000): SaleReport
    {
        return new SaleReport('ORD-1042', '7', null, new DateTimeImmutable('2026-11-14 09:00'), 'ZAR', $total, 0, 'SPRING', [
            new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 1, 25000),
            new SaleLine('item:2', 'bundle', 'gift-box', 'Gift box', 'Bundle', 1, 20000, 24000, 5000, contains: ['product:tea-100', 'product:mug-01']),
        ]);
    }

    private function sale(string $state, int $refunded = 0, int $chargedBack = 0): Sale
    {
        $at = new DateTimeImmutable('2026-11-14 09:00:00+00:00');
        $lines = [new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 1, 25000),
            new SaleLine('item:2', 'bundle', 'gift-box', 'Gift box', 'Bundle', 1, 20000, 24000, 5000, contains: ['product:tea-100'])];

        return new Sale(9, 'ORD-1042', null, '7', null, 4, 'ZA', $state, 'ZAR', 45000, 5000, 40000, 0, $state === 'paid' ? 40000 : 0, $refunded, $chargedBack, 'SPRING', $at,
            $state === 'paid' ? $at->modify('+1 hour') : null, $state === 'cancelled' ? $at->modify('+1 day') : null, $at, 300, 400, 3, 4, 30, 40, 'link', 'promotion', 4, 'no_link', '30d',
            $at, $state === 'paid', $lines);
    }
}
