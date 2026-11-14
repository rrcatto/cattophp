<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Landing\CardOptions;
use CattoPHP\Marketing\Landing\GenericLanding;
use CattoPHP\Marketing\Landing\LandingAccess;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPresses;
use CattoPHP\Marketing\Landing\LandingProject;
use CattoPHP\Marketing\Landing\LandingSection;
use CattoPHP\Marketing\Landing\LandingSettingsInput;
use CattoPHP\Marketing\Landing\LandingView;
use CattoPHP\Marketing\Landing\LandingViewBuilder;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Landing\OfferAction;
use CattoPHP\Marketing\Landing\OfferCard;
use CattoPHP\Marketing\Landing\ProjectPart;
use CattoPHP\Marketing\NoProject;
use CattoPHP\Marketing\Offer;
use CattoPHP\Tests\Support\ChangingShop;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use CattoPHP\Tests\Support\SamplePictures;
use CattoPHP\Tests\Support\UiHarness;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Loader\FilesystemLoader;

/**
 * Landing pages drawn by LandingViewBuilder against the database, the parts the kernel's own project cannot show: an
 * offer's page public only while its offer is; previews, kept versions and one section alone, which record nothing and
 * claim no address; a project's own buttons (a form carrying the page's reference and address, a free preview the hero
 * leads to) and its purchase box; a POST press accepted, and a forged or unpublished one refused; the video framed only
 * when the policy lets it be; the head; and a project with NoProject drawing a campaign's page.
 */
