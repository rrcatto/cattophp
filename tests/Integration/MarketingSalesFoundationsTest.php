<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Account\Records\MarketingRecords;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\EventInput;
use CattoPHP\Marketing\MarketingDetailWords;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\MarketingSubjectLinks;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Schema\FoundationSteps;
use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Schema\Steps\Step202611141200MarketingSales;
use CattoPHP\Schema\Steps\Step202611141500MarketingForgottenCustomers;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * What step 3 of the Campaign Marketing Manager rests on, against the database: its schema step on an empty database and
 * on one holding steps 1 and 2's records (old visitors given references), the checks that refuse money that cannot be,
 * visitors' references and their tie to the first account only, a person's visitors made anonymous, touches' references,
 * a campaign made for an arrival from a connected site, the journey's events from an input with their sale and kept once
 * by their key, the reads a journey needs, and the activity log's words and links for connected sites and visitors.
 */
final class MarketingSalesFoundationsTest extends DatabaseTestCase
{
    private MarketingHarness $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection);
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testOnAnEmptyDatabaseTheStepMakesItsTablesAndColumns(): void
    {
        $tables = array_map('strval', $this->connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'cattophp\\_marketing\\_%'"));
        foreach (['cattophp_marketing_sales', 'cattophp_marketing_sale_lines', 'cattophp_marketing_sale_movements', 'cattophp_marketing_sites', 'cattophp_marketing_reports_out',
            'cattophp_marketing_reports_in'] as $table) {
            self::assertContains($table, $tables);
        }
        self::assertSame(['cattophp_marketing_sales', 'cattophp_marketing_sale_lines', 'cattophp_marketing_sale_movements', 'cattophp_marketing_sites', 'cattophp_marketing_reports_out',
            'cattophp_marketing_reports_in'], (new Step202611141200MarketingSales())->tables());
        foreach ([['cattophp_marketing_visitors', 'reference'], ['cattophp_marketing_touches', 'reference'], ['cattophp_marketing_touches', 'origin_publication'],
            ['cattophp_marketing_campaigns', 'origin_site'], ['cattophp_marketing_events', 'sale_id']] as [$table, $column]) {
            self::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM information_schema.columns WHERE table_name = :table AND column_name = :column', ['table' => $table, 'column' => $column]),
                $table . '.' . $column);
        }
        self::assertSame([], $this->runner()->migrate(), 'Applied once.');
    }

    public function testTheStepRunsOnADatabaseWithStepsOneAndTwosRecordsAndGivesOldVisitorsReferences(): void
    {
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
        $steps = array_values(array_filter(FoundationSteps::all(), static fn($step): bool => !$step instanceof Step202611141200MarketingSales && !$step instanceof Step202611141500MarketingForgottenCustomers));
        (new SchemaRunner($this->connection, $steps, new MockClock('2026-11-14 00:10:00')))->migrate();
        $campaign = (int) $this->connection->fetchOne("INSERT INTO cattophp_marketing_campaigns (code, name, status) VALUES ('autumn-sale', 'Autumn sale', 'active') RETURNING id");
        $visitors = [];
        for ($n = 0; $n < 40; $n++) {
            $visitors[] = (int) $this->connection->fetchOne('INSERT INTO cattophp_marketing_visitors (token) VALUES (:token) RETURNING id', ['token' => bin2hex(random_bytes(16))]);
        }
        $this->connection->executeStatement("UPDATE cattophp_marketing_visitors SET token = NULL, anonymised_at = now() WHERE id = :id", ['id' => $visitors[0]]);
        $touch = (int) $this->connection->fetchOne("INSERT INTO cattophp_marketing_touches (visitor_id, campaign_id, agent, counted, landing) VALUES (:visitor, :campaign, 'person', true, '/') RETURNING id",
            ['visitor' => $visitors[1], 'campaign' => $campaign]);
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_events (kind, visitor_id, campaign_id, touch_id) VALUES ('landing_opened', :visitor, :campaign, :touch)",
            ['visitor' => $visitors[1], 'campaign' => $campaign, 'touch' => $touch]);

        self::assertSame(['202611141200-marketing-sales', '202611141500-marketing-forgotten-customers'], $this->runner()->migrate());
        $references = array_map('strval', $this->connection->fetchFirstColumn('SELECT reference FROM cattophp_marketing_visitors ORDER BY id'));
        self::assertCount(40, $references);
        self::assertCount(40, array_unique($references), 'Each its own.');
        foreach ($references as $reference) {
            self::assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{8}$/', $reference);
        }
        self::assertTrue($this->marketing->visitors->byReference($references[0])?->isAnonymous(), 'An anonymous visitor keeps a reference, naming nobody.');
        self::assertSame($visitors[1], $this->marketing->visitors->byReference(strtoupper($references[1]))?->id);
        $old = $this->marketing->touches->byId($touch);
        self::assertSame([null, null], [$old?->reference, $old?->originPublication], 'An older touch has no reference.');
        self::assertNull($this->marketing->events->recent()[0]->saleId);
        self::assertNull($this->connection->fetchAssociative('SELECT origin_site FROM cattophp_marketing_campaigns')['origin_site'] ?? null);
        self::assertSame([], $this->runner()->migrate(), 'Applied once.');
    }

    public function testTheChecksRefuseMoneyThatCannotBe(): void
    {
        $sale = $this->sale();
        $refused = [
            'an amount below nothing' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, total_minor) VALUES ('ORD-2', 'ZAR', now(), -1)",
            'refunds over what was paid' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, state, paid_at, total_minor, paid_minor, refunded_minor, charged_back_minor)
                VALUES ('ORD-2', 'ZAR', now(), 'paid', now(), 1000, 1000, 600, 401)",
            'paid without its moment' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, state, total_minor, paid_minor) VALUES ('ORD-2', 'ZAR', now(), 'paid', 1000, 1000)",
            'its moment without being paid' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, paid_at) VALUES ('ORD-2', 'ZAR', now(), now())",
            'money paid while waiting' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, total_minor, paid_minor) VALUES ('ORD-2', 'ZAR', now(), 1000, 1000)",
            'cancelled without its moment' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, state) VALUES ('ORD-2', 'ZAR', now(), 'cancelled')",
            'fixed while waiting' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, fixed) VALUES ('ORD-2', 'ZAR', now(), true)",
            'wallet credit over the total' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, total_minor, wallet_minor) VALUES ('ORD-2', 'ZAR', now(), 100, 101)",
            'a currency in small letters' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at) VALUES ('ORD-2', 'zar', now())",
            'a reference with a space' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at) VALUES ('ORD 2', 'ZAR', now())",
            'the same reference sold here twice' => "INSERT INTO cattophp_marketing_sales (reference, currency, placed_at) VALUES ('ORD-1', 'ZAR', now())",
            'a line paid other than its price less discount' => "INSERT INTO cattophp_marketing_sale_lines (sale_id, line_key, offer_type, offer_reference, label, price_minor, discount_minor, paid_minor)
                VALUES ($sale, 'item:2', 'product', 'mug-01', 'Mug', 1000, 100, 1000)",
            'a line refunded over what it paid' => "INSERT INTO cattophp_marketing_sale_lines (sale_id, line_key, offer_type, offer_reference, label, price_minor, paid_minor, refunded_minor)
                VALUES ($sale, 'item:2', 'product', 'mug-01', 'Mug', 1000, 1000, 1001)",
            'a line given twice' => "INSERT INTO cattophp_marketing_sale_lines (sale_id, line_key, offer_type, offer_reference, label, price_minor, paid_minor)
                VALUES ($sale, 'item:1', 'product', 'mug-01', 'Mug', 1000, 1000)",
            'a line of no quantity' => "INSERT INTO cattophp_marketing_sale_lines (sale_id, line_key, offer_type, offer_reference, label, price_minor, paid_minor, quantity)
                VALUES ($sale, 'item:2', 'product', 'mug-01', 'Mug', 1000, 1000, 0)",
            'a movement below nothing' => "INSERT INTO cattophp_marketing_sale_movements (sale_id, kind, key, amount_minor, at) VALUES ($sale, 'refund', 'refund:1', -5, now())",
            'a movement of another kind' => "INSERT INTO cattophp_marketing_sale_movements (sale_id, kind, key, amount_minor, at) VALUES ($sale, 'gift', 'gift:1', 5, now())",
            'a movement kept twice' => "INSERT INTO cattophp_marketing_sale_movements (sale_id, kind, key, amount_minor, at) VALUES ($sale, 'refund', 'paid', 5, now())",
            'a site at an address without https' => "INSERT INTO cattophp_marketing_sites (key, name, address, secret) VALUES ('other', 'Other', 'http://example.net', 'x')",
            'a second site at the same address' => "INSERT INTO cattophp_marketing_sites (key, name, address, secret) VALUES ('other', 'Other', 'https://EXAMPLE.org', 'x')",
            'a report out kept twice' => "INSERT INTO cattophp_marketing_reports_out (site_key, key, kind, body) VALUES ('example-org', 'sale_paid:ORD-1', 'sale_paid', '{}')",
            'a report out delivered and given up' => "INSERT INTO cattophp_marketing_reports_out (site_key, key, kind, body, delivered_at, gave_up_at) VALUES ('example-org', 'k2', 'sale_paid', '{}', now(), now())",
            'a report in of no result' => "INSERT INTO cattophp_marketing_reports_in (site_key, key, kind, result) VALUES ('example-org', 'k1', 'sale_paid', 'kept')",
            'a report from no site' => "INSERT INTO cattophp_marketing_reports_in (site_key, key, kind, result) VALUES ('nowhere', 'k1', 'sale_paid', 'recorded')",
        ];
        foreach ($refused as $what => $statement) {
            $this->connection->beginTransaction();
            try {
                $this->connection->executeStatement($statement);
                self::fail('The database took ' . $what . '.');
            } catch (DriverException) {
                self::addToAssertionCount(1);
            } finally {
                $this->connection->rollBack();
            }
        }

        // What can be is taken: the same reference from a connected site, a removed site's address again, a report in.
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_sales (reference, origin_site, currency, placed_at) VALUES ('ORD-1', 'example-org', 'ZAR', now())");
        $this->connection->executeStatement("UPDATE cattophp_marketing_sites SET removed_at = now() WHERE key = 'example-org'");
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_sites (key, name, address, secret) VALUES ('example-org-2', 'Example', 'https://example.org', 'x')");
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_reports_in (site_key, key, kind, result) VALUES ('example-org', 'k1', 'sale_paid', 'unknown_click')");
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_sales'));

        // A sale's events lose it when it goes; its lines and movements go with it.
        $event = $this->marketing->events->record(new EventInput(Events::ORDER_CREATED, new DateTimeImmutable('2026-11-14 09:00'), account: '7', saleId: $sale, idempotencyKey: 'order_created:ORD-1'));
        $this->connection->executeStatement('DELETE FROM cattophp_marketing_sales WHERE id = :id', ['id' => $sale]);
        self::assertNull($this->marketing->events->byKey('order_created:ORD-1')?->saleId);
        self::assertSame($event, $this->marketing->events->byKey('order_created:ORD-1')?->id);
        self::assertSame([0, 0], [(int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_sale_lines'), (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_sale_movements')]);
    }

    public function testAVisitorHasAReferenceAndKeepsTheFirstAccountItWasTiedTo(): void
    {
        $now = new DateTimeImmutable('2026-11-14 09:00:00+00:00');
        [$phone] = $this->marketing->visitors->arrive(null, 'ZA', 'phone', $now);
        [$computer] = $this->marketing->visitors->arrive(null, 'ZA', 'desktop', $now->modify('+1 day'));
        self::assertMatchesRegularExpression(Visitors::REFERENCE, $phone->reference);
        self::assertNotSame($phone->reference, $computer->reference);
        self::assertSame('Visitor ' . $phone->reference, $phone->name());
        self::assertNotSame($phone->token, $phone->reference, 'Never the cookie\'s token.');
        self::assertTrue($this->marketing->visitors->identify($phone->id, '7'));
        self::assertFalse($this->marketing->visitors->identify($phone->id, '8'), 'A browser two people use keeps the first person it was tied to.');
        self::assertSame('7', $this->marketing->visitors->byId($phone->id)?->account);
        self::assertTrue($this->marketing->visitors->identify($computer->id, '7'));
        self::assertSame([$phone->id, $computer->id], array_map(static fn($v): int => $v->id, $this->marketing->visitors->ofAccount('7')), 'One person, two visitors, the first seen first.');
        self::assertNull($this->marketing->visitors->byReference('not-one'));

        $this->marketing->visitors->seen($phone->id, $now->modify('+3 days'));
        $this->marketing->visitors->seen($phone->id, $now->modify('+2 days'));
        self::assertSame('2026-11-17', $this->marketing->visitors->byId($phone->id)?->lastSeenAt->format('Y-m-d'), 'Seen again moves it on, never back.');

        [$stranger] = $this->marketing->visitors->arrive(null, 'GB', 'desktop', $now);
        self::assertSame([$phone->id, $computer->id], $this->marketing->visitors->anonymise($computer, $now), 'Every visitor of the person.');
        self::assertSame([], $this->marketing->visitors->ofAccount('7'));
        self::assertTrue($this->marketing->visitors->byId($phone->id)?->isAnonymous());
        self::assertFalse($this->marketing->visitors->byId($stranger->id)?->isAnonymous(), 'Nobody else.');
        self::assertFalse($this->marketing->visitors->identify($phone->id, '7'), 'An anonymous visitor is never tied again.');
        self::assertSame([$stranger->id], $this->marketing->visitors->anonymise($stranger, $now), 'A visitor nobody knows is made anonymous alone.');
        self::assertSame([], $this->marketing->visitors->anonymise($stranger, $now), 'Once.');
    }

    public function testATouchHasAReferenceAndAnArrivalKeepsWhereItCameFrom(): void
    {
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale');
        $at = new DateTimeImmutable('2026-11-14 09:00:00+00:00');
        $here = $this->marketing->touches->record($campaign->id, null, null, 'facebook', [], $at, null, 'ZA', null, 'person', null, false, false, '/');
        $arrival = $this->marketing->touches->record($campaign->id, null, null, 'email', [], $at, null, 'ZA', null, 'person', null, false, false, '/courses', 'example-org', 'q8w3e5r7t2', 'em1');
        $touch = $this->marketing->touches->byId($arrival);
        self::assertMatchesRegularExpression('/^[a-z0-9]{10}$/', (string) $touch?->reference);
        self::assertSame(['example-org', 'q8w3e5r7t2', 'em1'], [$touch?->originSite, $touch?->originReference, $touch?->originPublication]);
        self::assertSame($arrival, $this->marketing->touches->byReference(strtoupper((string) $touch?->reference))?->id);
        self::assertNotSame($touch?->reference, $this->marketing->touches->byId($here)?->reference);
        self::assertNull($this->marketing->touches->byReference('short'));
        [$visitor] = $this->marketing->visitors->arrive(null, 'ZA', 'phone', $at);
        $mine = $this->marketing->touches->record($campaign->id, null, $visitor->id, 'facebook', [], $at, null, 'ZA', null, 'person', null, true, false, '/');
        self::assertSame([$mine], array_map(static fn($t): int => $t->id, $this->marketing->touches->forVisitors([$visitor->id, 999])));
        self::assertSame([], $this->marketing->touches->forVisitors([]));
    }

    public function testACampaignIsMadeOnceForAnArrivalFromAConnectedSite(): void
    {
        $site = new Site('example-org', 'Example', 'https://example.org', 'sealed');
        $campaign = $this->marketing->campaigns->makeForArrival('Law-Nov26', '  Law   in November ', $site);
        self::assertSame(['law-nov26', 'Law in November', 'active', '/', 'example-org', null, null], [$campaign->code, $campaign->name, $campaign->status, $campaign->destination,
            $campaign->originSite, $campaign->startsAt, $campaign->endsAt]);
        self::assertSame('Made when a visitor arrived by a link from Example.', $campaign->description);
        self::assertTrue($campaign->isRunning($this->marketing->campaigns->now()));
        self::assertSame($campaign->id, $this->marketing->campaigns->makeForArrival('law-nov26', 'Another name', $site)->id, 'Found the second time, unchanged.');
        self::assertSame(1, $this->marketing->site->activity->count('marketing.campaign_created'), 'Made once, in the activity log.');
        $ours = $this->marketing->campaign('Spring sale', 'spring-sale');
        self::assertSame([$ours->id, null], [$this->marketing->campaigns->makeForArrival('spring-sale', 'Spring', $site)->id, $ours->originSite], 'A campaign made here stays as it is.');
        self::assertSame('law-nov26', $this->marketing->campaigns->makeForArrival('law-nov26', '', $site)->code);
        foreach (['new', 'x', 'not a code'] as $code) {
            try {
                $this->marketing->campaigns->makeForArrival($code, 'A name', $site);
                self::fail('Made for ' . $code);
            } catch (MarketingRefused $refused) {
                self::assertStringContainsString('from Example is not.', implode(' ', $refused->errors()));
            }
        }
        $copy = $this->marketing->campaigns->copy('law-nov26');
        self::assertNull($copy->originSite, 'A copy is made here.');
    }

    public function testAnEventIsRecordedFromItsInputWithItsSaleAndOnceByItsKey(): void
    {
        $events = $this->marketing->events;
        $sale = $this->sale();
        $at = new DateTimeImmutable('2026-11-14 09:00:00+00:00');
        $id = $events->record(new EventInput(Events::PAID, $at, account: '7', offerType: 'product', offerReference: 'tea-100', detail: ['how' => 'Card'], country: 'za',
            idempotencyKey: 'paid:ORD-1', saleId: $sale));
        self::assertNotNull($id);
        $event = $events->byKey('paid:ORD-1');
        self::assertSame([$id, 'paid', '7', $sale, 'ZA', ['how' => 'Card']], [$event?->id, $event?->kind, $event?->account, $event?->saleId, $event?->country, $event?->detail]);
        self::assertSame([$id], array_map(static fn($e): int => $e->id, $events->forSale($sale)));

        // A repeat inside a caller's transaction changes nothing and leaves the transaction usable.
        $this->connection->beginTransaction();
        self::assertNull($events->record(new EventInput(Events::PAID, $at, account: '7', idempotencyKey: 'paid:ORD-1', saleId: $sale)));
        self::assertNotNull($events->record('landing_opened', $at), 'Step 2\'s way of giving one still works.');
        $this->connection->commit();
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_events'));
        self::assertContains(Events::CHARGED_BACK, Events::KINDS);
        foreach (Events::KINDS as $kind) {
            self::assertMatchesRegularExpression(Events::KIND, $kind);
        }
        $this->expectException(\InvalidArgumentException::class);
        $events->record('landing_opened');
    }

    public function testAJourneysEventsAreReadForAPersonAndARepeatIsFound(): void
    {
        $events = $this->marketing->events;
        $now = new DateTimeImmutable('2026-11-14 09:00:00+00:00');
        [$phone] = $this->marketing->visitors->arrive(null, 'ZA', 'phone', $now);
        [$computer] = $this->marketing->visitors->arrive(null, 'ZA', 'desktop', $now);
        [$stranger] = $this->marketing->visitors->arrive(null, 'GB', 'desktop', $now);
        $viewed = $events->record(new EventInput(Events::OFFER_VIEWED, $now, $phone->id, offerType: 'product', offerReference: 'tea-100'));
        $signedIn = $events->record(new EventInput(Events::SIGNED_IN, $now->modify('+1 hour'), $computer->id, '7'));
        $paid = $events->record(new EventInput(Events::PAID, $now->modify('+2 hours'), account: '7'));
        $events->record(new EventInput(Events::OFFER_VIEWED, $now, $stranger->id, offerType: 'product', offerReference: 'tea-100'));
        self::assertSame([$viewed, $signedIn, $paid], array_map(static fn($e): int => $e->id, $events->forPerson([$phone->id, $computer->id], '7')), 'Both visitors and the account, oldest first.');
        self::assertSame([], $events->forPerson([], null));
        self::assertSame($viewed, $events->latest([Events::OFFER_VIEWED], $phone->id, '7', 'product', 'tea-100', $now->modify('-30 minutes'))?->id);
        self::assertNull($events->latest([Events::OFFER_VIEWED], $phone->id, null, 'product', 'mug-01'), 'Another offer.');
        self::assertNull($events->latest([Events::OFFER_VIEWED], $phone->id, null, 'product', 'tea-100', $now->modify('+1 minute')), 'Not since.');
        self::assertSame($paid, $events->latest([Events::SIGNED_IN, Events::PAID], null, '7')?->id);
        self::assertNull($events->latest([Events::SIGNED_IN], null, null));

        $this->marketing->visitors->anonymise($computer, $now);
        self::assertSame(1, $events->anonymiseVisitors(), 'The anonymous visitor\'s event names nobody.');
        self::assertNull($events->byKey('none'));
        self::assertSame([null, '7'], [$this->connection->fetchOne('SELECT account FROM cattophp_marketing_events WHERE id = :id', ['id' => $signedIn]) ?: null,
            $this->connection->fetchOne('SELECT account FROM cattophp_marketing_events WHERE id = :id', ['id' => $paid])]);
    }

    public function testForgettingAnAccountUnnamesTheConnectedSitesItAdded(): void
    {
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_sites (key, name, address, secret, created_by) VALUES ('example-org', 'Example', 'https://example.org', 'x', '7')");
        $forgotten = (new MarketingRecords($this->marketing->visitors, $this->connection, $this->marketing->events))->forgetAccount('7', null);
        self::assertSame(1, $forgotten['unnamed']['campaign records made'] ?? null);
        self::assertSame([null, 'Example'], array_values($this->connection->fetchAssociative('SELECT created_by, name FROM cattophp_marketing_sites') ?: []));
    }

    public function testTheActivityLogLinksAConnectedSiteAndAVisitorAndWordsTheirDetails(): void
    {
        $routes = new RouteCollection();
        $routes->add('cattophp_marketing_settings', new Route('/marketing/settings'));
        $links = new MarketingSubjectLinks(new UrlGenerator($routes, new RequestContext()), $this->marketing->campaigns, null, $this->marketing->visitors);
        [$visitor] = $this->marketing->visitors->arrive(null, 'ZA', 'phone', new DateTimeImmutable('2026-11-14 09:00'));
        self::assertSame('/marketing/settings#connected-sites', $links->href(Site::SUBJECT, 'example-org'));
        self::assertNull($links->href(Visitors::SUBJECT, $visitor->reference), 'Words alone until the project imports the visitor\'s page.');
        $routes->add('cattophp_marketing_visitor', new Route('/marketing/visitors/{reference}'));
        $links = new MarketingSubjectLinks(new UrlGenerator($routes, new RequestContext()), $this->marketing->campaigns, null, $this->marketing->visitors);
        self::assertSame('/marketing/visitors/' . $visitor->reference, $links->href(Visitors::SUBJECT, strtoupper($visitor->reference)));
        self::assertNull($links->href(Visitors::SUBJECT, 'abcdefgh'), 'Nobody by that reference.');
        $this->marketing->visitors->anonymise($visitor, new DateTimeImmutable('2026-11-14 10:00'));
        self::assertNull($links->href(Visitors::SUBJECT, $visitor->reference), 'A forgotten visitor in words alone.');

        $words = new MarketingDetailWords($this->marketing->project, $this->marketing->channels);
        self::assertSame(['Connected site', 'Clocks apart by', 'Visitors made anonymous', 'Mailing message'], [$words->label('marketing.site.added', 'site'),
            $words->label('marketing.site.checked', 'clock_apart_seconds'), $words->label('marketing.visitor.forgotten', 'visitors'), $words->label('marketing.publication.tied', 'message')]);
        self::assertSame(['40 seconds', '1 second', '7 minutes'], [$words->value('marketing.site.checked', 'clock_apart_seconds', -40), $words->value('marketing.site.checked', 'clock_apart_seconds', 1),
            $words->value('marketing.site.checked', 'clock_apart_seconds', 420)]);
    }

    /** A waiting sale of ORD-1 with one line and its paid movement's key taken, and a connected site at https://example.org with one report out: the sale's id. */
    private function sale(): int
    {
        $sale = (int) $this->connection->fetchOne("INSERT INTO cattophp_marketing_sales (reference, currency, placed_at, total_minor) VALUES ('ORD-1', 'ZAR', now(), 25000) RETURNING id");
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_sale_lines (sale_id, line_key, offer_type, offer_reference, label, price_minor, paid_minor)
            VALUES (:sale, 'item:1', 'product', 'tea-100', 'Rooibos tea', 25000, 25000)", ['sale' => $sale]);
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_sale_movements (sale_id, kind, key, amount_minor, at) VALUES (:sale, 'paid', 'paid', 0, now())", ['sale' => $sale]);
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_sites (key, name, address, secret) VALUES ('example-org', 'Example', 'https://example.org', 'x')");
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_reports_out (site_key, key, kind, body) VALUES ('example-org', 'sale_paid:ORD-1', 'sale_paid', '{}')");

        return $sale;
    }
}
