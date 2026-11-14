<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Landing\Events\LandingPageChanged;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingSection;
use CattoPHP\Marketing\Landing\LandingSettingsInput;
use CattoPHP\Marketing\Landing\LandingVersion;
use CattoPHP\Marketing\Landing\NotRunning;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Schema\FoundationSteps;
use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Schema\Steps\Step202611140010MarketingLanding;
use CattoPHP\Schema\Steps\Step202611141200MarketingSales;
use CattoPHP\Schema\Steps\Step202611141500MarketingForgottenCustomers;
use CattoPHP\Tests\Support\ChangingShop;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\SamplePictures;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Clock\MockClock;

/**
 * Landing pages against the database, for both kinds of page, by the rules course landing pages keep today (R1 to R15 of
 * the step 2 plan) and the new ones: one page a campaign and one an offer, the address's rules, the publish checks, the
 * delete rule, every change under the page's row lock with a compact order, kinds held once, data checked again on every
 * read, kept versions written and never changed, Use this version again, templates, an offer renamed or removed, a
 * campaign's copy and deletion, and a picture kept while anything names it.
 */
final class MarketingLandingTest extends DatabaseTestCase
{
    private MarketingHarness $marketing;
    private ChangingShop $shop;
    private Actor $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = new ChangingShop();
        $this->marketing = new MarketingHarness($this->connection, ['APP_TIMEZONE' => 'Africa/Johannesburg'], $this->shop);
        $this->owner = new Actor('1', 'Ayanda Khumalo');
        $this->marketing->campaigns->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop', promotion: 'SPRING10', offers: ['product:tea-100', 'bundle:gift-box']));
        $this->marketing->campaigns->activate('spring-sale');
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testACampaignHasOnePageMadeFromATemplateAsADraft(): void
    {
        $pages = $this->marketing->pages;
        $result = $pages->createForCampaign('spring-sale', null, $this->owner);
        $page = $result->page;
        self::assertSame([true, false, 'draft', null, '/c/spring-sale', 'Spring sale', []], [$page->isCampaignPage(), $page->isOfferPage(), $page->status, $page->slug, $pages->address($page),
            $pages->title($page), $result->skipped]);
        self::assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $page->reference, 'Its address names it by a reference, never a row number.');
        $sections = $this->marketing->sections->list($page);
        self::assertSame(['hero', 'offers', 'faq', 'cta'], array_map(static fn(LandingSection $s): string => $s->kindKey, $sections), 'Several offers, the first template that suits.');
        self::assertSame([0, 1, 2, 3], array_map(static fn(LandingSection $s): int => $s->position, $sections));
        self::assertSame(['product:tea-100', 'bundle:gift-box'], $sections[1]->data['offers'], 'The offers section takes the campaign\'s offers.');
        self::assertSame([true, true, true, true], array_map(static fn(LandingSection $s): bool => $s->enabled, $sections));
        self::assertEquals($page, $pages->forCampaign($this->marketing->campaigns->require('spring-sale')));
        self::assertEquals($page, $pages->get(strtoupper($page->reference)));

