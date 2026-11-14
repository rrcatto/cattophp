<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Account\Records\MarketingRecords;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Sales\SaleChanged;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleMovement;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\MarketingSalesShop;

/**
 * What the project reports about its orders, against the database: an order placed, paid, refunded in parts and charged
 * back, with its lines, movements and the journey's events; every call repeated changing nothing; a payment that mends a
 * lost placing; a paid sale that placing again leaves alone; cancelling from waiting only and an order placed again after
 * it; reports refused with their reasons logged; a fault rolling back to its own savepoint while the caller's work is
 * committed; the placing browser's visitor and country; and forgetting an account.
 */
final class MarketingSalesTest extends DatabaseTestCase
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

    public function testAnOrderPlacedPaidRefundedInTwoPartsAndChargedBackKeepsEveryMovement(): void
    {
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale');
        $this->marketing->publication('spring-sale', 'facebook', 'Page post');
        $click = $this->shop->click('spring-sale', 'fb1');
        $this->shop->later('+2 hours');
        $sales = $this->marketing->sales;

        $placed = $sales->placed($this->shop->report('ORD-1042', promotion: 'SPRING10', wallet: 5000), $this->shop->browser(MarketingSalesShop::token($click)));
        self::assertNotNull($placed);
        self::assertSame(['waiting', 'Waiting for payment', 'ZA', $click->visitorId], [$placed->state, $placed->stateWords(), $placed->country, $placed->visitorId]);
        self::assertSame([34000, 1900, 32100, 5000, 0], [$placed->listMinor, $placed->discountMinor, $placed->totalMinor, $placed->walletMinor, $placed->paidMinor]);
        self::assertSame([$campaign->id, $campaign->id, 'link', false], [$placed->firstCampaignId, $placed->lastCampaignId, $placed->lastBy, $placed->fixed], 'A provisional attribution.');
        self::assertSame(['item:1', 'item:2'], array_map(static fn(SaleLine $line): string => $line->key, $placed->lines));
        self::assertSame([17100, 15000], array_map(static fn(SaleLine $line): int => $line->paidMinor(), $placed->lines));

        $paidAt = $this->shop->later('+10 minutes');
        $paid = $sales->paid($this->shop->report('ORD-1042', promotion: 'SPRING10', wallet: 5000, placedAt: $placed->placedAt), $paidAt, 'Card');
        self::assertNotNull($paid);
        self::assertSame(['paid', 32100, true, '30d'], [$paid->state, $paid->paidMinor, $paid->fixed, $paid->countedWithin]);
        self::assertEquals($paidAt, $paid->paidAt);
        self::assertSame([['paid', 'paid', 32100, ['item:1' => 17100, 'item:2' => 15000], 'Card']],
            array_map(static fn(SaleMovement $m): array => [$m->kind, $m->key, $m->amountMinor, $m->lines, $m->how], $paid->movements));

        $sales->refunded('ORD-1042', 'refund:7', ['item:1' => 8550], $this->shop->later('+1 day'), 'Money back');
        $partly = $sales->get('ORD-1042');
        self::assertSame(['partly_refunded', 'Partly refunded', 8550, 23550], [$partly?->shownState(), $partly?->stateWords(), $partly?->refundedMinor, $partly?->netMinor()]);
        $sales->refunded('ORD-1042', 'refund:8', ['item:1' => 8550], $this->shop->later('+1 day'), 'To the wallet');
        $refunded = $sales->get('ORD-1042');
        self::assertSame([17100, 17100, 0], [$refunded?->refundedMinor, $refunded?->line('item:1')?->refundedMinor, $refunded?->line('item:1')?->netMinor()], 'The tea is refunded in full.');
        self::assertNotNull($refunded);
        self::assertSame('partly_refunded', $refunded->shownState(), 'The mug is still paid for.');

        $charged = $sales->chargedBack('ORD-1042', 'chargeback:3', 15000, $this->shop->later('+3 days'));
        self::assertNotNull($charged);
        self::assertSame(['charged_back', 'Charged back', 15000, 0], [$charged->shownState(), $charged->stateWords(), $charged->chargedBackMinor, $charged->netMinor()]);
        self::assertSame([0, 15000], [$charged->line('item:1')?->chargedBackMinor, $charged->line('item:2')?->chargedBackMinor], 'Shared by what each line had left.');
        self::assertSame(['paid:32100', 'refund:8550', 'refund:8550', 'chargeback:15000'],
            array_map(static fn(SaleMovement $m): string => $m->kind . ':' . $m->amountMinor, $charged->movements));

        $kinds = array_map(static fn($event): string => $event->kind . ':' . $event->idempotencyKey, $this->marketing->events->forSale($charged->id));
        self::assertSame(['order_created:order_created:ORD-1042', 'paid:paid:ORD-1042', 'refunded:refunded:ORD-1042:refund:7', 'refunded:refunded:ORD-1042:refund:8',
            'charged_back:charged_back:ORD-1042:chargeback:3'], $kinds);
        $paidEvent = $this->marketing->events->byKey('paid:ORD-1042');
        self::assertSame([$click->visitorId, '1', $campaign->id, $click->touchId, 'ORD-1042'], [$paidEvent?->visitorId, $paidEvent?->account, $paidEvent?->campaignId, $paidEvent?->touchId,
            $paidEvent?->detail['reference'] ?? null]);
        self::assertSame(['placed', 'paid', 'refunded', 'refunded', 'charged_back'],
            array_map(static fn(SaleChanged $change): string => $change->change, $this->marketing->dispatcher->of(SaleChanged::class)));
        self::assertSame('chargeback:3', $this->marketing->dispatcher->of(SaleChanged::class)[4]->movement?->key);
        self::assertTrue($sales->has('ORD-1042'));
        self::assertTrue($sales->hasMovement('ORD-1042', 'refund:8'));
        self::assertFalse($sales->hasMovement('ORD-1042', 'refund:9'));
        self::assertFalse($sales->has('ORD-1042', 'waiting'));
        self::assertTrue($sales->has('ORD-1042', 'any'));
    }

    public function testEveryCallRepeatedChangesNothing(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $click = $this->shop->click('spring-sale');
        $sales = $this->marketing->sales;
        $report = $this->shop->report('ORD-1043');
        $browser = $this->shop->browser(MarketingSalesShop::token($click));
        $calls = [
            fn() => $sales->placed($report, $browser),
            fn() => $sales->failed('ORD-1043', 'pay:1', 'The card was declined by the bank.', $this->shop->now()),
            fn() => $sales->paid($report, $this->shop->now(), 'Card'),
            fn() => $sales->refunded('ORD-1043', 'refund:1', ['item:2' => 5000], $this->shop->now()),
            fn() => $sales->chargedBack('ORD-1043', 'chargeback:1', 2000, $this->shop->now()),
        ];
        foreach ($calls as $call) {
            $call();
            $this->shop->later('+1 hour');
            $before = $this->snapshot();
            $announced = count($this->marketing->dispatcher->events);
            $call();
            self::assertSame($before, $this->snapshot());
            self::assertCount($announced, $this->marketing->dispatcher->events, 'A repeat announces nothing.');
        }
        self::assertSame([32100, 5000, 2000], [$sales->get('ORD-1043')?->paidMinor, $sales->get('ORD-1043')?->refundedMinor, $sales->get('ORD-1043')?->chargedBackMinor]);
        $sales->cancelled('ORD-1043', $this->shop->now());
        self::assertSame('paid', $sales->get('ORD-1043')?->state, 'A paid sale is never cancelled.');
    }

    public function testAPaymentWithoutAPlacingWritesTheSale(): void
    {
        $paid = $this->marketing->sales->paid($this->shop->report('ORD-1044', account: null), $this->shop->now(), 'EFT');
        self::assertNotNull($paid);
        self::assertSame(['paid', 32100, null, null, 2], [$paid->state, $paid->paidMinor, $paid->account, $paid->lastCampaignId, count($paid->lines)]);
        self::assertSame([], $this->marketing->events->forSale($paid->id), 'Nobody followed placed it: no journey events, the sale is kept all the same.');
        self::assertSame(['paid'], array_map(static fn(SaleChanged $change): string => $change->change, $this->marketing->dispatcher->of(SaleChanged::class)));
    }

    public function testAPaymentMendsALostPlacingForAFollowedPersonAndPlacingAfterPaymentChangesNothing(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $click = $this->shop->click('spring-sale');
        self::assertNotNull($click->visitorId);
        $this->marketing->visitors->identify($click->visitorId, '1');
        $paid = $this->marketing->sales->paid($this->shop->report('ORD-1045'), $this->shop->later('+1 hour'));
        self::assertNotNull($paid);
        self::assertSame(['order_created', 'paid'], array_map(static fn($event): string => $event->kind, $this->marketing->events->forSale($paid->id)));

        $before = $this->snapshot();
        $again = $this->marketing->sales->placed($this->shop->report('ORD-1045', lines: [MarketingSalesShop::mug()]), $this->shop->browser(MarketingSalesShop::token($click)));
        self::assertSame($before, $this->snapshot(), 'A paid sale keeps what it was paid for.');
        self::assertSame(32100, $again?->totalMinor);
    }

    public function testAWaitingSaleFollowsItsDescriptionAndIsCancelledOnlyWhileWaitingAndPlacedAgainAfter(): void
    {
        $sales = $this->marketing->sales;
        $sales->placed($this->shop->report('ORD-1046'));
        $changed = $sales->placed($this->shop->report('ORD-1046', lines: [MarketingSalesShop::giftBox()]));
        self::assertNotNull($changed);
        self::assertSame([32000, ['gift-box']], [$changed->totalMinor, array_map(static fn(SaleLine $line): string => $line->offerReference, $changed->lines)]);
        self::assertSame(['product:tea-100', 'product:mug-01'], $changed->lines[0]->contains);

        $cancelled = $sales->cancelled('ORD-1046', $this->shop->later('+3 days'), 'Not paid by its deadline.');
        self::assertSame(['cancelled', 'Cancelled'], [$cancelled?->state, $cancelled?->stateWords()]);
        self::assertNotNull($cancelled);
        self::assertNotNull($cancelled->cancelledAt);
        $before = $this->snapshot();
        $sales->cancelled('ORD-1046', $this->shop->later('+1 day'));
        self::assertSame($before, $this->snapshot(), 'Cancelled once.');

        $again = $sales->placed($this->shop->report('ORD-1046', lines: [MarketingSalesShop::giftBox()]));
        self::assertSame(['waiting', null], [$again?->state, $again?->cancelledAt], 'An order placed again is waiting again.');
        $sales->cancelled('ORD-1046', $this->shop->later('+3 days'));
        self::assertSame('cancelled', $sales->get('ORD-1046')?->state);
        self::assertNull($sales->cancelled('ORD-9999', $this->shop->now()), 'An order not known.');
        self::assertFalse($sales->failed('ORD-9999', 'pay:1', 'Declined.', $this->shop->now()));
        self::assertSame(['placed', 'placed', 'cancelled', 'placed', 'cancelled'],
            array_map(static fn(SaleChanged $change): string => $change->change, $this->marketing->dispatcher->of(SaleChanged::class)));
    }

    public function testAFollowedPersonsCancellationsAreEachKeptByTheirCount(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $click = $this->shop->click('spring-sale');
        $browser = $this->shop->browser(MarketingSalesShop::token($click));
        $sales = $this->marketing->sales;
        $sales->placed($this->shop->report('ORD-1047'), $browser);
        $sales->failed('ORD-1047', 'pay:1', 'The card was declined by the bank.', $this->shop->now());
        $sales->cancelled('ORD-1047', $this->shop->later('+3 days'));
        $sales->placed($this->shop->report('ORD-1047'), $browser);
        $sales->cancelled('ORD-1047', $this->shop->later('+3 days'));
        $sale = $sales->get('ORD-1047');
        self::assertNotNull($sale);
        self::assertSame(['order_created:ORD-1047', 'payment_failed:ORD-1047:pay:1', 'order_cancelled:ORD-1047:1', 'order_cancelled:ORD-1047:2'],
            array_map(static fn($event): ?string => $event->idempotencyKey, $this->marketing->events->forSale($sale->id)));
        self::assertSame('The card was declined by the bank.', $this->marketing->events->byKey('payment_failed:ORD-1047:pay:1')?->detail['why'] ?? null);
    }

    public function testAReportThatDoesNotAddUpIsRefusedWithItsReasonLogged(): void
    {
        $wrong = new SaleReport('ORD-1048', '1', null, $this->shop->now(), 'ZAR', 30000, lines: [MarketingSalesShop::tea(), MarketingSalesShop::mug()]);
        self::assertNull($this->marketing->sales->placed($wrong));
        self::assertNull($this->marketing->sales->paid($wrong, $this->shop->now()));
        self::assertFalse($this->marketing->sales->has('ORD-1048', 'any'));
        self::assertStringContainsString('The lines\' prices less their discounts come to 32100, not the 30000 to be paid.', implode("\n", $this->marketing->logger->lines));
        self::assertStringContainsString('error: The Campaign Marketing Manager refused the report of sale ORD-1048 (placed)', implode("\n", $this->marketing->logger->lines));
        self::assertSame([], $this->marketing->dispatcher->events);
    }

    public function testMoneyTakenBackIsRefusedBeyondWhatWasPaidAndBeforePayment(): void
    {
        $sales = $this->marketing->sales;
        $sales->placed($this->shop->report('ORD-1049'));
        self::assertNull($sales->refunded('ORD-1049', 'refund:1', ['item:1' => 100], $this->shop->now()), 'Not paid yet.');
        $sales->paid($this->shop->report('ORD-1049'), $this->shop->now());
        $before = $this->snapshot();
        self::assertNull($sales->refunded('ORD-1049', 'refund:2', ['item:2' => 15001], $this->shop->now()), 'More than the mug was paid.');
        self::assertNull($sales->refunded('ORD-1049', 'refund:3', ['item:9' => 100], $this->shop->now()), 'No such line.');
        self::assertNull($sales->refunded('ORD-1049', 'refund:4', [], $this->shop->now()), 'Nothing.');
        self::assertNull($sales->chargedBack('ORD-1049', 'chargeback:1', 32101, $this->shop->now()), 'More than was paid.');
        self::assertNull($sales->chargedBack('ORD-1049', 'chargeback:2', 100, $this->shop->now(), ['item:1' => 60]), 'Lines not adding up to the amount.');
        self::assertNull($sales->refunded('ORD-9999', 'refund:1', ['item:1' => 100], $this->shop->now()), 'An order not known.');
        self::assertSame($before, $this->snapshot());
        $sales->refunded('ORD-1049', 'refund:5', ['item:2' => 15000], $this->shop->now());
        self::assertNull($sales->chargedBack('ORD-1049', 'chargeback:3', 17101, $this->shop->now()), 'More than is left after the refund.');
        $charged = $sales->chargedBack('ORD-1049', 'chargeback:4', 17100, $this->shop->now());
        self::assertSame([17100, 0], [$charged?->line('item:1')?->chargedBackMinor, $charged?->line('item:2')?->chargedBackMinor]);
        self::assertNotNull($charged);
        self::assertSame(0, $charged->netMinor());
        self::assertCount(8, array_filter($this->marketing->logger->lines, static fn(string $line): bool => str_starts_with($line, 'error: The Campaign Marketing Manager')));
    }

    public function testAChargebackWithoutLinesIsSharedByLargestRemainderAndAddsUpExactly(): void
    {
        $lines = [new SaleLine('a', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 1, 100), new SaleLine('b', 'product', 'mug-01', 'Enamel mug', 'Product', 1, 100),
            new SaleLine('c', 'bundle', 'gift-box', 'Gift box', 'Bundle', 1, 100)];
        $this->marketing->sales->paid($this->shop->report('ORD-1050', lines: $lines), $this->shop->now());
        $charged = $this->marketing->sales->chargedBack('ORD-1050', 'chargeback:1', 100, $this->shop->now());
        self::assertSame([34, 33, 33], array_map(static fn(SaleLine $line): int => $line->chargedBackMinor, $charged->lines ?? []));
        self::assertSame(['a' => 34, 'b' => 33, 'c' => 33], $charged?->movements[1]->lines);
    }

    public function testAFaultRollsBackToItsOwnSavepointAndTheCallersWorkIsCommitted(): void
    {
        $this->connection->executeStatement('CREATE TABLE shop_orders (reference text PRIMARY KEY)');
        // A listener that breaks the database's transaction, as a fault inside recording would.
        $this->marketing->dispatcher->listener = function (object $event): void {
            if ($event instanceof SaleChanged) {
                $this->connection->executeQuery('SELECT * FROM no_such_table');
            }
        };
        $this->connection->beginTransaction();
        $this->connection->executeStatement("INSERT INTO shop_orders VALUES ('ORD-1051')");
        self::assertNull($this->marketing->sales->placed($this->shop->report('ORD-1051')));
        $this->connection->executeStatement("INSERT INTO shop_orders VALUES ('ORD-1052')");
        self::assertNull($this->marketing->sales->paid($this->shop->report('ORD-1052'), $this->shop->now()));
        $this->connection->commit();

        self::assertSame(['ORD-1051', 'ORD-1052'], array_map('strval', $this->connection->fetchFirstColumn('SELECT reference FROM shop_orders ORDER BY reference')));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_sales'), 'The sale went with its savepoint.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_sale_lines'));
        self::assertStringContainsString('error: The Campaign Marketing Manager could not keep an order placed for sale ORD-1051; the purchase went on',
            implode("\n", $this->marketing->logger->lines));

        $this->marketing->dispatcher->listener = null;
        self::assertNotNull($this->marketing->sales->paid($this->shop->report('ORD-1052'), $this->shop->now()), 'Reported again, it is kept.');
    }

    public function testThePlacingBrowsersVisitorAndCountryAreKeptUnlessItAsksNotToBeTracked(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $token = MarketingSalesShop::token($this->shop->click('spring-sale'));
        $kept = $this->marketing->sales->placed($this->shop->report('ORD-1053', account: '2'), $this->shop->browser($token));
        self::assertSame(['ZA', $this->marketing->visitors->byToken($token)?->id], [$kept?->country, $kept?->visitorId]);
        $notTracked = $this->marketing->sales->placed($this->shop->report('ORD-1054', account: '2'), $this->shop->browser($token, headers: ['HTTP_DNT' => '1']));
        self::assertSame(['ZA', null], [$notTracked?->country, $notTracked?->visitorId]);
        $bot = $this->marketing->sales->placed($this->shop->report('ORD-1055', account: '2'), $this->shop->browser($token, MarketingSalesShop::PREVIEW));
        self::assertNull($bot?->visitorId);
        self::assertNotNull($bot);
        self::assertNull($bot->lastCampaignId, 'No visitor, and the account has none: no campaign.');
    }

    public function testForgettingAnAccountKeepsItsSalesNamingNobody(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $click = $this->shop->click('spring-sale');
        $this->marketing->sales->paid($this->shop->report('ORD-1056', account: '7'), $this->shop->now());
        $this->marketing->sales->placed($this->shop->report('ORD-1057', account: '8'), $this->shop->browser(MarketingSalesShop::token($click)));
        $records = new MarketingRecords($this->marketing->visitors, $this->connection, $this->marketing->events);
        self::assertSame(1, $records->forgetAccount('7', null)['unnamed']['campaign sales'] ?? null);
        $forgotten = $this->marketing->sales->get('ORD-1056');
        self::assertSame([null, 32100, 'paid'], [$forgotten?->account, $forgotten?->paidMinor, $forgotten?->state]);
        self::assertSame(1, $this->marketing->sales->forgetAccount('8'));
        self::assertSame(0, $this->marketing->sales->forgetAccount('8'));

        $this->marketing->sales->placed($this->shop->report('ORD-1058', account: '9'), $this->shop->browser(MarketingSalesShop::token($click)));
        $visitor = $this->marketing->visitors->byToken(MarketingSalesShop::token($click));
        self::assertNotNull($visitor);
        $this->marketing->visitors->anonymise($visitor, $this->shop->now());
        self::assertSame(1, $this->marketing->sales->forgetAccountsOfAnonymousVisitors());
        self::assertSame([null, 32100], [$this->marketing->sales->get('ORD-1058')?->account, $this->marketing->sales->get('ORD-1058')?->totalMinor]);
    }

    public function testAForgottenCustomerIsStillOneCustomerAndNamedByNothing(): void
    {
        $sales = $this->marketing->sales;
        foreach (['ORD-1061' => '7', 'ORD-1062' => '7', 'ORD-1063' => '8', 'ORD-1064' => '8', 'ORD-1065' => '9'] as $reference => $account) {
            $sales->paid($this->shop->report($reference, account: $account), $this->shop->now());
        }
        $customers = fn(): int => $sales->totals(new SaleFilter(where: SaleFilter::HERE))->payingCustomers;
        self::assertSame(3, $customers());

        (new MarketingRecords($this->marketing->visitors, $this->connection, $this->marketing->events))->forgetAccount('7', null);
        self::assertSame(2, $sales->forgetAccount('8'));
        self::assertSame(3, $customers(), 'Each forgotten person still counts once, however many sales they paid for.');

        $marks = $this->connection->fetchAllKeyValue("SELECT reference, forgotten_customer FROM cattophp_marketing_sales WHERE reference LIKE 'ORD-106%' ORDER BY reference");
        self::assertSame(['ORD-1061', 'ORD-1062', 'ORD-1063', 'ORD-1064', 'ORD-1065'], array_keys($marks));
        self::assertSame($marks['ORD-1061'], $marks['ORD-1062']);
        self::assertSame($marks['ORD-1063'], $marks['ORD-1064']);
        self::assertNotSame($marks['ORD-1061'], $marks['ORD-1063'], 'Two forgotten people are two customers.');
        self::assertNull($marks['ORD-1065'], 'A person not forgotten has no mark.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $marks['ORD-1061']);
        self::assertSame([null, null], [$sales->get('ORD-1061')?->account, $sales->get('ORD-1063')?->account]);

        // Visitors made anonymous: the sales they placed for one account keep one mark together.
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $click = $this->shop->click('spring-sale');
        $token = MarketingSalesShop::token($click);
        $sales->placed($this->shop->report('ORD-1066', account: '10'), $this->shop->browser($token));
        $sales->placed($this->shop->report('ORD-1067', account: '10'), $this->shop->browser($token));
        $sales->paid($this->shop->report('ORD-1066', account: '10'), $this->shop->now());
        $sales->paid($this->shop->report('ORD-1067', account: '10'), $this->shop->now());
        self::assertSame(4, $customers());
        $visitor = $this->marketing->visitors->byToken($token);
        self::assertNotNull($visitor);
        $this->marketing->visitors->anonymise($visitor, $this->shop->now());
        self::assertSame(2, $sales->forgetAccountsOfAnonymousVisitors());
        self::assertSame(4, $customers());
    }

    /** @return array<string,list<array<string,mixed>>> every row of the sales' tables and the events, as they are */
    private function snapshot(): array
    {
        $rows = [];
        foreach (['cattophp_marketing_sales', 'cattophp_marketing_sale_lines', 'cattophp_marketing_sale_movements', Events::TABLE] as $table) {
            $rows[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }

        return $rows;
    }
}
