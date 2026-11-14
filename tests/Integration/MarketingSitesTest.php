<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\EventInput;
use CattoPHP\Marketing\Journey\JourneyRecorded;
use CattoPHP\Marketing\LinkOpening;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\Sites\SiteReports;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\RecordingLogger;
use CattoPHP\Webhooks\WebhookSignature;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Connected sites as services on a test database: a site added, refused as typed, renamed, given a new secret (the
 * previous one kept until the other site uses the new one) and removed (refused while reports wait, kept so its records
 * keep its name, connected again under its old key); and the reports owed to it: kept once by their key, sent 50 at a
 * time, tried again after the first wait and twice as long each time up to the longest, the problem named, given up after
 * the time kept and offered once more by Send them again, removed after that time once delivered, and nothing sent
 * without an HTTP client.
 */
final class MarketingSitesTest extends DatabaseTestCase
{
    private const SECRET = 'a-shared-secret-of-thirty-two-or-more-characters';
    private const NEW_SECRET = 'another-shared-secret-of-thirty-two-or-more-chars';

    private MarketingHarness $marketing;
    private Sites $sites;
    private RecordingLogger $logger;
    /** @var list<array{string,string,array<string,mixed>}> */
    private array $requests = [];
    /** @var list<ResponseInterface|\Throwable> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection, ['MARKETING_SITE_RETRY_LONGEST' => '5m', 'MARKETING_SITE_REPORTS_KEPT' => '1d']);
        $this->sites = new Sites($this->connection, $this->marketing->site->cipher, $this->marketing->settings, $this->marketing->site->clock, $this->marketing->site->activity,
            $this->marketing->site->installation);
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testASiteIsAddedWithItsSecretEncryptedOrRefusedWithWhyBesideEachField(): void
    {
        foreach ([
            [['', 'https://example.org', self::SECRET], ['name']],
            [['Example Shop', 'http://example.org', self::SECRET], ['address']],
            [['Example Shop', 'https://example.com', self::SECRET], ['address']],
            [['Example Shop', 'https://example.org', 'too short'], ['secret']],
            [['!!!', 'https://example.org', 'with spaces in a secret that is long enough'], ['secret', 'name']],
        ] as [[$name, $address, $secret], $fields]) {
            try {
                $this->sites->add($name, $address, $secret);
                self::fail('Refused: ' . implode(', ', $fields));
            } catch (MarketingRefused $refused) {
                self::assertSame($fields, array_keys($refused->errors()), $name . ' ' . $address);
            }
        }
        self::assertStringContainsString('this site\'s own address', $this->refusedField(fn () => $this->sites->add('Me', 'https://example.com/', self::SECRET), 'address'));

        $site = $this->sites->add(' Example Shop ', 'https://Example.org/', self::SECRET);
        self::assertSame(['example-shop', 'Example Shop', 'https://example.org', 'example.org', false], [$site->key, $site->name, $site->address, $site->host(), $site->isConnected()]);
        $stored = (string) $this->connection->fetchOne('SELECT secret FROM cattophp_marketing_sites WHERE key = :key', ['key' => 'example-shop']);
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString(self::SECRET, $stored, 'Stored encrypted, never as typed.');
        self::assertSame([self::SECRET], $this->sites->secrets($site));
        self::assertSame(['marketing.site.added', 'marketing_site', 'example-shop', ['name' => 'Example Shop', 'address' => 'https://example.org']], $this->lastActivity());
        self::assertSame('example-shop', $this->sites->byAddress('https://EXAMPLE.org/')?->key);
        self::assertSame('example-shop', $this->sites->forHost('Example.org')?->key);
        self::assertNull($this->sites->forHost('example.net'));
        self::assertStringContainsString('Example Shop is connected at that address already.',
            $this->refusedField(fn () => $this->sites->add('Another shop', 'https://example.org', self::SECRET), 'address'));
        self::assertSame('example-shop-2', $this->sites->add('Example Shop', 'https://shop.example.net', self::SECRET)->key, 'A name taken gives the next free key.');
        self::assertSame(['Example Shop', 'Example Shop'], array_map(static fn (Site $site): string => $site->name, $this->sites->all()));
    }

    public function testASiteIsRenamedAndGivenANewSecretThePreviousOneKeptUntilTheOtherSiteUsesTheNewOne(): void
    {
        $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $changed = $this->sites->change('example-shop', 'Example Books', 'https://books.example.org');
        self::assertSame(['example-shop', 'Example Books', 'https://books.example.org'], [$changed->key, $changed->name, $changed->address], 'Its key stays.');
        self::assertSame(['marketing.site.changed', 'marketing_site', 'example-shop', ['name' => 'Example Books', 'address' => 'https://books.example.org', 'name_before' => 'Example Shop',
            'address_before' => 'https://example.org']], $this->lastActivity());
        self::assertSame('https://books.example.org', $this->sites->change('example-shop', 'Example Books', 'https://books.example.org/')->address, 'Nothing changed, nothing logged.');
        self::assertSame('marketing.site.changed', $this->lastActivity()[0]);

        self::assertStringContainsString('That is the secret it has already.', $this->refusedField(fn () => $this->sites->changeSecret('example-shop', self::SECRET), 'secret'));
        self::assertStringContainsString('32 to 200 characters', $this->refusedField(fn () => $this->sites->changeSecret('example-shop', 'short'), 'secret'));
        $changing = $this->sites->changeSecret('example-shop', self::NEW_SECRET);
        self::assertTrue($changing->isChangingSecret());
        self::assertSame([self::NEW_SECRET, self::SECRET], $this->sites->secrets($changing));
        self::assertSame(['marketing.site.secret_changed', 'marketing_site', 'example-shop', ['site' => 'Example Books']], $this->lastActivity());
        $now = $this->marketing->site->clock->now();
        // A message with the old secret alone: the other site has not the new one yet, so the previous one stays.
        $this->sites->verify($changing, WebhookSignature::sign('{}', [self::SECRET], $now->getTimestamp()), '{}', $now);
        self::assertTrue($this->sites->get('example-shop')?->isChangingSecret());
        // One that checks with the new one: the previous one is dropped by itself.
        $this->sites->verify($changing, WebhookSignature::sign('{}', [self::NEW_SECRET, self::SECRET], $now->getTimestamp()), '{}', $now);
        self::assertFalse($this->sites->get('example-shop')?->isChangingSecret());
        self::assertSame([self::NEW_SECRET], $this->sites->secrets($this->sites->find('example-shop') ?? self::fail()));

        // A secret this site cannot read any more (another application key) is simply replaced.
        $this->connection->executeStatement("UPDATE cattophp_marketing_sites SET secret = 'v1:" . base64_encode(str_repeat('x', 40)) . "'");
        $replaced = $this->sites->changeSecret('example-shop', self::SECRET);
        self::assertSame([self::SECRET], $this->sites->secrets($replaced));
    }

    public function testASiteIsRemovedOnlyWithItsWaitingReportsDroppedAndConnectingItAgainGivesBackItsKey(): void
    {
        $site = $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $reports = $this->reports();
        self::assertTrue($reports->queue($site, 'registered:1', 'registered', ['version' => 1, 'key' => 'registered:1'], $this->marketing->site->clock->now()));
        self::assertStringContainsString('Example Shop has 1 report not delivered yet, so it was not removed.', $this->refusedField(fn () => $this->sites->remove('example-shop', false), ''));
        self::assertSame(1, $this->sites->remove('example-shop', true));
        self::assertSame(['marketing.site.removed', 'marketing_site', 'example-shop', ['name' => 'Example Shop', 'address' => 'https://example.org', 'reports_dropped' => 1]], $this->lastActivity());
        self::assertNull($this->sites->get('example-shop'));
        self::assertNull($this->sites->byAddress('https://example.org'), 'Its arrivals and messages are refused from now on.');
        self::assertSame(['Example Shop', true], [$this->sites->find('example-shop')?->name, $this->sites->find('example-shop')?->isRemoved()]);
        self::assertSame('Example Shop', $this->sites->name('example-shop'), 'Its records keep its name.');
        self::assertSame('gone-site', $this->sites->name('gone-site'));
        self::assertSame([], $this->sites->all());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_reports_out'));

        $again = $this->sites->add('Example Shop again', 'https://example.org', self::NEW_SECRET);
        self::assertSame(['example-shop', 'Example Shop again', false], [$again->key, $again->name, $again->isRemoved()], 'Its old key, so its records are its again.');
        self::assertSame(0, $this->sites->remove('example-shop', false), 'Nothing waiting: removed at once.');
    }

    public function testAReportIsQueuedOnceAndSentFiftyAtATimeSignedAndNamingThisSite(): void
    {
        $site = $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $reports = $this->reports();
        $now = $this->marketing->site->clock->now();
        self::assertTrue($reports->queue($site, 'sale_paid:CL-1', 'sale_paid', ['key' => 'sale_paid:CL-1'], $now));
        self::assertFalse($reports->queue($site, 'sale_paid:CL-1', 'sale_paid', ['key' => 'sale_paid:CL-1', 'again' => true], $now), 'A repeat queues nothing.');
        for ($n = 2; $n <= 60; $n++) {
            $reports->queue($site, 'sale_paid:CL-' . $n, 'sale_paid', ['key' => 'sale_paid:CL-' . $n], $now);
        }
        self::assertSame([60, 0, 0], [$reports->counts($site)->waiting, $reports->counts($site)->givenUp, $reports->counts($site)->delivered]);
        $this->answers = [new MockResponse('{"recorded":50}'), new MockResponse('{"recorded":10}')];
        self::assertSame('60 reports delivered, 0 not delivered yet, 0 given up, 0 removed.', $reports->deliver($now));
        self::assertCount(2, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame(['POST', 'https://example.org/webhooks/marketing'], [$method, $url]);
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $sent = json_decode($body, true);
        self::assertIsArray($sent);
        self::assertCount(50, $sent);
        self::assertSame(['key' => 'sale_paid:CL-1'], $sent[0], 'Oldest first, the body as queued.');
        $headers = self::headers($options);
        self::assertSame('https://example.com', $headers['x-cattophp-from']);
        self::assertTrue(WebhookSignature::valid($headers['x-cattophp-signature'], $body, [self::SECRET], $now->getTimestamp()), 'Signed with the shared secret.');
        self::assertSame([0, 60], [$reports->counts($site)->waiting, $reports->counts($site)->delivered]);
        self::assertNotNull($this->sites->get('example-shop')?->lastSentAt);
        self::assertSame('0 reports delivered, 0 not delivered yet, 0 given up, 0 removed.', $reports->deliver($now), 'Nothing is sent twice.');
        self::assertCount(2, $this->requests);
    }

    public function testAReportNotAcceptedIsTriedAgainTwiceAsLongEachTimeAndItsProblemNamed(): void
    {
        $site = $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $reports = $this->reports();
        $clock = $this->marketing->site->clock;
        $reports->queue($site, 'registered:1', 'registered', ['key' => 'registered:1'], $clock->now());
        $waits = [];
        $this->answers = [new TransportException('Could not resolve host.'), new MockResponse('', ['http_code' => 401]), new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 500])];
        $problems = [];
        foreach (range(1, 5) as $try) {
            self::assertStringContainsString('1 not delivered yet', $reports->deliver($clock->now()), 'Try ' . $try);
            $problems[] = (string) $this->sites->get('example-shop')?->lastProblem;
            $next = $reports->counts($site)->nextTry ?? self::fail();
            $waits[] = $next->getTimestamp() - $clock->now()->getTimestamp();
            self::assertSame('0 reports delivered, 0 not delivered yet, 0 given up, 0 removed.', $reports->deliver($clock->now()), 'Not due again before its wait.');
            $clock->modify('+' . $waits[count($waits) - 1] . ' seconds');
        }
        self::assertSame([60, 120, 240, 300, 300], $waits, 'A minute, then twice as long each time, never more than the longest (5 minutes here).');
        self::assertSame(['The other site could not be reached. Its reports are tried again later.',
            'The other site did not accept this site\'s signature. Check that both have the same shared secret.',
            'The other site answered with an error (HTTP 500). Its reports are tried again later.'], array_slice($problems, 0, 3));
        self::assertFalse($this->sites->get('example-shop')?->isConnected());
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT attempts FROM cattophp_marketing_reports_out'));

        $this->answers = [new MockResponse('{"recorded":1}')];
        self::assertStringStartsWith('1 report delivered', $reports->deliver($clock->now()));
        self::assertNull($this->sites->get('example-shop')?->lastProblem, 'Delivered: the problem is over.');
    }

    public function testAReportNotDeliveredInTimeIsGivenUpSentAgainOnRequestAndOldReportsAreRemoved(): void
    {
        $site = $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $reports = $this->reports();
        $clock = $this->marketing->site->clock;
        $reports->queue($site, 'sale_paid:CL-1', 'sale_paid', ['key' => 'sale_paid:CL-1'], $clock->now());
        $reports->queue($site, 'sale_paid:CL-2', 'sale_paid', ['key' => 'sale_paid:CL-2'], $clock->now());
        $this->answers = [new MockResponse('', ['http_code' => 500])];
        $reports->deliver($clock->now());
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_reports_in (site_key, key, kind, received_at, result) VALUES ('example-shop', 'registered:9', 'registered', :at, 'recorded')",
            ['at' => $clock->now()->format('Y-m-d H:i:sP')]);
        $clock->modify('+25 hours');
        self::assertSame('0 reports delivered, 0 not delivered yet, 2 given up, 1 removed.', $reports->deliver($clock->now()), 'Kept a day here: given up, and the report received long ago removed.');
        self::assertSame([0, 2, 2], [$reports->counts($site)->waiting, $reports->counts($site)->givenUp, $reports->counts($site)->undelivered()]);
        self::assertSame(0, $reports->counts($site)->received);

        self::assertSame(2, $reports->sendAgain($site));
        self::assertSame(0, $reports->sendAgain($site));
        $this->answers = [new MockResponse('{"recorded":2}')];
        self::assertSame('2 reports delivered, 0 not delivered yet, 0 given up, 0 removed.', $reports->deliver($clock->now()), 'Sent again, and tried for the whole time kept once more.');
        $clock->modify('+25 hours');
        self::assertSame('0 reports delivered, 0 not delivered yet, 0 given up, 2 removed.', $reports->deliver($clock->now()), 'Delivered ones go after the time kept.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_reports_out'));
    }

    public function testNothingIsSentWithoutAnHttpClientAndTheCheckSaysWhy(): void
    {
        $site = $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $reports = new SiteReports($this->connection, $this->sites, $this->marketing->touches, $this->marketing->visitors, $this->marketing->settings, $this->marketing->site->clock,
            $this->marketing->site->installation);
        $reports->queue($site, 'registered:1', 'registered', ['key' => 'registered:1'], $this->marketing->site->clock->now());
        self::assertFalse($reports->canSend());
        self::assertSame('0 reports delivered, 0 not delivered yet, 0 given up, 0 removed (nothing is sent without symfony/http-client).', $reports->deliver($this->marketing->site->clock->now()));
        self::assertSame(1, $reports->counts($site)->waiting);
        self::assertSame('Connected sites need symfony/http-client, which this installation does not have.', $reports->check($site));
    }

    public function testACheckOfTheConnectionNotesItAndHowFarApartTheClocksAre(): void
    {
        $site = $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $reports = $this->reports();
        $now = $this->marketing->site->clock->now();
        $this->answers = [new MockResponse('{"name":"Example Shop"}', ['response_headers' => ['Date' => gmdate('D, d M Y H:i:s \G\M\T', $now->getTimestamp() + 95)]])];
        self::assertNull($reports->check($site));
        [$method, $url, $options] = $this->requests[0];
        self::assertSame(['GET', 'https://example.org/webhooks/marketing/check'], [$method, $url]);
        self::assertTrue(WebhookSignature::valid(self::headers($options)['x-cattophp-signature'], '', [self::SECRET], $now->getTimestamp()));
        $checked = $this->sites->get('example-shop') ?? self::fail();
        self::assertSame([true, 95], [$checked->isConnected(), $checked->clockApartSeconds]);
        self::assertSame(['marketing.site.checked', 'marketing_site', 'example-shop', ['name' => 'Example Shop', 'result' => 'Connected', 'clock_apart_seconds' => 95]], $this->lastActivity());

        $this->answers = [new MockResponse('', ['http_code' => 401, 'response_headers' => ['Date' => gmdate('D, d M Y H:i:s \G\M\T', $now->getTimestamp() - 420)]])];
        self::assertSame('The other site refused this site\'s signature because the two clocks are 7 minutes apart, more than the 5 minutes allowed. Set both clocks right.', $reports->check($site));
        self::assertFalse($this->sites->get('example-shop')?->isConnected());
        $this->answers = [new TransportException('Connection refused.')];
        self::assertSame('The other site could not be reached. Check its address, and that it is running.', $reports->check($site));
    }

    public function testARegistrationAfterAnArrivalFromAConnectedSiteIsReportedToItOnce(): void
    {
        $site = $this->sites->add('Example Shop', 'https://example.org', self::SECRET);
        $reports = $this->reports();
        $campaign = $this->marketing->campaigns->makeForArrival('law-nov26', 'Law, November', $site);
        $request = Request::create('https://example.com/shop', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0', 'REMOTE_ADDR' => '198.51.100.7']);
        $tracked = $this->marketing->links->recordOpening(new LinkOpening($campaign, null, 'email', [], '/shop'), $request, 'example-shop', 'k2m9q4d7xa', 'em1');
        $events = new Events($this->connection);
        $now = $this->marketing->site->clock->now();
        $id = $events->record(new EventInput(Events::REGISTERED, $now, $tracked->visitorId, 'acct-7', idempotencyKey: 'registered:acct-7')) ?? self::fail();
        $event = $events->byKey('registered:acct-7') ?? self::fail();
        $reports->onJourneyRecorded(new JourneyRecorded($event));
        $reports->onJourneyRecorded(new JourneyRecorded($event));
        self::assertSame(1, $reports->counts($site)->waiting, 'Once, by its key.');
        $body = json_decode((string) $this->connection->fetchOne('SELECT body FROM cattophp_marketing_reports_out'), true);
        self::assertEquals(['version' => 1, 'key' => 'registered:' . $id, 'kind' => 'registered', 'at' => '2026-11-09T10:00:00+00:00', 'click' => 'k2m9q4d7xa'], $body,
            'Its click there, and no person, account or address.');

        // A view is not reported, nor an event that came from a connected site itself.
        $viewed = $events->record(new EventInput(Events::OFFER_VIEWED, $now, $tracked->visitorId, offerType: 'product', offerReference: 't-100')) ?? self::fail();
        $reports->onJourneyRecorded(new JourneyRecorded($events->latest([Events::OFFER_VIEWED], $tracked->visitorId, null) ?? self::fail(), new Offer('product', 't-100', 'Green tea')));
        self::assertGreaterThan(0, $viewed);
        $events->record(new EventInput(Events::SUBSCRIBED, $now, $tracked->visitorId, idempotencyKey: 'site:x:1', originSite: 'example-shop'));
        $reports->onJourneyRecorded(new JourneyRecorded($events->byKey('site:x:1') ?? self::fail()));
        self::assertSame(1, $reports->counts($site)->waiting);
    }

    private function reports(): SiteReports
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = [$method, $url, $options];
            $answer = array_shift($this->answers) ?? throw new TransportException('No answer is set for this request.');
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });

        return new SiteReports($this->connection, $this->sites, $this->marketing->touches, $this->marketing->visitors, $this->marketing->settings, $this->marketing->site->clock,
            $this->marketing->site->installation, $http, $this->logger);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,string> a request's headers by their small-letter names
     */
    private static function headers(array $options): array
    {
        $headers = [];
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
            if (is_string($line) && str_contains($line, ': ')) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
        }

        return $headers;
    }

    /** @return string why a field was refused */
    private function refusedField(callable $change, string $field): string
    {
        try {
            $change();
        } catch (MarketingRefused $refused) {
            return $refused->errors()[$field] ?? '';
        }
        self::fail('It was not refused.');
    }

    /** @return array{string,?string,?string,array<string,mixed>} the newest entry of the activity log */
    private function lastActivity(): array
    {
        $entry = $this->marketing->site->activity->recent(1)[0] ?? self::fail('Nothing was logged.');

        return [$entry->action, $entry->subjectType, $entry->subjectId, $entry->details];
    }
}