final class MarketingLandingPublicTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';

    private ChangingShop $shop;
    private MarketingHarness $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = new ChangingShop();
        $this->marketing = new MarketingHarness($this->connection, ['APP_TIMEZONE' => 'Africa/Johannesburg'], $this->shop);
        $this->marketing->campaigns->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop', promotion: 'SPRING10', offers: ['product:tea-100', 'product:mug-01']));
        $this->marketing->campaigns->activate('spring-sale');
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testAnOffersPageIsPublicOnlyWhileItAndItsOfferAre(): void
    {
        $builder = $this->builder();
        $page = $this->marketing->pages->createForOffer('product', 'tea-100')->page;
        $this->answerTheQuestions($page);
        self::assertNull($builder->forVisitor($page), 'A draft.');
        $page = $this->marketing->pages->publish($page->reference);
        $view = $builder->forVisitor($page);
        self::assertInstanceOf(LandingView::class, $view);
        self::assertSame(['/landing/rooibos-tea-100-bags', 'Rooibos tea, 100 bags'], [$view->path, $view->hero['headline'] ?? null]);
        $this->shop->hide('product:tea-100');
        self::assertNull($builder->forVisitor($page), 'Its offer is no longer public.');
        $this->shop->show('product:tea-100');
        self::assertNotNull($builder->forVisitor($page));
    }

    public function testAPreviewRecordsNothingClaimsNoAddressAndItsButtonsDoNothing(): void
    {
        $builder = $this->builder();
        $page = $this->campaignPage(false);
        $preview = $builder->preview($page, '/marketing/landing-pages/' . $page->reference, '1');
        self::assertTrue($preview->isPreview());
        self::assertSame(['title' => 'Spring sale', 'description' => '', 'robots' => 'noindex, nofollow'], $preview->meta, 'No canonical address and no share card.');
        self::assertSame('This is the saved page as visitors will see it once it is published. Its buttons do nothing here and this view is not counted.', $preview->notice);
        $this->marketing->journey->opened($preview, $this->request());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Events::TABLE), 'A preview records nothing.');
        $html = new Crawler($builder->drawSection($preview));
        self::assertSame('Preview of the landing page', trim($html->filter('.cl-ui-notice strong')->first()->text()));
        self::assertSame('/marketing/landing-pages/' . $page->reference, $html->filter('a:contains("Back to the landing page editor")')->attr('href'));
        self::assertSame(0, $html->filter('a[href*="/go/"]')->count(), 'No button leads anywhere.');
        self::assertSame(0, $html->filter('.cl-landing-sections form')->count());
        self::assertSame(['In a preview the buttons do nothing'], array_values(array_unique($html->filter('.cl-landing-offer-actions button[disabled]')->each(static fn(Crawler $b): string => (string) $b->attr('title')))));

        $page = $this->marketing->pages->publish($page->reference);
        self::assertSame('This is the saved page as visitors will see it. Its buttons do nothing here and this view is not counted.', $builder->preview($page)->notice);
    }

    public function testAKeptVersionIsDrawnAsWhatVisitorsSawAndOneSectionAloneAsAPreview(): void
    {
        $builder = $this->builder();
        $page = $this->campaignPage();
        $hero = $this->section($page, 'hero');
        $this->marketing->site->clock->modify('+1 day');
        $this->marketing->sections->save($page->reference, $hero->reference, ['headline' => 'Ten percent off all spring']);
        $versions = $this->marketing->versions->list($page);
        self::assertCount(2, $versions);
        $first = $builder->version($page, $versions[1], '/back');
        self::assertInstanceOf(LandingView::class, $first);
        self::assertSame(['Spring sale', true, ['title' => 'Spring sale', 'description' => '', 'robots' => 'noindex, nofollow']], [$first->hero['headline'] ?? null, $first->isPreview(), $first->meta]);
        self::assertSame('What visitors saw from 9 Nov 2026, 12:00 to 10 Nov 2026, 12:00. Prices and the products and bundles are shown as they are today, because the page reads them live.', $first->notice);
        $now = $builder->version($page, $versions[0]);
        self::assertSame(['Ten percent off all spring', 'What visitors see now, since 10 Nov 2026, 12:00. Prices and the products and bundles are shown as they are today, because the page reads them live.'],
            [$now?->hero['headline'] ?? null, $now?->notice]);

        $words = $this->marketing->sections->add($page->reference, 'words');
        $this->marketing->sections->save($page->reference, $words->reference, ['heading' => 'How we choose our tea', 'text' => 'From one farm.']);
        $this->marketing->sections->hide($page->reference, $words->reference);
        self::assertNull($builder->forVisitor($page)?->sections[3] ?? null, 'Hidden: not on the page.');
        $alone = $builder->sectionPreview($page, $words->reference);
        self::assertInstanceOf(LandingView::class, $alone);
        self::assertSame([['words', 'How we choose our tea']], array_map(static fn(array $s): array => [$s['kind'], $s['heading']], $alone->sections), 'Previewed alone, hidden or not.');
        $drawn = new Crawler($builder->drawSection($alone));
        self::assertStringContainsString('How we choose our tea', $drawn->html());
        self::assertSame([0, 0, 0], [$drawn->filter('h1')->count(), $drawn->filter('.cl-landing-hero')->count(), $drawn->filter('.cl-ui-notice, .cl-ui-feedback-notice')->count()],
            'Drawn inside the editor\'s own page: no h1, no head of its own and no preview notice.');
        $hero = $builder->sectionPreview($page, (string) ($this->marketing->pages->sections($page)[0]->reference ?? ''));
        self::assertInstanceOf(LandingView::class, $hero);
        $head = new Crawler($builder->drawSection($hero));
        self::assertSame([1, 0, 'Ten percent off all spring'], [$head->filter('.cl-landing-hero')->count(), $head->filter('h1')->count(), trim($head->filter('.cl-landing-headline')->text())],
            'The hero previewed alone is the head, its headline no h1.');
        self::assertNull($builder->sectionPreview($page, 'zzzz9999'), 'Not the page\'s.');
    }

    public function testAProjectsOwnButtonsCarryThePageAndItsPurchaseBoxIsDrawn(): void
    {
        $builder = $this->builder($this->shopLanding());
        $page = $this->marketing->pages->createForOffer('product', 'tea-100')->page;
        $this->answerTheQuestions($page);
        $this->marketing->sections->save($page->reference, $this->section($page, 'hero')->reference, ['secondary' => 'sample', 'secondary_label' => '']);
        $page = $this->marketing->pages->publish($page->reference);
        $view = $builder->forVisitor($page, '1');
        self::assertNotNull($view);
        self::assertSame(['Bestseller'], $view->hero['badges'] ?? null, 'The hero\'s badges from the offer\'s card.');
        self::assertSame(['average' => 4.5, 'count' => 12], $view->hero['rating'] ?? null);
        $hero = $this->section($page, 'hero');
        self::assertSame(['Try a sample', '/landing/rooibos-tea-100-bags/go/' . $hero->reference . '-1', 'action-preview'],
            [$view->hero['secondary']['label'] ?? null, $view->hero['secondary']['href'] ?? null, $view->hero['secondary']['icon'] ?? null], 'A place the offer\'s card gives, through the press address.');
        self::assertSame(['href' => '/shop/tea-100/sample', 'offer' => 'product:tea-100', 'action' => 'sample', 'section' => $hero->reference], $builder->press($view, $hero->reference . '-1'));

        $html = new Crawler($builder->drawSection($view));
        $form = $html->filter('#offers form.cl-landing-action');
        self::assertSame(['/basket/add', 'post'], [$form->attr('action'), $form->attr('method')]);
        self::assertSame(['offer' => 'product:tea-100', 'cattophp_landing' => $page->reference, 'cattophp_return' => '/landing/rooibos-tea-100-bags'],
            array_combine($form->filter('input')->each(static fn(Crawler $i): string => (string) $i->attr('name')), $form->filter('input')->each(static fn(Crawler $i): string => (string) $i->attr('value'))));
        self::assertSame('Bought in a box of the shop\'s own', trim($html->filter('#cta .shop-purchase')->text()), 'The call to action draws the project\'s purchase box.');
        self::assertSame('1:' . $page->reference, trim((string) $html->filter('#cta .shop-purchase')->attr('data-landing')), 'With the page\'s fields for its forms.');
        self::assertSame(1, $html->filter('.cl-landing-hero .cl-rating-summary')->count());
        $actions = $html->filter('a.cl-ui-action, button.cl-ui-action');
        self::assertGreaterThan(2, $actions->count());
        self::assertSame(array_fill(0, $actions->count(), 1), $actions->each(static fn(Crawler $a): int => $a->filter('svg.cl-ui-icon')->count()), 'Every button has its icon.');
        self::assertSame(['action-view', 'action-buy'], $html->filter('#offers .cl-landing-offer-actions use')->each(static fn(Crawler $u): string => substr((string) $u->attr('href'), (int) strpos((string) $u->attr('href'), '#') + 1)),
            'The project\'s icon, else the kind\'s.');
    }

    public function testAHeroLeftWithoutWordsShowsTheOffersOwnWordsForItWholeElseItsLine(): void
    {
        $about = "Rooibos from one farm in the Cederberg, picked by hand in the summer and dried in the sun.\n\nEach bag holds enough for a large pot.";
        $page = $this->marketing->pages->createForOffer('product', 'tea-100')->page;
        $this->answerTheQuestions($page);
        $page = $this->marketing->pages->publish($page->reference);
        self::assertSame([['Rooibos from one farm in the Cederberg, picked by hand in the summer and dried in the sun.'], ['Each bag holds enough for a large pot.']],
            $this->builder($this->shopLanding($about))->forVisitor($page)?->hero['paragraphs'] ?? null, 'The offer\'s own words for the hero, whole, a paragraph each.');
        self::assertSame([['Picked by hand.']], $this->builder($this->shopLanding())->forVisitor($page)?->hero['paragraphs'] ?? null, 'Without them, its line.');
        $this->marketing->sections->save($page->reference, $this->section($page, 'hero')->reference, ['text' => 'Written for this page.']);
        self::assertSame([['Written for this page.']], $this->builder($this->shopLanding($about))->forVisitor($page)?->hero['paragraphs'] ?? null, 'Words written in the hero win.');
    }

    public function testAPostPressIsRecordedOnlyFromAPublishedPageThatHoldsTheOffer(): void
    {
        $page = $this->campaignPage();
        $presses = $this->marketing->presses;
        $tea = $this->shop->offer('product', 'tea-100');
        $box = $this->shop->offer('bundle', 'gift-box');
        self::assertNotNull($tea);
        self::assertNotNull($box);
        self::assertTrue($presses->accepted($this->press($page->reference), $tea, 'buy'));
        self::assertFalse($presses->accepted($this->press('zzzz9999'), $tea, 'buy'), 'A forged page.');
        self::assertFalse($presses->accepted($this->press('<script>'), $tea, 'buy'));
        self::assertFalse($presses->accepted($this->press($page->reference), $box, 'buy'), 'An offer the page does not hold.');
        self::assertSame('/c/spring-sale', $presses->returnTo($this->press($page->reference)));
        self::assertNull($presses->returnTo($this->press($page->reference, 'https://elsewhere.example.org/')), 'Never back to another site.');
        $this->marketing->pages->unpublish($page->reference);
        self::assertFalse($presses->accepted($this->press($page->reference), $tea, 'buy'), 'An unpublished page.');
        $pressed = $this->connection->fetchAllAssociative('SELECT offer_reference, detail FROM ' . Events::TABLE . ' WHERE kind = :kind', ['kind' => Events::LANDING_PRESSED]);
        self::assertSame([['tea-100', 'buy']], array_map(static fn(array $e): array => [$e['offer_reference'], json_decode((string) $e['detail'], true)['action'] ?? null], $pressed));
    }

    public function testAVideoIsFramedOnlyWhenTheSitesPolicyLetsItsHostBeFramed(): void
    {
        $page = $this->campaignPage();
        $video = $this->marketing->sections->add($page->reference, 'video');
        $this->marketing->sections->save($page->reference, $video->reference, ['address' => 'https://www.youtube.com/watch?v=abcdefghijk', 'title' => 'Picking the leaves', 'length' => 'Two minutes']);
        $this->marketing->sections->show($page->reference, $video->reference);
        $page = $this->marketing->pages->require($page->reference);

        $plain = new Crawler($this->builder()->drawSection($this->builder()->forVisitor($page) ?? throw new \LogicException('Drawn.')));
        self::assertSame(0, $plain->filter('iframe')->count(), 'The starting policy frames nothing.');
        self::assertSame('https://www.youtube.com/watch?v=abcdefghijk', $plain->filter('#video a:contains("Watch the video")')->attr('href'));

        $framing = $this->builder(policy: new ContentSecurityPolicy(new RequestStack(), ['frame-src' => 'https://www.youtube-nocookie.com']));
        $framed = new Crawler($framing->drawSection($framing->forVisitor($page) ?? throw new \LogicException('Drawn.')));
        self::assertSame(['https://www.youtube-nocookie.com/embed/abcdefghijk', 'Picking the leaves'], [$framed->filter('#video iframe')->attr('src'), $framed->filter('#video iframe')->attr('title')]);
        self::assertSame('Picking the leaves · Two minutes', trim($framed->filter('.cl-landing-video-title')->text()));
        $other = $this->builder(policy: new ContentSecurityPolicy(new RequestStack(), ['frame-src' => 'https://player.vimeo.com']));
        self::assertSame(0, (new Crawler($other->drawSection($other->forVisitor($page) ?? throw new \LogicException('Drawn.'))))->filter('iframe')->count(), 'Another host allowed is not this one.');
    }

    public function testTheHeadAndTheAnchorsAndTheRowsOfHalves(): void
    {
        $page = $this->campaignPage();
        $sections = $this->marketing->sections;
        $this->marketing->sections->save($page->reference, $this->section($page, 'hero')->reference, ['headline' => 'Spring, ten percent off', 'text' => "Every tea in the shop.\n\nUntil the end of the month."]);
        $faq = $this->section($page, 'faq');
        $second = $sections->add($page->reference, 'faq');
        $sections->save($page->reference, $second->reference, ['heading' => 'About delivery', 'items' => [['question' => 'How long?', 'answer' => 'Three days.']]]);
        $sections->show($page->reference, $second->reference);
        $sections->width($page->reference, $faq->reference, 'half');
        $sections->width($page->reference, $second->reference, 'half');
        $sections->step($page->reference, $second->reference, 'up');
        $page = $this->marketing->pages->require($page->reference);
        $view = $this->builder()->forVisitor($page);
        self::assertNotNull($view);
        self::assertSame(['title' => 'Spring, ten percent off', 'description' => 'Every tea in the shop. Until the end of the month.', 'robots' => 'noindex',
            'canonical' => 'https://example.com/c/spring-sale', 'og' => ['type' => 'website', 'title' => 'Spring, ten percent off', 'description' => 'Every tea in the shop. Until the end of the month.',
            'url' => 'https://example.com/c/spring-sale'], 'twitter_card' => 'summary'], $view->meta);
        self::assertSame(['offers', 'faq', 'faq-' . $second->reference, 'cta'], array_map(static fn(array $s): string => $s['anchor'], $view->sections), 'A second of a kind adds its reference.');
        self::assertSame([1, 2, 1], array_map('count', $view->rows), 'The two halves share a row.');
        $html = new Crawler($this->builder()->drawSection($view));
        self::assertSame(2, $html->filter('.cl-landing-sections > .cl-ui-section-grid > section.cl-landing-section')->count());

        $this->marketing->pages->saveSettings($page->reference, new LandingSettingsInput(metaTitle: 'The spring tea sale', metaDescription: 'Ten percent off every tea.'));
        $view = $this->builder()->forVisitor($this->marketing->pages->require($page->reference));
        self::assertSame(['The spring tea sale', 'Ten percent off every tea.'], [$view?->meta['title'] ?? null, $view?->meta['description'] ?? null], 'The page\'s own words win.');
    }

    public function testEveryOneOfCattoPHPsKindsIsDrawnWithTodaysMarkup(): void
    {
        $this->marketing->campaigns->change('spring-sale', new CampaignInput('Spring sale', 'spring-sale', endsAt: '2026-11-30', destination: '/shop', promotion: 'SPRING10',
            offers: ['product:tea-100', 'product:mug-01']));
        $page = $this->campaignPage();
        $sections = $this->marketing->sections;
        $picture = $this->marketing->pictures->store(SamplePictures::file($this->marketing->folder, SamplePictures::PNG), 1_000_000);
        $copy = [
            'words' => ['heading' => 'Our story', 'text' => "Grown on one farm.\nPicked by hand."],
            'picture' => ['heading' => 'The farm', 'picture' => ['source' => 'upload', 'ref' => $picture], 'caption' => 'The farm in spring.'],
            'audience' => ['items' => [['title' => 'Tea lovers', 'text' => 'Who drink it every day.'], 'Anyone looking for a gift.']],
            'outcomes' => ['items' => ['Brew a better cup.']],
            'benefits' => ['items' => [['title' => 'Fresh', 'text' => 'Packed the week it is picked.']]],
            'countdown' => ['words' => 'The sale ends in'],
            'video' => ['address' => 'https://video.example.com/farm.mp4', 'title' => 'On the farm'],
        ];
        foreach ($copy as $kind => $input) {
            $section = $sections->add($page->reference, $kind);
            $sections->save($page->reference, $section->reference, $input);
            $sections->show($page->reference, $section->reference);
        }
        $view = $this->builder()->forVisitor($this->marketing->pages->require($page->reference));
        self::assertNotNull($view);
        $html = new Crawler($this->builder()->drawSection($view));
        self::assertSame(['offers', 'faq', 'cta', 'words', 'picture', 'audience', 'outcomes', 'benefits', 'countdown', 'video'],
            $html->filter('section.cl-landing-section')->each(static fn(Crawler $s): string => (string) $s->attr('id')));
        self::assertSame('Grown on one farm.<br>Picked by hand.', trim($html->filter('#words .cl-landing-intro')->html()), 'A line break kept, the copy escaped.');
        self::assertSame(['/c/spring-sale/picture/' . $picture, 'The farm in spring.'], [$html->filter('#picture figure img')->attr('src'), trim($html->filter('#picture figcaption')->text())]);
        self::assertSame(['Tea lovers Who drink it every day.', 'Anyone looking for a gift.'], $html->filter('#audience .cl-landing-checklist li')->each(static fn(Crawler $l): string => trim($l->text())));
        self::assertSame(['Brew a better cup.'], $html->filter('#outcomes .cl-landing-checklist li')->each(static fn(Crawler $l): string => trim($l->text())));
        self::assertSame('Fresh', trim($html->filter('#benefits .cl-landing-benefit-title')->text()));
        self::assertSame(['How is it brewed?'], $html->filter('#faq details summary .cl-ui-accordion-title')->each(static fn(Crawler $q): string => trim($q->text())), 'Closed accordions.');
        self::assertSame(0, $html->filter('#faq details[open]')->count());
        self::assertSame('The sale ends in', trim($html->filter('#countdown .cl-ui-countdown-words')->text()));
        self::assertNotNull($html->filter('#countdown')->attr('data-cl-countdown-scope'), 'Gone at zero.');
        self::assertSame(strtotime('2026-11-30T23:59:59+02:00'), strtotime((string) $html->filter('#countdown time')->attr('datetime')), 'To the end of the campaign\'s last day, in the site\'s time zone.');
        self::assertSame('https://video.example.com/farm.mp4', $html->filter('#video a')->attr('href'), 'Not YouTube or Vimeo: a link.');
        self::assertSame('Ready to start?', trim($html->filter('#cta h2')->text()));
        self::assertSame(['View'], $html->filter('#cta .cl-landing-cta-actions a')->each(static fn(Crawler $a): string => trim($a->text())), 'With no purchase box of the project\'s, its card\'s button.');

        $this->marketing->site->clock->modify('+30 days');
        $later = $this->builder()->forVisitor($this->marketing->pages->require($page->reference));
        self::assertNotNull($later, 'Ended, and the page kept.');
        self::assertNotContains('countdown', array_map(static fn(array $s): string => $s['kind'], $later->sections), 'No time left: the countdown is left out.');
    }

    public function testASectionWithNothingToDrawIsLeftOutAndNoButtonLeadsToIt(): void
    {
        $page = $this->campaignPage();
        $view = $this->builder()->forVisitor($page);
        self::assertSame(['label' => 'See the products and bundles', 'href' => '#offers', 'icon' => 'action-jump-down', 'inert' => false], $view?->hero['secondary'] ?? null);
        $this->shop->hide('product:tea-100');
        $this->shop->hide('product:mug-01');
        $view = $this->builder()->forVisitor($page);
        self::assertNotNull($view);
        self::assertSame(['faq', 'cta'], array_map(static fn(array $s): string => $s['anchor'], $view->sections), 'No offer visitors may see: the offers are left out.');
        self::assertNull($view->hero['secondary'] ?? null, 'Nor does the hero\'s button lead to them.');
    }

    public function testAProjectWithNoProjectDrawsACampaignsPage(): void
    {
        $harness = new MarketingHarness($this->connection, [], new NoProject());
        try {
            $harness->campaigns->create(new CampaignInput('Open day', 'open-day', destination: '/'));
            $harness->campaigns->activate('open-day');
            $page = $harness->pages->createForCampaign('open-day')->page;
            foreach ($harness->sections->list($page) as $section) {
                match ($section->kindKey) {
                    'hero' => $harness->sections->save($page->reference, $section->reference, ['picture' => ['source' => 'none']]),
                    'faq' => $harness->sections->save($page->reference, $section->reference, ['items' => [['question' => 'When is it?', 'answer' => 'On Saturday.']]]),
                    'cta' => $harness->sections->save($page->reference, $section->reference, ['button' => 'address', 'target' => '/visit', 'label' => 'Book a visit']),
                    'offers' => $harness->sections->hide($page->reference, $section->reference),
                    default => null,
                };
            }
            $page = $harness->pages->publish($page->reference);
            $builder = new LandingViewBuilder($harness->pages, $harness->kinds, $harness->campaigns, new GenericLanding(), new NoProject(), $harness->pictureSources, $harness->site->installation,
                null, UiHarness::twig());
            $view = $builder->forVisitor($page);
            self::assertNotNull($view);
            $html = new Crawler($builder->drawSection($view));
            self::assertSame('Open day', trim($html->filter('h1')->text()));
            self::assertSame(['faq', 'cta'], $html->filter('section.cl-landing-section')->each(static fn(Crawler $s): string => (string) $s->attr('id')));
            $cta = $this->sectionOf($harness, $page, 'cta');
            self::assertSame(['Book a visit', '/c/open-day/go/' . $cta->reference . '-1'], [trim($html->filter('#cta a')->text()), $html->filter('#cta a')->attr('href')]);
            self::assertSame('/visit', $builder->press($view, $cta->reference . '-1')['href'] ?? null);
        } finally {
            $harness->remove();
        }
    }

    private function builder(?LandingProject $landing = null, ?ContentSecurityPolicy $policy = null): LandingViewBuilder
    {
        $twig = UiHarness::twig();
        $loader = $twig->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $loader);
        $loader->addPath(dirname(__DIR__) . '/Support/templates', 'test');

        return new LandingViewBuilder($this->marketing->pages, $this->marketing->kinds, $this->marketing->campaigns, $landing ?? new GenericLanding(), $this->marketing->project,
            $this->marketing->pictureSources, $this->marketing->site->installation, $policy ?? new ContentSecurityPolicy(new RequestStack()), $twig);
    }

    /**
     * A shop's own landing part: a badge, a rating, a sample to try, a basket form for Buy now, its own purchase box, and
     * the words its hero shows when none are written ($about, its line when empty).
     */
    private function shopLanding(string $about = ''): LandingProject
    {
        return new class ($about) implements LandingProject {
            public function __construct(private readonly string $about)
            {
            }

            public function card(Offer $offer, LandingVisit $visit, CardOptions $options): OfferCard
            {
                return new OfferCard($offer->key(), $offer->label, (string) $offer->href, badges: ['Bestseller'], line: 'Picked by hand.', price: $offer->price,
                    rating: ['filled' => 5, 'average' => '4.5', 'count' => 12], actions: [new OfferAction('view', 'View', '', href: (string) $offer->href),
                    new OfferAction('buy', 'Add to basket', 'action-buy', 'POST', action: '/basket/add', fields: ['offer' => $offer->key()])],
                    links: ['sample' => ['label' => 'Try a sample', 'href' => $offer->href . '/sample']], about: $this->about);
            }

            public function purchase(Offer $offer, LandingVisit $visit, string $style): ProjectPart
            {
                return new ProjectPart('@test/marketing-landing-public-purchase.html.twig', ['words' => 'Bought in a box of the shop\'s own']);
            }

            public function page(LandingView $view): array
            {
                return ['active_nav' => 'shop'];
            }

            public function access(LandingPage $page, ?UserInterface $reader): LandingAccess
            {
                return LandingAccess::none();
            }
        };
    }

    /** The spring sale's page, its questions answered, published unless asked not to be. */
    private function campaignPage(bool $published = true): LandingPage
    {
        $page = $this->marketing->pages->createForCampaign('spring-sale')->page;
        $this->answerTheQuestions($page);

        return $published ? $this->marketing->pages->publish($page->reference) : $page;
    }

    private function answerTheQuestions(LandingPage $page): void
    {
        $this->marketing->sections->save($page->reference, $this->section($page, 'faq')->reference, ['items' => [['question' => 'How is it brewed?', 'answer' => 'Four minutes.']]]);
    }

    private function section(LandingPage $page, string $kind): LandingSection
    {
        return $this->sectionOf($this->marketing, $page, $kind);
    }

    private function sectionOf(MarketingHarness $harness, LandingPage $page, string $kind): LandingSection
    {
        foreach ($harness->sections->list($page) as $section) {
            if ($section->kindKey === $kind) {
                return $section;
            }
        }

        throw new \LogicException('The page has no ' . $kind . ' section.');
    }

    private function request(): Request
    {
        return Request::create('/c/spring-sale', server: ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    /** A press's form as the page posts it to the project's handler. */
    private function press(string $page, string $return = '/c/spring-sale'): Request
    {
        return Request::create('/basket/add', 'POST', [LandingPresses::PAGE_FIELD => $page, LandingPresses::RETURN_FIELD => $return, 'offer' => 'product:tea-100'],
            server: ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '198.51.100.7']);
    }
}

