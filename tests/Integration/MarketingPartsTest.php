<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\AdvertInput;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Schema\Steps\Step202611132100MarketingCampaigns;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\SamplePictures;
use Symfony\Component\HttpFoundation\Request;

/** What belongs to campaigns: the channels, adverts with their pictures, publications with their codes, and other spend. */
final class MarketingPartsTest extends DatabaseTestCase
{
    private MarketingHarness $marketing;
    private Actor $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection, ['MARKETING_PICTURE_LARGEST' => '50']);
        $this->owner = new Actor('1', 'Ayanda Khumalo');
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testTheUsualChannelsAreThereFromTheStart(): void
    {
        $channels = $this->marketing->channels->all();
        self::assertSame(array_column(Step202611132100MarketingCampaigns::STARTING_CHANNELS, 1), array_map(static fn($channel): string => $channel->label, $channels));
        self::assertSame(['fb', 'ig', 'wa', 'li', 'em', 'x', 'yt', 'tt', 'tg', 'sa', 'pr', 'ot'], array_map(static fn($channel): string => $channel->linkPrefix, $channels));
        foreach ($channels as $channel) {
            self::assertTrue((new \CattoPHP\Ui\IconLibrary())->has($channel->icon), $channel->icon . ' is in the icon library.');
        }
    }

    public function testChannelsAreAddedRenamedReorderedRetiredAndDeletedOnlyWhenUnused(): void
    {
        $channels = $this->marketing->channels;
        $radio = $channels->add('Community radio', 'cr', 'radio', $this->owner);
        self::assertSame(['community-radio', 'cr', true, 130], [$radio->key, $radio->linkPrefix, $radio->active, $radio->position]);
        try {
            $channels->add('', 'fb', 'not-an-icon', key: 'Bad Key!');
            self::fail('A channel typed wrong was added.');
        } catch (MarketingRefused $refused) {
            self::assertSame(['label', 'link_prefix', 'icon', 'key'], array_keys($refused->errors()));
            self::assertSame('Another channel has the prefix fb.', $refused->error('link_prefix'));
        }
        $renamed = $channels->change('community-radio', 'Local radio', 'lr', 'radio', $this->owner);
        self::assertSame(['community-radio', 'Local radio', 'lr'], [$renamed->key, $renamed->label, $renamed->linkPrefix]);
        $channels->move('community-radio', -1);
        $keys = array_map(static fn($channel): string => $channel->key, $channels->all());
        self::assertSame(['other', 'community-radio'], [$keys[12], $keys[11]]);
        $channels->reorder(['print', 'email']);
        self::assertSame(['print', 'email', 'facebook'], array_slice(array_map(static fn($channel): string => $channel->key, $channels->all()), 0, 3));
        $channels->retire('community-radio');
        self::assertNotContains('community-radio', array_map(static fn($channel): string => $channel->key, $channels->all(true)));
        $channels->restore('community-radio');
        self::assertTrue($channels->get('community-radio')?->active);
        $channels->delete('community-radio', $this->owner);
        self::assertNull($channels->get('community-radio'));
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $this->marketing->publication('spring-sale', 'print', 'Library noticeboard');
        $this->refused(fn() => $channels->delete('print'), 'Print has publications, so it is kept: retire it instead.');
        $actions = array_map(static fn($entry): string => $entry->action, $this->marketing->site->activity->recent(20, null, 'marketing_channel'));
        self::assertSame(['marketing.channel_deleted', 'marketing.channel_restored', 'marketing.channel_retired', 'marketing.channels_reordered', 'marketing.channels_reordered',
            'marketing.channel_changed', 'marketing.channel_added'], $actions);
    }

    public function testAnAdvertKeepsItsPictureCheckedByWhatItHoldsAndItsSize(): void
    {
        $marketing = $this->marketing;
        $campaign = $marketing->campaign('Spring sale', 'spring-sale');
        $advert = $marketing->adverts->create('spring-sale', new AdvertInput('Spring picture', 'picture', 'Tea for spring', picturePath: SamplePictures::file($marketing->folder, SamplePictures::WEBP)), $this->owner);
        $picture = $marketing->adverts->picture($advert->reference, $campaign);
        self::assertNotNull($picture);
        self::assertSame(['image/webp', (string) base64_decode(SamplePictures::WEBP)], [$picture->mimeType, (string) file_get_contents($picture->path)]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\.webp$/', $picture->name);
        try {
            $marketing->adverts->create('spring-sale', new AdvertInput('Moving picture', 'picture', picturePath: SamplePictures::file($marketing->folder, SamplePictures::GIF)));
            self::fail('A GIF was kept.');
        } catch (MarketingRefused $refused) {
            self::assertSame('Choose a JPEG, PNG or WebP picture.', $refused->error('picture'));
        }
        $large = $marketing->folder . '/large.png';
        file_put_contents($large, (string) base64_decode(SamplePictures::PNG) . str_repeat("\0", 60 * 1024));
        try {
            $marketing->adverts->create('spring-sale', new AdvertInput('Large picture', 'picture', picturePath: $large));
            self::fail('A picture larger than the setting was kept.');
        } catch (MarketingRefused $refused) {
            self::assertSame('The picture is 60 KB; the largest allowed is 50 KB.', $refused->error('picture'));
        }
        $this->assertRefusedFields(fn() => $marketing->adverts->create('spring-sale', new AdvertInput('', 'film', mediaAddress: 'not an address')), ['name', 'kind', 'media_address']);
        $this->assertRefusedFields(fn() => $marketing->adverts->create('spring-sale', new AdvertInput('Video', 'video')), ['media_address']);
        $this->assertRefusedFields(fn() => $marketing->adverts->create('spring-sale', new AdvertInput('Empty words', 'words')), ['words']);
        $this->assertRefusedFields(fn() => $marketing->adverts->create('spring-sale', new AdvertInput('No picture', 'picture')), ['picture']);
        $this->assertRefusedFields(fn() => $marketing->adverts->create('spring-sale', new AdvertInput('Too large', 'picture', uploadProblem: 'The picture is larger than the site takes: choose a smaller one.')), ['picture']);

        $changed = $marketing->adverts->change($advert->reference, new AdvertInput('Spring picture', 'picture', picturePath: SamplePictures::file($marketing->folder, SamplePictures::PNG)), $this->owner);
        self::assertStringEndsWith('.png', (string) $changed->picture);
        self::assertFileDoesNotExist($picture->path, 'The picture replaced is removed when no advert has it.');
        $video = $marketing->adverts->create('spring-sale', new AdvertInput('Spring video', 'video', mediaAddress: 'https://video.example.com/watch/spring'));
        self::assertSame(['video', 'https://video.example.com/watch/spring', null], [$video->kind, $video->mediaAddress, $video->picture]);
        $words = $marketing->adverts->change($changed->reference, new AdvertInput('Spring words', 'words', 'Tea for spring', removePicture: true));
        self::assertNull($words->picture);
        self::assertSame([], glob($marketing->folder . '/marketing/pictures/*') ?: []);
        self::assertSame(['Spring words', 'Spring video'], array_map(static fn($a): string => $a->name, $marketing->adverts->list($campaign->id)));
    }

    public function testAnAdvertInUseIsRetiredNeverDeletedAndAnotherCampaignsIsNotFound(): void
    {
        $marketing = $this->marketing;
        $spring = $marketing->campaign('Spring sale', 'spring-sale');
        $winter = $marketing->campaign('Winter warmers', 'winter');
        $used = $marketing->adverts->create('spring-sale', new AdvertInput('Spring words', 'words', 'Tea for spring'));
        $spare = $marketing->adverts->create('spring-sale', new AdvertInput('Spare words', 'words', 'Spare'));
        $marketing->publications->create('spring-sale', new PublicationInput('facebook', $used->reference));
        self::assertNull($marketing->adverts->get($used->reference, $winter), 'Another campaign\'s advert is simply not found.');
        $this->refused(fn() => $marketing->adverts->retire($used->reference, of: $winter), 'There is no such advert.');
        $this->refused(fn() => $marketing->adverts->delete($used->reference), 'This advert has been published, so it is kept: retire it instead.');
        self::assertTrue($marketing->adverts->retire($used->reference, $this->owner)->isRetired());
        $this->refused(fn() => $marketing->adverts->retire($used->reference), 'This advert is already retired.');
        self::assertSame(['Spare words'], array_map(static fn($a): string => $a->name, $marketing->adverts->list($spring->id, false)));
        self::assertFalse($marketing->adverts->restore($used->reference)->isRetired());
        $this->refused(fn() => $marketing->adverts->restore($used->reference), 'This advert is not retired.');
        $marketing->adverts->delete($spare->reference, $this->owner);
        self::assertNull($marketing->adverts->get($spare->reference));
        $actions = array_map(static fn($entry): string => $entry->action, $marketing->campaigns->history($spring));
        foreach (['marketing.advert_created', 'marketing.advert_retired', 'marketing.advert_restored', 'marketing.advert_deleted', 'marketing.publication_created'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function testAPublicationIsGivenTheNextCodeOfItsChannelAndKeepsItOnceOpened(): void
    {
        $marketing = $this->marketing;
        $campaign = $marketing->campaign('Spring sale', 'spring-sale');
        $advert = $marketing->adverts->create('spring-sale', new AdvertInput('Spring words', 'words', 'Tea for spring'));
        self::assertSame('fb1', $marketing->publications->suggestCode($campaign, 'facebook'));
        $first = $marketing->publications->create('spring-sale', new PublicationInput('facebook', $advert->reference, '', 'Cape Town readers', '2026-11-08', 'https://social.example.com/post/1',
            '250', '/shop/tea-100', 'Pinned for a week.'), $this->owner);
        self::assertSame(['fb1', 'facebook', $advert->id, 'Cape Town readers', '2026-11-08', 25000, '/shop/tea-100'],
            [$first->code, $first->channelKey, $first->advertId, $first->place, $first->publishedOn?->format('Y-m-d'), $first->costMinor, $first->destination]);
        self::assertSame('fb2', $marketing->publication('spring-sale', 'facebook')->code);
        $marketing->publications->create('spring-sale', new PublicationInput('facebook', code: 'fb7'));
        self::assertSame(['fb8', 'x1', 'em1'], [$marketing->publications->suggestCode($campaign, 'facebook'), $marketing->publications->suggestCode($campaign, 'x'), $marketing->publications->suggestCode($campaign, 'email')]);
        $this->assertRefusedFields(fn() => $marketing->publications->create('spring-sale', new PublicationInput('no-channel', 'no-advert', 'FB 1!', str_repeat('p', 201), '8 Nov', 'nowhere', 'free', '//elsewhere')),
            ['channel', 'advert', 'code', 'place', 'published_on', 'published_address', 'cost', 'destination']);
        $this->assertRefusedFields(fn() => $marketing->publications->create('spring-sale', new PublicationInput('facebook', code: 'fb1')), ['code']);
        $marketing->channels->retire('print');
        $this->assertRefusedFields(fn() => $marketing->publications->create('spring-sale', new PublicationInput('print')), ['channel']);

        $changed = $marketing->publications->change('spring-sale', 'fb1', new PublicationInput('facebook', '', 'fbcape', 'Cape Town readers', cost: '300'), $this->owner);
        self::assertSame(['fbcape', null, 30000], [$changed->code, $changed->advertId, $changed->costMinor]);
        $request = Request::create('/c/spring-sale/fbcape', server: ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0']);
        $opening = $marketing->links->open('spring-sale', 'fbcape', $request);
        self::assertNotNull($opening);
        $marketing->links->record($opening, $request);
        self::assertTrue($marketing->publications->hasClicks($changed));
        try {
            $marketing->publications->change('spring-sale', 'fbcape', new PublicationInput('facebook', code: 'fb1x'));
            self::fail('The code of a publication whose link was opened changed.');
        } catch (MarketingRefused $refused) {
            self::assertSame('People have opened this publication\'s link, so its code stays fbcape.', $refused->error('code'));
        }
        $this->refused(fn() => $marketing->publications->delete('spring-sale', 'fbcape'), 'This publication\'s link has been opened, so it is kept: retire it instead.');
        self::assertTrue($marketing->publications->retire('spring-sale', 'fbcape')->isRetired());
        self::assertSame(['fb2', 'fb7'], array_map(static fn($p): string => $p->code, $marketing->publications->list($campaign->id, false)));
        self::assertFalse($marketing->publications->restore('spring-sale', 'fbcape')->isRetired());
        $marketing->publications->delete('spring-sale', 'fb7', $this->owner);
        self::assertNull($marketing->publications->get($campaign, 'fb7'));
        $this->refused(fn() => $marketing->publications->retire('spring-sale', 'fb7'), 'There is no such publication.');
    }

    public function testAPublicationMayStandForSomethingOfTheProjects(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $this->marketing->publications->create('spring-sale', new PublicationInput('email', place: 'Newsletter', sourceType: 'mailing_message', sourceReference: '42'));
        $found = $this->marketing->publications->forSource('mailing_message', '42');
        self::assertSame([['em1', 'mailing_message', '42']], array_map(static fn($p): array => [$p->code, $p->sourceType, $p->sourceReference], $found));
    }

    public function testOtherSpendIsAddedAndRemovedAndCountsWithThePublicationsCosts(): void
    {
        $marketing = $this->marketing;
        $campaign = $marketing->campaign('Spring sale', 'spring-sale');
        $marketing->publication('spring-sale', 'facebook', cost: '250');
        $item = $marketing->spend->add('spring-sale', '2026-11-05', 'R1 200,50', 'Designer', $this->owner);
        self::assertSame(['2026-11-05', 120050, 'Designer', '1'], [$item->spentOn->format('Y-m-d'), $item->amountMinor, $item->note, $item->createdBy]);
        self::assertSame('2026-11-09', $marketing->spend->add('spring-sale', '', '10', '')->spentOn->format('Y-m-d'), 'Today when no date is typed.');
        $this->assertRefusedFields(fn() => $marketing->spend->add('spring-sale', '5 Nov', '0', str_repeat('n', 301)), ['spent_on', 'amount', 'note']);
        self::assertSame([121050, 146050], [$marketing->spend->otherTotal($campaign->id), $marketing->spend->total($campaign->id)]);
        $marketing->spend->remove('spring-sale', $item->reference, $this->owner);
        self::assertSame([1000, 1], [$marketing->spend->otherTotal($campaign->id), count($marketing->spend->list($campaign->id))]);
        $this->refused(fn() => $marketing->spend->remove('spring-sale', $item->reference), 'There is no such cost.');
        $history = $marketing->campaigns->history($campaign);
        self::assertSame('marketing.spend_removed', $history[0]->action);
        self::assertSame(120050, $history[0]->details['amount_minor']);
    }

    /** @param list<string> $fields */
    private function assertRefusedFields(callable $action, array $fields): void
    {
        try {
            $action();
            self::fail('Not refused: ' . implode(', ', $fields));
        } catch (MarketingRefused $refused) {
            self::assertSame($fields, array_keys($refused->errors()));
        }
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