        $this->refused(fn() => $pages->createForCampaign('spring-sale'), 'This campaign already has a landing page.');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_pages (reference, campaign_id) SELECT 'zzzzzzzz', campaign_id FROM cattophp_marketing_landing_pages"),
            'cattophp_marketing_landing_pages_campaign_id_key');
        $entries = $this->marketing->site->activity->recent(10, 'marketing.landing_page.created');
        self::assertSame(['marketing_landing_page', $page->reference, 'spring-sale', 'Ayanda Khumalo'], [$entries[0]->subjectType, $entries[0]->subjectId, $entries[0]->details['campaign'] ?? null, $entries[0]->actorName]);
        $announced = $this->marketing->dispatcher->of(LandingPageChanged::class);
        self::assertSame(['marketing.landing_page.created'], array_map(static fn(LandingPageChanged $e): string => $e->action, $announced));

        $this->marketing->campaigns->create(new CampaignInput('Winter sale', 'winter-sale'));
        $special = $pages->createForCampaign('winter-sale', 'special-with-end-date');
        self::assertSame(['hero', 'countdown', 'offers', 'faq', 'cta'], array_map(static fn(LandingSection $s): string => $s->kindKey, $this->marketing->sections->list($special->page)));
        $this->refused(fn() => $pages->createForOffer('product', 'mug-01', 'special-with-end-date'), 'Choose one of the templates.', 'template');
    }

    public function testAnOffersPageHasItsOwnAddressFreeAmongLandingPages(): void
    {
        $pages = $this->marketing->pages;
        $page = $pages->createForOffer('product', 'tea-100', null, $this->owner)->page;
        self::assertSame([true, 'product', 'tea-100', 'rooibos-tea-100-bags', '/landing/rooibos-tea-100-bags', 'Rooibos tea, 100 bags'],
            [$page->isOfferPage(), $page->offerType, $page->offerReference, $page->slug, $pages->address($page), $pages->title($page)]);
        $second = $pages->createForOffer('product', 'mug-01', null, null, 'rooibos-tea-100-bags')->page;
        self::assertSame('rooibos-tea-100-bags-2', $second->slug, 'A slug taken gets a number.');
        $own = $pages->createForOffer('product', 'pot-02')->page;
        self::assertSame('blue-enamel-teapot', $own->slug, 'An offer with a slug of its own starts its page\'s address there, not from its label.');
        $pages->delete($own->reference);
        self::assertEquals($page, $pages->bySlug('Rooibos-Tea-100-Bags'));
        self::assertNull($pages->bySlug('not a slug'));
        $this->refused(fn() => $pages->createForOffer('product', 'tea-100'), 'This product already has a landing page.');
        $this->refused(fn() => $pages->createForOffer('product', 'no-such'), 'There is no such offer.');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_pages (reference, offer_type, offer_reference, slug) VALUES ('zzzzzzz1', 'product', 'tea-100', 'another')"),
            'cattophp_marketing_landing_pages_one_per_offer');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_pages (reference) VALUES ('zzzzzzz2')"), 'cattophp_marketing_landing_pages_one_owner');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_pages (reference, campaign_id, offer_type, offer_reference, slug) SELECT 'zzzzzzz3', id, 'product', 'mug-01', 'mugs' FROM cattophp_marketing_campaigns"),
            'cattophp_marketing_landing_pages_one_owner');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_pages (reference, offer_type, offer_reference) VALUES ('zzzzzzz4', 'product', 'pot-02')"),
            'cattophp_marketing_landing_pages_slug');

        $this->refusedFields(fn() => $pages->saveSettings($page->reference, new LandingSettingsInput('Not A Slug!')), ['slug']);
        $this->refusedFields(fn() => $pages->saveSettings($page->reference, new LandingSettingsInput('')), ['slug']);
        $this->refusedFields(fn() => $pages->saveSettings($page->reference, new LandingSettingsInput(str_repeat('a', 121))), ['slug']);
        $this->refusedFields(fn() => $pages->saveSettings($page->reference, new LandingSettingsInput('rooibos-tea-100-bags-2', str_repeat('t', 121), str_repeat('d', 301), ['source' => 'NOPE'])),
            ['slug', 'meta_title', 'meta_description', 'share_picture']);
        $saved = $pages->saveSettings($page->reference, new LandingSettingsInput('spring-rooibos', 'Rooibos for spring', 'A private sentence about the tea.'), $this->owner);
        self::assertSame(['spring-rooibos', 'Rooibos for spring', 'A private sentence about the tea.', 'keep'], [$saved->slug, $saved->metaTitle, $saved->metaDescription, $saved->notRunning],
            'An offer\'s page has no not-running choice.');
        $entry = $this->marketing->site->activity->recent(1, 'marketing.landing_page.settings_saved')[0];
        self::assertSame(['rooibos-tea-100-bags', 'spring-rooibos', ['slug', 'meta_title', 'meta_description']], [$entry->details['slug_before'] ?? null, $entry->details['slug'] ?? null, $entry->details['changed'] ?? null]);
        self::assertStringNotContainsString('A private sentence', (string) json_encode($entry->details), 'The log names the fields changed, never their copy.');
        $count = count($this->marketing->site->activity->recent(50));
        $pages->saveSettings($page->reference, new LandingSettingsInput('spring-rooibos', 'Rooibos for spring', 'A private sentence about the tea.'));
        self::assertCount($count, $this->marketing->site->activity->recent(50), 'Nothing changed, nothing recorded.');
    }

    public function testACampaignPagesSettingsSayWhatItDoesWhileTheCampaignIsNotRunning(): void
    {
        $pages = $this->marketing->pages;
        $page = $pages->createForCampaign('spring-sale')->page;
        $this->refusedFields(fn() => $pages->saveSettings($page->reference, new LandingSettingsInput(notRunning: 'vanish')), ['not_running']);
        $this->refusedFields(fn() => $pages->saveSettings($page->reference, new LandingSettingsInput(notRunning: 'forward', notRunningTo: 'https://example.com/elsewhere')), ['not_running_to']);
        $forward = $pages->saveSettings($page->reference, new LandingSettingsInput('ignored', notRunning: NotRunning::FORWARD));
        self::assertSame([null, 'forward', '/shop'], [$forward->slug, $forward->notRunning, $forward->notRunningTo], 'The campaign\'s destination to start; a campaign\'s page has no slug.');
        $note = $pages->saveSettings($page->reference, new LandingSettingsInput(notRunning: NotRunning::NOTE, notRunningTo: '/offers'));
        self::assertSame(['note', '/offers'], [$note->notRunning, $note->notRunningTo]);
    }

    public function testPublishingChecksTheOfferAndTheSectionsAndUnpublishingKeepsEverything(): void
    {
        $pages = $this->marketing->pages;
        $page = $pages->createForOffer('product', 'tea-100')->page;
        $problems = $pages->problems($page);
        self::assertSame(['Questions and answers has no questions yet.'], $problems, 'The offers section has the page\'s offer; the questions are still to write.');
        $this->refused(fn() => $pages->publish($page->reference), 'The page was not published. Questions and answers has no questions yet.');
        $faq = $this->section($page, 'faq');
        $this->marketing->sections->save($page->reference, $faq->reference, ['items' => [['question' => 'How is it brewed?', 'answer' => 'Four minutes.']]]);
        self::assertSame([], $pages->problems($page));

        $this->shop->hide('product:tea-100');
        self::assertSame(['The product is not published, so its landing page cannot be either.'], $pages->problems($page));
        $this->shop->show('product:tea-100');
        $this->connection->executeStatement("UPDATE cattophp_marketing_landing_sections SET enabled = false WHERE landing_page_id = :id", ['id' => $page->id]);
        self::assertSame(['Every section is hidden, so the page would be empty.'], $pages->problems($page), 'Hidden sections are never checked.');
        $this->connection->executeStatement("UPDATE cattophp_marketing_landing_sections SET enabled = true WHERE landing_page_id = :id", ['id' => $page->id]);

        $published = $pages->publish($page->reference, $this->owner);
        self::assertSame(['published', true], [$published->status, $published->hasBeenPublic()]);
        self::assertNotNull($published->publishedAt);
        $this->refusedSql(fn() => $this->connection->executeStatement('UPDATE cattophp_marketing_landing_pages SET published_at = NULL WHERE id = :id', ['id' => $page->id]),
            'cattophp_marketing_landing_pages_published_when');
        self::assertCount(1, $this->marketing->site->activity->recent(10, 'marketing.landing_page.published'));
        $pages->publish($page->reference);
        self::assertCount(1, $this->marketing->site->activity->recent(10, 'marketing.landing_page.published'), 'Publishing a published page does nothing.');
        self::assertSame([$page->reference], array_map(static fn(LandingPage $p): string => $p->reference, $pages->publishedOfferPages()));

        $taken = $pages->unpublish($page->reference, $this->owner);
        self::assertSame(['draft', null, true], [$taken->status, $taken->publishedAt, $taken->hasBeenPublic()]);
        self::assertSame('How is it brewed?', $this->section($page, 'faq')->data['items'][0]['question'] ?? null, 'Nothing on it was lost.');
        self::assertSame([], $pages->publishedOfferPages());
        $this->refused(fn() => $pages->delete($page->reference), 'This page has been public, so it is unpublished rather than deleted.');

        $draft = $pages->createForOffer('product', 'mug-01')->page;
        $pages->delete($draft->reference, $this->owner);
        self::assertNull($pages->get($draft->reference));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_landing_sections WHERE landing_page_id = :id', ['id' => $draft->id]), 'Its sections go with it.');

        $missing = $pages->createForOffer('product', 'pot-02')->page;
        self::assertContains('The product is not published, so its landing page cannot be either.', $pages->problems($missing), 'A draft product\'s page waits for it.');
        $this->shop->remove('product:pot-02');
        self::assertContains('The product is no longer on the site, so its landing page cannot be published.', $pages->problems($missing));
    }

    public function testSectionsAreAddedSavedShownHiddenRemovedAndAKindHeldOnceIsRefusedASecondTime(): void
    {
        $page = $this->marketing->pages->createForCampaign('spring-sale')->page;
        $sections = $this->marketing->sections;
        $added = $sections->add($page->reference, 'faq', $this->owner);
        self::assertSame([false, true, 4], [$added->enabled, $added->unsaved, $added->position], 'A new section goes to the end, hidden until it is saved.');
        $saved = $sections->save($page->reference, $added->reference, ['heading' => 'More questions', 'items' => [['question' => 'Is there a sale?', 'answer' => "Yes.\n\nIn spring."]]], $this->owner);
        self::assertSame([true, false, 'More questions'], [$saved->enabled, $saved->unsaved, $saved->data['heading']], 'Its first save shows it.');
        $this->refusedFields(fn() => $sections->save($page->reference, $added->reference, ['items' => [['question' => 'No answer', 'answer' => '']]]), ['items.0.answer']);
        self::assertSame('Is there a sale?', $this->section($page, 'faq', 1)->data['items'][0]['question'], 'A refused save leaves the section as it was.');

        $audience = $sections->add($page->reference, 'audience');
        $this->refusedFields(fn() => $sections->add($page->reference, 'audience'), ['kind'], 'The page already has a who it is for section; a page has only one.');
        $this->refusedFields(fn() => $sections->add($page->reference, 'hero'), ['kind'], 'The page already has a hero section; a page has only one.');
        $this->refusedFields(fn() => $sections->add($page->reference, 'html'), ['kind'], 'Choose a kind of section.');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_sections (reference, landing_page_id, kind, position) VALUES ('zzzzzzzz', :page, 'hero', 0)",
            ['page' => $page->id]), 'cattophp_marketing_landing_sections_one_hero');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_sections (reference, landing_page_id, kind, position) VALUES ('zzzzzzzz', :page, 'hero', 99)",
            ['page' => $page->id]), 'cattophp_marketing_landing_sections_hero_first');
        $this->refusedSql(fn() => $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_sections (reference, landing_page_id, kind, position) VALUES ('zzzzzzzz', :page, 'faq', 0)",
            ['page' => $page->id]), 'cattophp_marketing_landing_sections_hero_first');

        self::assertSame('half', $sections->width($page->reference, $saved->reference, 'half', $this->owner)->width);
        $hero = $this->section($page, 'hero');
        $this->refusedFields(fn() => $sections->width($page->reference, $hero->reference, 'half'), ['width'], 'The hero is always the full width of the page.');
        $this->refusedFields(fn() => $sections->width($page->reference, $saved->reference, 'third'), ['width']);
        self::assertSame('running', $sections->shownWhen($page->reference, $saved->reference, 'running', $this->owner)->shownWhen);
        self::assertFalse($sections->hide($page->reference, $saved->reference)->enabled);
        self::assertTrue($sections->show($page->reference, $saved->reference)->enabled);
        $sections->show($page->reference, $audience->reference);
        self::assertFalse($this->section($page, 'audience')->unsaved, 'Shown by hand, it is no longer waiting for its first save.');

        $sections->remove($page->reference, $this->section($page, 'faq')->reference, $this->owner);
        $positions = array_map(static fn(LandingSection $s): int => $s->position, $sections->list($page));
        self::assertSame(range(0, count($positions) - 1), $positions, 'Removing a section closes the gap: 1 to n after the hero.');
        $sections->remove($page->reference, $hero->reference);
        self::assertSame(range(1, count($positions) - 1), array_map(static fn(LandingSection $s): int => $s->position, $sections->list($page)), 'A page may go without a hero.');
        self::assertSame(0, $sections->add($page->reference, 'hero')->position, 'A hero added goes first.');

        foreach (['section_added', 'section_saved', 'section_width', 'section_shown_when', 'section_hidden', 'section_shown', 'section_removed'] as $action) {
            self::assertNotSame([], $this->marketing->site->activity->recent(10, 'marketing.landing_page.' . $action), $action);
        }
        $edited = $this->marketing->site->activity->recent(1, 'marketing.landing_page.section_saved')[0];
        self::assertSame(['faq', ['heading', 'items']], [$edited->details['kind'] ?? null, $edited->details['changed'] ?? null]);
        self::assertStringNotContainsString('Is there a sale', (string) json_encode($edited->details), 'Copy is never written to the activity log.');

        $offer = $this->marketing->pages->createForOffer('product', 'tea-100')->page;
        $this->refusedFields(fn() => $sections->shownWhen($offer->reference, $this->section($offer, 'faq')->reference, 'running'), ['shown_when']);
        $countdown = $sections->add($offer->reference, 'countdown');
        $this->refusedFields(fn() => $sections->width($offer->reference, $countdown->reference, 'half'), ['width'], 'Countdown is always the full width of the page.');
        $this->refused(fn() => $sections->save($page->reference, $countdown->reference, []), 'That section is not on this page.');
    }

    public function testMovesAreCheckedAgainstTheStoredOrderAndSavedAsOneCompactOrder(): void
    {
        $page = $this->marketing->pages->createForCampaign('spring-sale')->page;
        $sections = $this->marketing->sections;
        $sections->add($page->reference, 'words');
        $order = $this->order($page);
        [$offers, $faq, $cta, $words] = $order;
        $sections->arrange($page->reference, $words, 0, $order, $this->owner);
        self::assertSame([$words, $offers, $faq, $cta], $this->order($page));
        $this->refused(fn() => $sections->arrange($page->reference, $offers, 3, $order), 'The sections were changed elsewhere since this page was loaded. The saved order is shown.');
        $this->refused(fn() => $sections->arrange($page->reference, $offers, 99), 'That is not a place on the page.');
        $hero = $this->section($page, 'hero')->reference;
        $this->refused(fn() => $sections->step($page->reference, $hero, 'down'), 'The hero is always at the top of the page.');
        $sections->step($page->reference, $words, 'bottom');
        self::assertSame($words, $this->order($page)[3]);
        $sections->step($page->reference, $words, 'up');
        self::assertSame($words, $this->order($page)[2]);
        $count = count($this->marketing->site->activity->recent(50, 'marketing.landing_page.section_moved'));
        $sections->step($page->reference, $this->order($page)[0], 'top');
        self::assertCount($count, $this->marketing->site->activity->recent(50, 'marketing.landing_page.section_moved'), 'A move to where it is changes nothing.');
        $this->refused(fn() => $sections->step($page->reference, $words, 'sideways'), 'Choose a valid move.');
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn(LandingSection $s): int => $s->position, $sections->list($page)));
        $moved = $this->marketing->site->activity->recent(1, 'marketing.landing_page.section_moved')[0];
        self::assertSame([4, 3], [$moved->details['from_position'] ?? null, $moved->details['to_position'] ?? null]);
    }

    public function testStoredDataIsCheckedAgainOnEveryReadAndAnUnknownKindIsKeptAndSkipped(): void
    {
        $page = $this->marketing->pages->createForCampaign('spring-sale')->page;
        $faq = $this->section($page, 'faq');
        $this->connection->executeStatement('UPDATE cattophp_marketing_landing_sections SET data = CAST(:data AS jsonb) WHERE id = :id',
            ['data' => '{"items": [{"question": "Q", "answer": "A"}], "script": "<script>"}', 'id' => $faq->id]);
        $read = $this->section($page, 'faq');
        self::assertSame(['heading' => '', 'text' => '', 'items' => [['question' => 'Q', 'answer' => 'A']]], $read->data, 'Fields added since get their defaults; what is outside the kind is dropped.');
        $hero = $this->section($page, 'hero');
        $this->connection->executeStatement('UPDATE cattophp_marketing_landing_sections SET data = jsonb_set(data, \'{headline}\', to_jsonb(repeat(\'x\', 130))) WHERE id = :id', ['id' => $hero->id]);
        $broken = $this->section($page, 'hero');
        self::assertSame(['The headline is longer than 120 characters.', false], [$broken->invalid, $broken->isValid()]);
        self::assertContains('Hero: The headline is longer than 120 characters.', $this->marketing->pages->problems($page), 'Named in the publish checks.');
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_landing_sections (reference, landing_page_id, kind, position, data) VALUES ('contents', :page, 'contents', 9, '{\"show_items\": true}')",
            ['page' => $page->id]);
        $unknown = $this->section($page, 'contents');
        self::assertSame([false, null, ['show_items' => true]], [$unknown->isKnown(), $unknown->kind, $unknown->data], 'Kept as stored, without a kind.');
        self::assertNotContains('contents', array_map(static fn(string $p): string => substr($p, 0, 8), $this->marketing->pages->problems($page)), 'An unknown kind is skipped by the checks.');
        $this->refused(fn() => $this->marketing->sections->save($page->reference, 'contents', []), 'This kind of section is no longer on the site: remove it.');
        $this->marketing->sections->remove($page->reference, 'contents');
        self::assertNull($this->marketing->sections->get($page, 'contents'), 'The editor can remove it.');
    }

    public function testVersionsAreWrittenAfterEveryChangeOfAPublishedPageAndNeverChanged(): void
    {
        $pages = $this->marketing->pages;
        $page = $pages->createForCampaign('spring-sale')->page;
        $faq = $this->section($page, 'faq');
        $this->marketing->sections->save($page->reference, $faq->reference, ['items' => [['question' => 'When?', 'answer' => 'Now.']]]);
        self::assertSame(0, count($this->marketing->versions->list($page)), 'A draft\'s changes write nothing.');
        $this->clock()->modify('+1 hour');
        $pages->publish($page->reference, $this->owner);
        $this->clock()->modify('+1 day');
        $hero = $this->section($page, 'hero');
        $this->marketing->sections->save($page->reference, $hero->reference, ['headline' => 'Spring tea, half price', 'picture' => ['source' => 'offer', 'ref' => '']], $this->owner);
        $this->clock()->modify('+1 day');
        $this->marketing->sections->hide($page->reference, $faq->reference);
        $versions = $this->marketing->versions->list($page);
        self::assertSame([[3, 'The Questions and answers section was hidden', 'Published now'], [2, 'The Hero section changed', 'Replaced 11 Nov'], [1, 'First published', 'Replaced 10 Nov']],
            array_map(static fn(LandingVersion $v): array => [$v->number, $v->whatChanged, $v->state()], $versions));
        self::assertSame('1', $versions[2]->madeBy);
        self::assertSame(['hero', 'offers', 'faq', 'cta'], array_column($versions[2]->sections(), 'kind'));
        self::assertSame('', $versions[2]->sections()[0]['data']['headline'], 'The first version keeps the hero as it was.');
        self::assertFalse($versions[0]->sections()[2]['enabled']);

        $this->refusedSql(fn() => $this->connection->executeStatement("UPDATE cattophp_marketing_landing_versions SET what_changed = 'Rewritten'"), 'A kept version of a landing page is never changed.');
        $this->refusedSql(fn() => $this->connection->executeStatement('DELETE FROM cattophp_marketing_landing_versions'), 'A kept version of a landing page goes only with its page.');

        $references = array_map(static fn(LandingSection $s): string => $s->reference, $this->marketing->sections->list($page));
        $this->marketing->sections->useVersion($page->reference, 1, $this->owner);
        $sections = $this->marketing->sections->list($page);
        self::assertSame(['', true], [$sections[0]->data['headline'], $sections[2]->enabled], 'The first version\'s content is back.');
        self::assertSame($references, array_map(static fn(LandingSection $s): string => $s->reference, $sections), 'Each section with its reference again.');
        self::assertSame([4, 'Version 1 used again', 'Published now'], [$this->marketing->versions->list($page)[0]->number, $this->marketing->versions->list($page)[0]->whatChanged,
            $this->marketing->versions->list($page)[0]->state()]);
        $this->refused(fn() => $this->marketing->sections->useVersion($page->reference, 99), 'There is no such version to use again.');

        $pages->unpublish($page->reference);
        self::assertSame(['unpublished', 'Taken down', 'Taken down'], [$this->marketing->versions->list($page)[0]->kind, $this->marketing->versions->list($page)[0]->whatChanged,
            $this->marketing->versions->list($page)[0]->state()]);
        self::assertStringStartsWith('Taken down ', $this->marketing->versions->list($page)[1]->state());
        self::assertNull($this->marketing->versions->current($page));
        $this->refused(fn() => $this->marketing->sections->useVersion($page->reference, 5), 'There is no such version to use again.');
    }

    public function testTemplatesAreSavedWithoutOffersAndAPageStartsAgainFromOne(): void
    {
        $pages = $this->marketing->pages;
        $page = $pages->createForCampaign('spring-sale')->page;
        $this->marketing->sections->save($page->reference, $this->section($page, 'faq')->reference, ['heading' => 'Asked often', 'items' => [['question' => 'When?', 'answer' => 'Now.']]]);
        $this->marketing->sections->width($page->reference, $this->section($page, 'faq')->reference, 'half');
        $template = $pages->saveAsTemplate($page->reference, 'Our spring page', 'Hero, offers and our questions.', $this->owner);
        self::assertSame(['saved:' . $template->reference, false, '1'], [$template->key, $template->builtIn, $template->madeBy]);
        self::assertSame([[], 'Asked often', 'half'], [$template->sections[1]['data']['offers'] ?? null, $template->sections[2]['data']['heading'] ?? null, $template->sections[2]['width'] ?? null],
            'Copy, widths and settings kept; the offers chosen left out.');
        $this->refusedFields(fn() => $pages->saveAsTemplate($page->reference, 'OUR SPRING PAGE', ''), ['name']);
        $this->refusedFields(fn() => $pages->saveAsTemplate($page->reference, '', str_repeat('n', 201)), ['name', 'note']);

        $this->marketing->campaigns->create(new CampaignInput('Winter sale', 'winter-sale', offers: ['product:mug-01']));
        $context = $pages->contextFor($this->marketing->campaigns->require('winter-sale'), null);
        self::assertSame(['several-offers', 'special-with-end-date', 'saved:' . $template->reference], array_map(static fn($t): string => $t->key, $this->marketing->templates->available($context, true)));
        self::assertSame(['several-offers', 'special-with-end-date'], array_map(static fn($t): string => $t->key, $this->marketing->templates->available($context, false)), 'Saved templates only for those who manage campaigns.');
        $winter = $pages->createForCampaign('winter-sale', $template->key)->page;
        $faq = $this->section($winter, 'faq');
        self::assertSame([['product:mug-01'], 'Asked often', 'half'], [$this->section($winter, 'offers')->data['offers'], $faq->data['heading'], $faq->width], 'The offers section takes this campaign\'s offers.');

        $pages->publish($page->reference);
        $again = $this->marketing->sections->startAgain($page->reference, 'special-with-end-date', false, $this->owner);
        self::assertSame(['hero', 'countdown', 'offers', 'faq', 'cta'], array_map(static fn(LandingSection $s): string => $s->kindKey, $this->marketing->sections->list($again->page)));
        self::assertSame('Started again from the template A special with an end date', $this->marketing->versions->list($page)[0]->whatChanged, 'On a published page the old content stays in the versions.');
        $this->refusedFields(fn() => $this->marketing->sections->startAgain($page->reference, $template->key, false), ['template']);
        $this->refusedFields(fn() => $this->marketing->sections->startAgain($page->reference, 'no-such', true), ['template']);
        $offerPage = $pages->createForOffer('product', 'tea-100')->page;
        $this->refusedFields(fn() => $this->marketing->sections->startAgain($offerPage->reference, 'special-with-end-date', true), ['template']);

        $this->marketing->templates->delete((string) $template->reference, $this->owner);
        self::assertNull($this->marketing->templates->get($template->key));
        self::assertSame(['marketing.landing_template.deleted', 'marketing.landing_template.saved'], array_map(static fn($e): string => $e->action,
            array_values(array_filter($this->marketing->site->activity->recent(50), static fn($e): bool => str_starts_with($e->action, 'marketing.landing_template.')))));
    }

    public function testAnOfferRenamedOrRemovedIsFollowedEverywhere(): void
    {
        $pages = $this->marketing->pages;
        $campaignPage = $pages->createForCampaign('spring-sale')->page;
        $offerPage = $pages->createForOffer('product', 'tea-100')->page;
        $this->marketing->events->record('landing_opened', new \DateTimeImmutable(), landingPageId: $offerPage->id, offerType: 'product', offerReference: 'tea-100');
        $this->shop->rename('product:tea-100', 'tea-200');
        $changed = $pages->offerRenamed('product', 'tea-100', 'tea-200');
        self::assertSame(3, $changed, 'Its own page, that page\'s offers section and the campaign page\'s.');
        self::assertSame('tea-200', $pages->get($offerPage->reference)?->offerReference);
        self::assertSame(['product:tea-200', 'bundle:gift-box'], $this->section($campaignPage, 'offers')->data['offers']);
        self::assertSame(['product:tea-200', 'bundle:gift-box'], array_map(static fn($o): string => $o->key(), $this->marketing->campaigns->offers($this->marketing->campaigns->require('spring-sale')->id)));
        self::assertSame('tea-200', $this->marketing->events->forPage($offerPage->id)[0]->offerReference);
        self::assertSame(0, $pages->offerRenamed('product', 'tea-200', 'tea-200'));

        $this->marketing->sections->hide($offerPage->reference, $this->section($offerPage, 'faq')->reference);
        $pages->publish($offerPage->reference);
        $removed = $pages->offerRemoved('product', 'tea-200');
        self::assertSame(2, $removed);
        self::assertNull($pages->get($offerPage->reference), 'Its own page goes, even one that was public, as a deleted course\'s page does.');
        self::assertSame(['bundle:gift-box'], $this->section($campaignPage, 'offers')->data['offers']);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_events'), 'Its events stay.');
    }

    public function testACampaignsCopyTakesItsPageAsADraftAndAPageThatWasPublicKeepsTheCampaign(): void
    {
        $pages = $this->marketing->pages;
        $page = $pages->createForCampaign('spring-sale')->page;
        $this->marketing->sections->save($page->reference, $this->section($page, 'faq')->reference, ['items' => [['question' => 'When?', 'answer' => 'Now.']]]);
        $pages->publish($page->reference);
        $copy = $this->marketing->campaigns->copy('spring-sale', $this->owner);
        $copied = $pages->forCampaign($copy) ?? throw new \LogicException('The copy has no page.');
        self::assertSame(['draft', null, '/c/' . $copy->code], [$copied->status, $copied->firstPublishedAt, $pages->address($copied)]);
        self::assertSame(['hero', 'offers', 'faq', 'cta'], array_map(static fn(LandingSection $s): string => $s->kindKey, $this->marketing->sections->list($copied)));
        self::assertSame('When?', $this->section($copied, 'faq')->data['items'][0]['question']);
        self::assertSame([], $this->marketing->versions->list($copied), 'Without the versions.');
        self::assertNotSame($this->section($page, 'faq')->reference, $this->section($copied, 'faq')->reference);

        self::assertTrue($this->marketing->campaigns->canDelete($copy));
        $this->marketing->campaigns->delete($copy->code);
        self::assertNull($pages->get($copied->reference), 'A page never public goes with its campaign.');
        self::assertFalse($this->marketing->campaigns->canDelete($this->marketing->campaigns->require('spring-sale')));
        $this->refused(fn() => $this->marketing->campaigns->delete('spring-sale'), 'This campaign has been published, its links have been opened or its landing page has been public, so it is kept: archive it instead.');
    }

    public function testAPictureIsKeptWhileAnythingNamesItAndRemovedWhenNothingDoes(): void
    {
        $pages = $this->marketing->pages;
        $page = $pages->createForCampaign('spring-sale')->page;
        $context = $pages->context($page);
        $upload = $this->marketing->pictureSources->get('upload') ?? throw new \LogicException('No upload source.');
        $name = $upload->upload(SamplePictures::file($this->marketing->folder, SamplePictures::PNG), 'spring.png', 'Spring', $context);
        self::assertTrue($upload->exists($name, $context));
        $hero = $this->section($page, 'hero');
        $this->refusedFields(fn() => $this->marketing->sections->save($page->reference, $hero->reference, ['picture' => ['source' => 'upload', 'ref' => str_repeat('b', 64) . '.png']]), ['picture']);
        $this->marketing->sections->save($page->reference, $hero->reference, ['picture' => ['source' => 'upload', 'ref' => $name]]);
        self::assertSame(['source' => 'upload', 'ref' => $name], $this->section($page, 'hero')->data['picture']);
        self::assertTrue($this->marketing->pictures->inUse($name));
        $this->marketing->sections->save($page->reference, $hero->reference, ['picture' => ['source' => 'offer']]);
        self::assertFalse($this->marketing->pictures->inUse($name));
        self::assertNull($this->marketing->pictures->read($name), 'No longer named by anything, it is removed.');

        $name = $upload->upload(SamplePictures::file($this->marketing->folder, SamplePictures::PNG), 'spring.png', 'Spring', $context);
        $this->marketing->sections->save($page->reference, $hero->reference, ['picture' => ['source' => 'upload', 'ref' => $name]]);
        $this->marketing->sections->save($page->reference, $this->section($page, 'faq')->reference, ['items' => [['question' => 'When?', 'answer' => 'Now.']]]);
        $pages->publish($page->reference);
        $this->marketing->sections->save($page->reference, $hero->reference, ['picture' => ['source' => 'offer']]);
        self::assertNotNull($this->marketing->pictures->read($name), 'A kept version still names it.');
        $saved = $pages->saveSettings($page->reference, new LandingSettingsInput(sharePicture: ['source' => 'upload', 'ref' => $name]));
        self::assertSame(['source' => 'upload', 'ref' => $name], $saved->sharePicture);
        $this->refusedFields(fn() => $pages->saveSettings($page->reference, new LandingSettingsInput(sharePicture: ['source' => 'upload', 'ref' => str_repeat('c', 64) . '.png'])), ['share_picture']);
        $offerPicture = $this->marketing->pictureSources->get('offer') ?? throw new \LogicException('No offer source.');
        self::assertSame([['ref' => 'product:tea-100', 'title' => 'Rooibos tea, 100 bags'], ['ref' => 'bundle:gift-box', 'title' => 'Gift box']], $offerPicture->choices($context));
        self::assertNull($offerPicture->resolve('', $context), 'GenericLanding\'s cards have no picture of their own.');
        self::assertTrue($offerPicture->exists('', $context));
        self::assertFalse($offerPicture->exists('product:mug-01', $context), 'Only an offer of the page.');
        self::assertSame([['source' => 'upload', 'ref' => $name], ['source' => 'offer', 'ref' => '']], $pages->pictures($pages->require($page->reference)),
            'The share picture and the shown sections\' pictures, which alone the page\'s picture address serves.');
        self::assertTrue($pages->namesPicture($pages->require($page->reference), 'upload', $name));
        $this->marketing->sections->hide($page->reference, $hero->reference);
        $pages->saveSettings($page->reference, new LandingSettingsInput());
        self::assertFalse($pages->namesPicture($pages->require($page->reference), 'offer', ''), 'A hidden section\'s picture is not served.');
        $file = $upload->resolve($name, $context);
        self::assertSame(['file', 'image/png', 'picture.png'], [$file?->kind, $file?->mimeType, $file?->fileName]);
    }

    public function testTheListShowsEachPageWithWhatItIsForItsSectionsAndItsRecentVisits(): void
    {
        $pages = $this->marketing->pages;
        $campaignPage = $pages->createForCampaign('spring-sale')->page;
        $this->marketing->sections->save($campaignPage->reference, $this->section($campaignPage, 'hero')->reference, ['headline' => 'Tea for spring']);
        $offerPage = $pages->createForOffer('product', 'tea-100')->page;
        $now = $this->marketing->campaigns->now();
        $this->marketing->events->record('landing_opened', $now, landingPageId: $offerPage->id);
        $this->marketing->events->record('landing_opened', $now->modify('-40 days'), landingPageId: $offerPage->id);
        $all = $pages->list();
        self::assertSame([[$offerPage->reference, 'Rooibos tea, 100 bags', 'Rooibos tea, 100 bags', null, '/landing/rooibos-tea-100-bags', 4, 1],
            [$campaignPage->reference, 'Tea for spring', 'Spring sale', 'spring-sale', '/c/spring-sale', 4, 0]],
            array_map(static fn($s): array => [$s->page->reference, $s->title, $s->about, $s->campaignCode, $s->address, $s->sections, $s->visits], $all), 'Visits over the recent setting, 30 days to start.');
        self::assertSame([$campaignPage->reference], array_map(static fn($s): string => $s->page->reference, $pages->list('campaign')));
        self::assertSame([$offerPage->reference], array_map(static fn($s): string => $s->page->reference, $pages->list('offer')));
        self::assertSame([$campaignPage->reference], array_map(static fn($s): string => $s->page->reference, $pages->list('all', 'for spring')));
        self::assertSame([], $pages->list('published'));
        self::assertSame(['all' => 2, 'campaign' => 1, 'offer' => 1, 'draft' => 2, 'published' => 0], $pages->filterCounts());
        self::assertSame(2, $pages->count('draft'));
        self::assertSame([$campaignPage->reference, $offerPage->reference], array_map(static fn($s): string => $s->page->reference, $pages->list(sort: 'title', direction: 'desc')));
    }

    public function testTheSchemaStepRunsOnADatabaseWithStepOnesRecords(): void
    {
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
        $steps = array_values(array_filter(FoundationSteps::all(), static fn($step): bool => !$step instanceof Step202611140010MarketingLanding && !$step instanceof Step202611141200MarketingSales
            && !$step instanceof Step202611141500MarketingForgottenCustomers));
        (new SchemaRunner($this->connection, $steps, new MockClock('2026-11-13 21:00:00')))->migrate();
        $this->connection->executeStatement("INSERT INTO cattophp_marketing_campaigns (code, name, status) VALUES ('autumn-sale', 'Autumn sale', 'active')");
        $applied = $this->runner()->migrate();
        self::assertSame(['202611140010-marketing-landing', '202611141200-marketing-sales', '202611141500-marketing-forgotten-customers'], $applied);
        $marketing = new MarketingHarness($this->connection, [], $this->shop);
        try {
            $page = $marketing->pages->createForCampaign('autumn-sale')->page;
            self::assertSame('/c/autumn-sale', $marketing->pages->address($page), 'A campaign from before the step takes a page.');
        } finally {
            $marketing->remove();
        }
        self::assertSame([], $this->runner()->migrate(), 'Applied once.');
    }

    private function section(LandingPage $page, string $kind, int $which = 0): LandingSection
    {
        $found = array_values(array_filter($this->marketing->sections->list($page), static fn(LandingSection $s): bool => $s->kindKey === $kind || $s->reference === $kind));

        return $found[$which] ?? throw new \LogicException('The page has no ' . $kind . ' section.');
    }

    /** @return list<string> the references of the sections after the hero, in order */
    private function order(LandingPage $page): array
    {
        return array_values(array_map(static fn(LandingSection $s): string => $s->reference, array_filter($this->marketing->sections->list($page), static fn(LandingSection $s): bool => !$s->isHero())));
    }

    private function clock(): MockClock
    {
        return $this->marketing->site->clock;
    }

    private function refused(callable $call, string $message, ?string $field = null): void
    {
        try {
            $call();
            self::fail('Expected the refusal: ' . $message);
        } catch (MarketingRefused $refused) {
            self::assertSame($message, $refused->error($field ?? '') ?? implode(' ', $refused->errors()));
        }
    }

    /** @param list<string> $fields */
    private function refusedFields(callable $call, array $fields, ?string $message = null): void
    {
        try {
            $call();
            self::fail('Expected a refusal of ' . implode(', ', $fields));
        } catch (MarketingRefused $refused) {
            self::assertSame($fields, array_keys($refused->errors()));
            if ($message !== null) {
                self::assertSame($message, $refused->error($fields[0]));
            }
        }
    }

    private function refusedSql(callable $call, string $constraint): void
    {
        try {
            $call();
            self::fail('The database took what ' . $constraint . ' refuses.');
        } catch (DbalException $refused) {
            self::assertStringContainsString($constraint, $refused->getMessage());
        }
    }
}
