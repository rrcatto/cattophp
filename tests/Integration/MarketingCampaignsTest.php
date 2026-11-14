<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\AdvertInput;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\SamplePictures;
use Symfony\Component\HttpFoundation\Request;

/** Campaigns: made, changed, listed, activated, paused, archived, restored, copied and deleted by the plan's rules, each in its history. */
final class MarketingCampaignsTest extends DatabaseTestCase
{
    private MarketingHarness $marketing;
    private Actor $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection, ['APP_TIMEZONE' => 'Africa/Johannesburg']);
        $this->owner = new Actor('1', 'Ayanda Khumalo');
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testACampaignIsMadeAsTypedWithItsCodeSuggestedAndItsOffers(): void
    {
        $campaigns = $this->marketing->campaigns;
        $campaign = $campaigns->create(new CampaignInput('Spring Sale 2026', description: 'Tea for spring.', objective: 'Sell 200 boxes.', startsAt: '2026-11-10', endsAt: '2026-11-30',
            budget: '1 500.50', destination: '/shop?from=spring', promotion: 'SPRING10', offers: ['product:tea-100', 'bundle:gift-box']), $this->owner);
        self::assertSame(['spring-sale-2026', 'Spring Sale 2026', 'draft', 150050, '/shop?from=spring', 'SPRING10', '1'],
            [$campaign->code, $campaign->name, $campaign->status, $campaign->budgetMinor, $campaign->destination, $campaign->promotion, $campaign->createdBy]);
        self::assertSame(['2026-11-10 00:00:00', '2026-11-30 23:59:59', 'Africa/Johannesburg'], [$campaign->startsAt?->format('Y-m-d H:i:s'), $campaign->endsAt?->format('Y-m-d H:i:s'),
            $campaign->startsAt?->getTimezone()->getName()], 'Dates in the installation\'s time zone, the end the last second of its day.');
        $offers = $campaigns->offers($campaign->id);
        self::assertSame([['product:tea-100', 'Rooibos tea, 100 bags'], ['bundle:gift-box', 'Gift box']], array_map(static fn($offer): array => [$offer->key(), $offer->label], $offers));
        self::assertSame('spring-sale-2026-2', $campaigns->suggestCode('Spring Sale 2026'), 'A code taken gets a number.');
        self::assertSame('campaign', $campaigns->suggestCode('!!'));
        $history = $campaigns->history($campaign);
        self::assertSame(['marketing.campaign_created'], array_map(static fn($entry): string => $entry->action, $history));
        self::assertSame('Ayanda Khumalo', $history[0]->actorName);
    }

    public function testWhatIsTypedWrongIsRefusedFieldByFieldAndNothingIsMade(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        try {
            $this->marketing->campaigns->create(new CampaignInput('', 'spring-sale', startsAt: '1 December', endsAt: '', budget: 'lots', destination: '//example.com', promotion: 'NOPE',
                offers: ['product:no-such']));
            self::fail('A campaign typed wrong was made.');
        } catch (MarketingRefused $refused) {
            self::assertSame(['name', 'code', 'starts_at', 'budget', 'destination', 'promotion', 'offers'], array_keys($refused->errors()));
            self::assertSame('Another campaign has the code spring-sale.', $refused->error('code'));
        }
        try {
            $this->marketing->campaigns->create(new CampaignInput('Winter', 'Winter Sale!', startsAt: '2026-12-01', endsAt: '2026-11-01'));
            self::fail('A campaign ending before it starts was made.');
        } catch (MarketingRefused $refused) {
            self::assertSame(['code', 'ends_at'], array_keys($refused->errors()));
            self::assertSame('The end comes after the start.', $refused->error('ends_at'));
        }
        self::assertSame(1, $this->marketing->campaigns->count());
    }

    public function testItsStatusMovesByTheButtonsAndEachIsInItsHistory(): void
    {
        $campaigns = $this->marketing->campaigns;
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale', active: false);
        self::assertSame('active', $campaigns->activate('spring-sale', $this->owner)->status);
        self::assertSame('paused', $campaigns->pause('spring-sale', $this->owner)->status);
        self::assertSame('archived', $campaigns->archive('spring-sale', $this->owner)->status);
        $this->refused(fn() => $campaigns->activate('spring-sale'), 'Restore this campaign before activating it.');
        $this->refused(fn() => $campaigns->archive('spring-sale'), 'This campaign is already archived.');
        self::assertSame('paused', $campaigns->restore('spring-sale', $this->owner)->status, 'An archived campaign comes back as it was, an active one paused.');
        $this->refused(fn() => $campaigns->pause('spring-sale'), 'Only an active campaign can be paused.');
        $this->refused(fn() => $campaigns->restore('spring-sale'), 'Only an archived campaign can be restored.');
        $draft = $this->marketing->campaign('Draft only', active: false);
        $campaigns->archive($draft->code);
        self::assertSame('draft', $campaigns->restore($draft->code)->status, 'A draft comes back a draft.');
        $actions = array_map(static fn($entry): string => $entry->action, $campaigns->history($campaign));
        self::assertSame(['marketing.campaign_restored', 'marketing.campaign_archived', 'marketing.campaign_paused', 'marketing.campaign_activated', 'marketing.campaign_created'], $actions);
        $this->refused(fn() => $campaigns->activate('no-such-campaign'), 'There is no such campaign.');
    }

    public function testItsCodeChangesOnlyUntilSomeoneOpensItsLinksAndItsHistoryKeepsTheOldCode(): void
    {
        $campaigns = $this->marketing->campaigns;
        $campaign = $this->marketing->campaign('Spring sale', 'spring');
        $changed = $campaigns->change('spring', new CampaignInput('Spring sale', 'spring-sale', budget: '900', offers: ['product:mug-01']), $this->owner);
        self::assertSame(['spring-sale', ['spring'], 90000], [$changed->code, $changed->formerCodes, $changed->budgetMinor]);
        self::assertNull($campaigns->get('spring'));
        self::assertSame(['marketing.campaign_changed', 'marketing.campaign_activated', 'marketing.campaign_created'], array_map(static fn($entry): string => $entry->action, $campaigns->history($changed)),
            'Its history under both codes.');
        $this->open('/c/spring-sale');
        self::assertTrue($campaigns->hasClicks($changed));
        try {
            $campaigns->change('spring-sale', new CampaignInput('Spring sale', 'spring-2026'));
            self::fail('The code of a campaign whose links were opened changed.');
        } catch (MarketingRefused $refused) {
            self::assertSame('People have opened this campaign\'s links, so its code stays spring-sale.', $refused->error('code'));
        }
        $kept = $campaigns->change('spring-sale', new CampaignInput('Spring sale, renamed', ''));
        self::assertSame(['spring-sale', 'Spring sale, renamed', '/'], [$kept->code, $kept->name, $kept->destination], 'Left empty, the code stays and the destination is the front page.');
        self::assertSame($campaign->id, $kept->id);
    }

    public function testAnOfferTheProjectNoLongerHasStaysOnTheCampaignItWasChosenFor(): void
    {
        $campaigns = $this->marketing->campaigns;
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale');
        $this->connection->insert('cattophp_marketing_campaign_offers', ['campaign_id' => $campaign->id, 'offer_type' => 'product', 'offer_reference' => 'old-tea', 'label' => 'Old tea', 'position' => 0]);
        $campaigns->change('spring-sale', new CampaignInput('Spring sale', offers: ['product:old-tea', 'product:mug-01']));
        self::assertSame(['Old tea', 'Enamel mug'], array_map(static fn($offer): string => $offer->label, $campaigns->offers($campaign->id)));
    }

    public function testACopyIsADraftWithItsAdvertsAndOffersAndNothingElse(): void
    {
        $marketing = $this->marketing;
        $campaign = $marketing->campaigns->create(new CampaignInput('Spring sale', 'spring-sale', startsAt: '2026-11-10', budget: '500', promotion: 'SPRING10', offers: ['product:tea-100']));
        $marketing->campaigns->activate('spring-sale');
        $picture = SamplePictures::file($marketing->folder, SamplePictures::PNG);
        $advert = $marketing->adverts->create('spring-sale', new AdvertInput('Spring picture', 'picture', picturePath: $picture));
        $retired = $marketing->adverts->create('spring-sale', new AdvertInput('Old words', 'words', 'Old headline'));
        $marketing->adverts->retire($retired->reference);
        $marketing->publication('spring-sale', 'facebook', cost: '200');
        $marketing->spend->add('spring-sale', '2026-11-09', '50', 'Designer');
        $copy = $marketing->campaigns->copy('spring-sale', $this->owner);
        self::assertSame(['spring-sale-copy', 'Spring sale (copy)', 'draft', null, 50000, 'SPRING10'], [$copy->code, $copy->name, $copy->status, $copy->startsAt, $copy->budgetMinor, $copy->promotion]);
        self::assertSame(['product:tea-100'], array_map(static fn($offer): string => $offer->key(), $marketing->campaigns->offers($copy->id)));
        $adverts = $marketing->adverts->list($copy->id);
        self::assertSame([['Spring picture', $advert->picture]], array_map(static fn($a): array => [$a->name, $a->picture], $adverts), 'Its adverts not retired, the picture shared.');
        self::assertNotSame($advert->reference, $adverts[0]->reference);
        self::assertSame([[], 0], [$marketing->publications->list($copy->id), $marketing->spend->total($copy->id)]);
        self::assertSame('spring-sale-copy-2', $marketing->campaigns->copy('spring-sale')->code);
        self::assertSame(['marketing.campaign_copied'], array_map(static fn($entry): string => $entry->action, $marketing->campaigns->history($copy)));
        self::assertSame('spring-sale', $marketing->campaigns->history($copy)[0]->details['copied_from']);
        self::assertSame($campaign->id, $marketing->campaigns->get('spring-sale')?->id);
    }

    public function testOnlyACampaignNeverPublishedOrOpenedIsDeletedAndItsPictureGoesWhenNobodyElseHasIt(): void
    {
        $marketing = $this->marketing;
        $marketing->campaign('Spring sale', 'spring-sale', active: false);
        $advert = $marketing->adverts->create('spring-sale', new AdvertInput('Spring picture', 'picture', picturePath: SamplePictures::file($marketing->folder, SamplePictures::JPEG)));
        $stored = $marketing->pictures->read($advert->picture);
        self::assertNotNull($stored);
        self::assertTrue($marketing->campaigns->canDelete($marketing->campaigns->require('spring-sale')));
        $marketing->campaigns->delete('spring-sale', $this->owner);
        self::assertNull($marketing->campaigns->get('spring-sale'));
        self::assertFileDoesNotExist($stored->path);
        self::assertSame('marketing.campaign_deleted', $marketing->site->activity->recent(1)[0]->action);
        $marketing->site->clock->modify('+1 hour');
        $again = $marketing->campaign('Spring sale again', 'spring-sale', active: false);
        self::assertSame(['marketing.campaign_created'], array_map(static fn($entry): string => $entry->action, $marketing->campaigns->history($again)), 'A code given again starts a history of its own.');

        $marketing->campaign('Published', 'published');
        $marketing->publication('published', 'print', 'Library noticeboard');
        $this->refused(fn() => $marketing->campaigns->delete('published'), 'This campaign has been published, its links have been opened or its landing page has been public, so it is kept: archive it instead.');
        $marketing->campaign('Opened', 'opened');
        $this->open('/c/opened');
        $this->refused(fn() => $marketing->campaigns->delete('opened'), 'This campaign has been published, its links have been opened or its landing page has been public, so it is kept: archive it instead.');
    }

    public function testTheListIsNarrowedByStatusAndSearchAndSortedWithItsFigures(): void
    {
        $marketing = $this->marketing;
        $clock = $marketing->site->clock;
        $marketing->campaigns->create(new CampaignInput('Autumn draft', 'autumn'));
        $marketing->campaigns->create(new CampaignInput('Winter warmers', 'winter', startsAt: '2026-12-01'));
        $marketing->campaigns->activate('winter');
        $marketing->campaigns->create(new CampaignInput('Spring sale', 'spring', budget: '100', description: 'Rooibos for everyone'));
        $marketing->campaigns->activate('spring');
        $marketing->campaigns->create(new CampaignInput('Old summer', 'summer', startsAt: '2026-10-01', endsAt: '2026-10-31'));
        $marketing->campaigns->activate('summer');
        $marketing->campaigns->create(new CampaignInput('Paused promo', 'paused'));
        $marketing->campaigns->activate('paused');
        $marketing->campaigns->pause('paused');
        $marketing->campaigns->create(new CampaignInput('Archived one', 'archived'));
        $marketing->campaigns->archive('archived');
        $marketing->publication('spring', 'facebook', cost: '150');
        $marketing->spend->add('spring', '', '25', 'Printing');
        $this->open('/c/spring/fb1');
        $clock->modify('+1 hour');
        $this->open('/c/spring', '203.0.113.9', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1');

        $codes = static fn(array $list): array => array_map(static fn($summary): string => $summary->campaign->code, $list);
        self::assertSame(['all' => 6, 'current' => 5, 'draft' => 1, 'scheduled' => 1, 'active' => 1, 'paused' => 1, 'ended' => 1, 'archived' => 1], $marketing->campaigns->statusCounts());
        foreach (['draft' => 'autumn', 'scheduled' => 'winter', 'active' => 'spring', 'ended' => 'summer', 'paused' => 'paused', 'archived' => 'archived'] as $status => $code) {
            self::assertSame([$code], $codes($marketing->campaigns->list($status)), $status);
            self::assertSame(1, $marketing->campaigns->count($status));
        }
        self::assertCount(5, $marketing->campaigns->list('current'));
        self::assertSame(['spring'], $codes($marketing->campaigns->list(null, 'rooibos')), 'Search reads the description too.');
        self::assertSame(['archived', 'autumn', 'paused', 'spring', 'summer', 'winter'], $codes($marketing->campaigns->list(null, '', 'code', 'asc')));
        $spring = $marketing->campaigns->list('active')[0];
        self::assertSame([1, 2, 2, 17500, true, 175], [$spring->publications, $spring->clicks, $spring->uniqueVisitors, $spring->spendMinor, $spring->overBudget(), $spring->budgetUsedPercent()]);
        self::assertSame('spring', $codes($marketing->campaigns->list(null, '', 'clicks', 'desc'))[0]);
        self::assertSame(['archived', 'autumn'], $codes($marketing->campaigns->list(null, '', 'name', 'asc', 2)));
        self::assertSame(['autumn'], $codes($marketing->campaigns->list(null, '', 'name', 'asc', 1, 1)), 'A page further on.');
    }

    /** Opens a tracking link as a person's browser would, recording the touch. */
    private function open(string $path, string $ip = '198.51.100.7', string $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0'): void
    {
        $parts = explode('/', trim((string) parse_url($path, PHP_URL_PATH), '/'));
        $request = Request::create($path, 'GET', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent]);
        $opening = $this->marketing->links->open($parts[1], $parts[2] ?? null, $request);
        self::assertNotNull($opening);
        $this->marketing->links->record($opening, $request);
    }

    private function refused(callable $action, string $why): void
    {
        try {
            $action();
            self::fail('Not refused: ' . $why);
        } catch (MarketingRefused $refused) {
            self::assertSame($why, $refused->getMessage());
        }
    }
}
