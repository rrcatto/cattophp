<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Country\Countries;
use CattoPHP\Marketing\AdvertInput;
use CattoPHP\Marketing\Attention;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\FigureRow;
use CattoPHP\Marketing\Period;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\Request;

/**
 * The figures, from openings made through the tracking links: two campaigns, three publications, four people (one asking
 * not to be tracked), a repeat within one visit, a later visit, a link preview and a search engine, over two days.
 */
final class CampaignFiguresTest extends DatabaseTestCase
{
    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private MarketingHarness $marketing;
    private int $spring;
    private int $winter;

    protected function setUp(): void
    {
        parent::setUp();
        $marketing = $this->marketing = new MarketingHarness($this->connection);
        $clock = $marketing->site->clock;
        $this->spring = $marketing->campaigns->create(new CampaignInput('Spring sale', 'spring', endsAt: '2026-11-12', budget: '300'))->id;
        $marketing->campaigns->activate('spring');
        $this->winter = $marketing->campaign('Winter warmers', 'winter')->id;
        $advert = $marketing->adverts->create('spring', new AdvertInput('Spring picture', 'words', 'Tea for spring'));
        $marketing->publications->create('spring', new PublicationInput('facebook', $advert->reference, place: 'Cape Town readers', publishedOn: '2026-11-08', cost: '250'));
        $marketing->publication('spring', 'email', 'Newsletter', publishedOn: '2026-11-09');
        $marketing->spend->add('spring', '2026-11-09', '100', 'Designer');
        $marketing->publication('winter', 'print', 'Cape Town readers ', '50', '2026-11-10');

        // 2026-11-09 10:00: the first visitor, a repeat within the visit, then a later visit by another link of theirs.
        $first = $this->open('spring', 'fb1', self::DESKTOP, '198.51.100.7');
        $clock->modify('+5 minutes');
        $this->open('spring', 'fb1', self::DESKTOP, '198.51.100.7', $first);
        $clock->modify('+5 minutes');
        $this->open('spring', 'em1', self::DESKTOP, '198.51.100.7', $first);
        $clock->modify('+50 minutes');
        $this->open('spring', 'fb1', self::PHONE, '203.0.113.9');
        $this->open('spring', 'fb1', 'WhatsApp/2.24.1.6 A', '203.0.113.50');
        $clock->modify('+1 hour');
        $this->open('spring', null, self::DESKTOP, '198.51.100.30', null, ['HTTP_DNT' => '1']);
        // 2026-11-10 10:00: a third visitor by the print advert, and a search engine.
        $clock->modify('+22 hours');
        $this->open('winter', 'pr1', self::PHONE, '198.51.100.20');
        $this->open('winter', null, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', '192.0.2.1');
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testTheTotalsCountClicksVisitorsRepeatsWhatIsSetAsideAndSpend(): void
    {
        $figures = $this->marketing->figures;
        $all = $figures->totals(Period::all());
        self::assertSame([5, 4, 1, 1, 1, 2, 0, 1, 40000], [$all->clicks, $all->uniqueVisitors, $all->repeatVisits, $all->linkPreviews, $all->otherSetAside, $all->setAside(), $all->notRunning,
            $all->sameVisit, $all->spendMinor]);
        $spring = $figures->totals(Period::all(), $this->spring);
        self::assertSame([4, 3, 1, 1, 0, 1, 35000], [$spring->clicks, $spring->uniqueVisitors, $spring->repeatVisits, $spring->linkPreviews, $spring->otherSetAside, $spring->sameVisit, $spring->spendMinor]);
        $winter = $figures->totals(Period::all(), $this->winter);
        self::assertSame([1, 1, 0, 1, 5000], [$winter->clicks, $winter->uniqueVisitors, $winter->linkPreviews, $winter->otherSetAside, $winter->spendMinor]);
        $today = $figures->totals(Period::named('today', new DateTimeImmutable('2026-11-09 18:00', new DateTimeZone('UTC'))));
        self::assertSame([4, 3, 10000], [$today->clicks, $today->uniqueVisitors, $today->spendMinor], 'Only the day\'s openings and costs.');
        $later = $figures->totals(new Period(new DateTimeImmutable('2026-11-10 00:00', new DateTimeZone('UTC'))));
        self::assertSame([1, 1, 5000], [$later->clicks, $later->uniqueVisitors, $later->spendMinor], 'An open end.');
    }

    public function testEachComparisonHasARowForEachWithItsOwnFigures(): void
    {
        $figures = $this->marketing->figures;
        $all = Period::all();
        self::assertSame([['spring', 'Spring sale', 4, 3, 1, 1, 35000], ['winter', 'Winter warmers', 1, 1, 0, 1, 5000]], self::rows($figures->byCampaign($all)));
        self::assertSame([['facebook', 'Facebook', 2, 2, 0, 1, 25000], ['print', 'Print', 1, 1, 0, 0, 5000], ['email', 'Email', 1, 1, 1, 0, 0], ['', 'No channel', 1, 1, 0, 1, 0]],
            self::rows($figures->byChannel($all)));
        $publications = $figures->byPublication($all);
        self::assertSame([['spring/fb1', 'fb1', 2, 2, 0, 1, 25000], ['winter/pr1', 'pr1', 1, 1, 0, 0, 5000], ['spring/em1', 'em1', 1, 1, 1, 0, 0], ['spring/', 'The campaign\'s own link', 1, 1, 0, 0, 0],
            ['winter/', 'The campaign\'s own link', 0, 0, 0, 1, 0]], self::rows($publications));
        self::assertSame(['Spring sale, Facebook, Cape Town readers', 'Winter warmers, Print, Cape Town readers', 'Spring sale, Email, Newsletter', 'Spring sale', 'Winter warmers'],
            array_map(static fn(FigureRow $row): string => $row->context, $publications));
        self::assertSame([['', 'No advert', 3, 3, 1, 1, 5000], ['Spring picture', 2, 2, 0, 1, 25000]], array_map(static fn(array $row): array => $row[0] === '' ? $row : array_slice($row, 1),
            self::rows($figures->byAdvert($all))));
        self::assertSame([['cape town readers', 'Cape Town readers', 3, 3, 0, 1, 30000], ['newsletter', 'Newsletter', 1, 1, 1, 0, 0], ['', 'No place given', 1, 1, 0, 1, 0]],
            self::rows($figures->byPlace($all)), 'The same place on two campaigns is one row, its space typed after it gone.');
        self::assertSame([['ZA', Countries::name('ZA'), 4, 3, 1, 0, 0], ['GB', Countries::name('GB'), 1, 1, 0, 1, 0], ['', 'Unknown', 0, 0, 0, 1, 0]], self::rows($figures->byCountry($all)));
        self::assertSame([['spring/fb1', 'fb1', 2, 2, 0, 1, 25000], ['spring/em1', 'em1', 1, 1, 1, 0, 0], ['spring/', 'The campaign\'s own link', 1, 1, 0, 0, 0]],
            self::rows($figures->breakdown('publication', $all, $this->spring)), 'One campaign\'s alone.');
        try {
            $figures->breakdown('colour', $all);
            self::fail('An unknown comparison was made.');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('campaign, channel, publication', $refused->getMessage());
        }
    }

    public function testByDayEveryDayIsThereInOrder(): void
    {
        $figures = $this->marketing->figures;
        self::assertSame([['2026-11-08', '8 Nov 2026', 0, 0, 0, 0, 25000], ['2026-11-09', '9 Nov 2026', 4, 3, 1, 1, 10000], ['2026-11-10', '10 Nov 2026', 1, 1, 0, 1, 5000]],
            self::rows($figures->byDay(Period::all())));
        $week = Period::dates('2026-11-07', '2026-11-11', new DateTimeZone('UTC'));
        self::assertNotNull($week);
        $days = $figures->byDay($week);
        self::assertSame(['2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11'], array_map(static fn(FigureRow $row): string => $row->key, $days));
        self::assertSame([0, 0, 4, 1, 0], array_map(static fn(FigureRow $row): int => $row->clicks, $days));
        self::assertSame([], $figures->byDay(new Period(new DateTimeImmutable('2027-01-01'))), 'Nothing to show, no days.');
    }

    public function testWhatNeedsAttentionIsFound(): void
    {
        $marketing = $this->marketing;
        $marketing->campaign('Quiet promo', 'quiet');
        $marketing->campaign('Draft idea', 'idea', active: false);
        $marketing->publication('winter', 'other', 'Old flyer', publishedOn: '2026-10-20');
        $found = $marketing->figures->needsAttention($marketing->site->clock->now());
        self::assertSame([
            [Attention::ENDING, 'spring', null, 'Ends on 12 Nov 2026.'],
            [Attention::OVER_BUDGET, 'spring', null, 'Over its budget: R350 spent of R300.'],
            [Attention::NO_PUBLICATION, 'quiet', null, 'Active with no publication: add where it was published.'],
            [Attention::NO_CLICKS, 'winter', 'ot1', 'No click since it was published on 20 Oct 2026.'],
        ], array_map(static fn(Attention $a): array => [$a->kind, $a->campaignCode, $a->publicationCode, $a->words], $found));
    }

    /**
     * @param list<FigureRow> $rows
     * @return list<array{0:string,1:string,2:int,3:int,4:int,5:int,6:int}>
     */
    private static function rows(array $rows): array
    {
        return array_map(static fn(FigureRow $row): array => [$row->key, $row->label, $row->clicks, $row->uniqueVisitors, $row->repeatVisits, $row->setAside, $row->spendMinor], $rows);
    }

    /**
     * Opens a tracking link as a browser and records it, giving the visitor's token from the cookie it was given.
     *
     * @param array<string,string> $server
     */
    private function open(string $campaign, ?string $publication, string $agent, string $ip, ?string $token = null, array $server = []): ?string
    {
        $request = Request::create('/c/' . $campaign . ($publication === null ? '' : '/' . $publication), 'GET', [], $token === null ? [] : [Visitors::COOKIE => $token], [],
            $server + ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent]);
        $opening = $this->marketing->links->open($campaign, $publication, $request);
        self::assertNotNull($opening);

        return $this->marketing->links->record($opening, $request)?->getValue();
    }
}
