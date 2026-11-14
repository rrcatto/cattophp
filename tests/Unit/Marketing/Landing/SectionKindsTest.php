<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing\Landing;

use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\JumpTarget;
use CattoPHP\Marketing\Landing\Kinds\AudienceKind;
use CattoPHP\Marketing\Landing\Kinds\BenefitsKind;
use CattoPHP\Marketing\Landing\Kinds\CountdownKind;
use CattoPHP\Marketing\Landing\Kinds\CtaKind;
use CattoPHP\Marketing\Landing\Kinds\FaqKind;
use CattoPHP\Marketing\Landing\Kinds\HeroKind;
use CattoPHP\Marketing\Landing\Kinds\OffersKind;
use CattoPHP\Marketing\Landing\Kinds\OutcomesKind;
use CattoPHP\Marketing\Landing\Kinds\PictureKind;
use CattoPHP\Marketing\Landing\Kinds\VideoKind;
use CattoPHP\Marketing\Landing\Kinds\WordsKind;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Landing\SectionKind;
use CattoPHP\Marketing\Landing\SectionKinds;
use CattoPHP\Marketing\Offer;
use CattoPHP\Tests\Kernel\App\ExampleMarketingProject;
use CattoPHP\Ui\IconLibrary;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * CattoPHP's eleven kinds of section: each has its words and icon, defaults that pass its own rules on both kinds of page,
 * its limits, what stops a page being published while it is shown, its one line in the editor and where it may be. The
 * rules of the kinds CattoLMS's course pages have today (the hero, who it is for, outcomes, benefits, questions and
 * answers, the call to action) are carried over, as CattoLMS's LandingPagesTest proved them.
 */
final class SectionKindsTest extends TestCase
{
    public function testEveryKindHasItsWordsAndDefaultsThatPassItsOwnRulesOnBothKindsOfPage(): void
    {
        $labels = [];
        $icons = new IconLibrary();
        foreach ($this->kinds()->all() as $key => $kind) {
            self::assertSame($key, $kind->key());
            self::assertNotSame('', $kind->label());
            self::assertStringNotContainsString('_', $kind->label(), 'No implementation name is shown.');
            self::assertNotSame('', $kind->description());
            self::assertTrue($icons->has($kind->icon()), $key . '\'s icon is in the icon library.');
            self::assertStringStartsWith('@CattoPHP/marketing/landing/sections/', $kind->template());
            self::assertStringStartsWith('@CattoPHP/marketing/landing/forms/', $kind->formTemplate());
            foreach ([$this->campaignPage(), $this->offerPage()] as $page) {
                self::assertSame($kind->defaults(), $kind->normalise($kind->defaults(), $page), $key . ' defaults are stored as they are, on a ' . $page->kind . ' page.');
                self::assertTrue($kind->appliesTo($page), $key . ' can be on any page.');
            }
            foreach (['headline', 'text', 'heading', 'words', 'caption', 'kicker'] as $copy) {
                if (array_key_exists($copy, $kind->defaults())) {
                    self::assertSame('', $kind->defaults()[$copy], $key . ' starts without ' . $copy . '.');
                }
            }
            self::assertSame([], $kind->needs());
            $labels[] = $kind->label();
        }
        self::assertSame(['Hero', 'Words', 'Picture', 'Video', 'Products and bundles', 'Who it is for', 'Outcomes', 'Benefits', 'Countdown', 'Questions and answers', 'Call to action'], $labels,
            'The offers section takes the project\'s own heading.');
    }

    public function testOnlyTheHeroWhoItIsForOutcomesAndTheCountdownAreOnceAPageAndOnlyTheHeroAndTheCountdownAreAlwaysFull(): void
    {
        $once = array_keys(array_filter($this->kinds()->all(), static fn(SectionKind $kind): bool => $kind->once()));
        self::assertSame(['hero', 'audience', 'outcomes', 'countdown'], $once);
        $full = array_keys(array_filter($this->kinds()->all(), static fn(SectionKind $kind): bool => $kind->alwaysFull()));
        self::assertSame(['hero', 'countdown'], $full);
        $jumps = array_map(static fn(JumpTarget $kind): string => $kind->jumpLabel(), array_filter($this->kinds()->all(), static fn(SectionKind $kind): bool => $kind instanceof JumpTarget));
        self::assertSame(['offers' => 'See the products and bundles', 'faq' => 'Questions and answers'], $jumps);
    }

    public function testListsAreOneStatementALineAndRowsNeedBothTheirFieldsAsOnCourseLandingPages(): void
    {
        $page = $this->offerPage();
        $audience = (new AudienceKind())->normalise(['heading' => '  Who  ', 'text' => "One\r\n\r\n\r\nTwo", 'items' => "Conveyancers\n\n  Candidate attorneys  \n"], $page);
        self::assertSame(['heading' => 'Who', 'text' => "One\n\nTwo", 'items' => ['Conveyancers', 'Candidate attorneys']], $audience);
        $cards = (new AudienceKind())->normalise(['items' => [['title' => 'Paralegals', 'text' => 'Who want to grow.'], ['title' => '', 'text' => 'Anyone else'], ['title' => '', 'text' => '']]], $page);
        self::assertSame([['title' => 'Paralegals', 'text' => 'Who want to grow.'], 'Anyone else'], $cards['items'], 'A point is a line, or a bold title and its line.');
        $this->refuses(static fn() => (new AudienceKind())->normalise(['items' => [['title' => 'Only a title', 'text' => '']]], $page), 'needs its words', 'items.0.text');
        self::assertSame(['A line'], (new OutcomesKind())->normalise(['items' => [['title' => 'No titles here', 'text' => 'A line']]], $page)['items'], 'Outcomes have no titles.');

        $faq = (new FaqKind())->normalise(['items' => [1 => ['question' => 'How long?', 'answer' => 'A year.'], 2 => ['question' => '', 'answer' => '']]], $page);
        self::assertSame([['question' => 'How long?', 'answer' => 'A year.']], $faq['items'], 'An empty row is dropped.');
        $this->refuses(static fn() => (new FaqKind())->normalise(['items' => [['question' => 'Half a row', 'answer' => '']]], $page), 'needs its answer', 'items.0.answer');
        $this->refuses(static fn() => (new BenefitsKind())->normalise(['items' => [['title' => '', 'text' => 'No title']]], $page), 'needs its title', 'items.0.title');
        $this->refuses(static fn() => (new AudienceKind())->normalise(['items' => implode("\n", array_fill(0, 13, 'Someone'))], $page), 'at most 12', 'items');
        $this->refuses(static fn() => (new OutcomesKind())->normalise(['items' => array_fill(0, 21, 'Something')], $page), 'at most 20', 'items');
        $this->refuses(static fn() => (new HeroKind())->normalise(['headline' => str_repeat('x', 121)], $page), 'longer than 120', 'headline');
        $this->refuses(static fn() => (new HeroKind())->normalise(['text' => str_repeat('x', 601)], $page), 'longer than 600', 'text');
        $this->refuses(static fn() => (new HeroKind())->normalise(['secondary_label' => str_repeat('x', 41)], $page), 'longer than 40', 'secondary_label');
        $this->refuses(static fn() => (new HeroKind())->normalise(['kicker' => str_repeat('x', 61)], $page), 'longer than 60', 'kicker');
        $this->refuses(static fn() => (new HeroKind())->normalise(['secondary' => 'https://example.com/buy'], $page), 'where the button leads', 'secondary');
        $this->refuses(static fn() => (new HeroKind())->normalise(['picture' => ['source' => 'https://example.com/a.png']], $page), 'hero picture', 'picture');
        $this->refuses(static fn() => (new CtaKind())->normalise(['text' => str_repeat('x', 601)], $page), 'longer than 600', 'text');
        $this->refuses(static fn() => (new WordsKind())->normalise(['text' => str_repeat('x', 4001)], $page), 'longer than 4000', 'text');
        $this->refuses(static fn() => (new PictureKind())->normalise(['caption' => str_repeat('x', 201)], $page), 'longer than 200', 'caption');
        $this->refuses(static fn() => (new BenefitsKind())->normalise(['items' => [['title' => str_repeat('t', 81), 'text' => 'A']]], $page), 'longer than 80', 'items.0.title');
        $this->refuses(static fn() => (new BenefitsKind())->normalise(['items' => [['title' => 'T', 'text' => str_repeat('e', 401)]]], $page), 'longer than 400', 'items.0.text');
        $this->refuses(static fn() => (new FaqKind())->normalise(['items' => [['question' => 'Q', 'answer' => str_repeat('a', 2001)]]], $page), 'longer than 2000', 'items.0.answer');
        self::assertSame(['heading' => '', 'text' => '', 'items' => []], (new BenefitsKind())->normalise(['heading' => '', 'anything' => 'else'], $page), 'What is outside the kind is ignored.');
    }

    public function testTheHeroRemembersItsPictureAndWhatAListNeedsBeforeThePageIsPublished(): void
    {
        $page = $this->offerPage();
        $hero = new HeroKind();
        self::assertSame(['source' => 'offer', 'ref' => ''], $hero->defaults()['picture'], 'The offer\'s own picture to start, as a course\'s cover today.');
        self::assertNull($hero->normalise(['picture' => ['source' => 'none']], $page)['picture']);
        self::assertSame(['source' => 'upload', 'ref' => str_repeat('a', 64) . '.png'], $hero->normalise(['picture' => ['source' => 'upload', 'ref' => str_repeat('a', 64) . '.png']], $page)['picture']);
        self::assertSame(['The hero has no picture chosen.'], $hero->missing($hero->normalise(['picture' => ['source' => 'upload', 'ref' => '']], $page), $page));
        self::assertSame([], $hero->missing($hero->defaults(), $page));
        self::assertSame('', $hero->normalise(['not_running_headline' => 'Over'], $page)['not_running_headline'], 'Only a campaign\'s page is ever not running.');
        self::assertSame('Over', $hero->normalise(['not_running_headline' => 'Over'], $this->campaignPage())['not_running_headline']);
        foreach ([new AudienceKind(), new OutcomesKind(), new BenefitsKind(), new FaqKind(), new WordsKind(), new PictureKind(), new OffersKind(), new VideoKind()] as $kind) {
            self::assertCount(1, $kind->missing($kind->defaults(), $page), $kind->key() . ' with nothing in it cannot be published.');
        }
        self::assertSame(['Who it is for has no statements yet.', 'Outcomes has no outcomes yet.', 'Benefits has no benefits yet.', 'Questions and answers has no questions yet.'],
            [(new AudienceKind())->missing([], $page)[0], (new OutcomesKind())->missing([], $page)[0], (new BenefitsKind())->missing([], $page)[0], (new FaqKind())->missing([], $page)[0]]);
        foreach ([new CtaKind(), new HeroKind()] as $kind) {
            self::assertSame([], $kind->missing($kind->defaults(), $page), $kind->key() . ' is complete as it is.');
        }
        self::assertSame(['Call to action has no part of the page chosen for its button.'], (new CtaKind())->missing(['button' => 'section', 'target' => ''], $page));
        self::assertSame(['Call to action has no offer for its button.'], (new CtaKind())->missing((new CtaKind())->defaults(), $this->campaignPage(offers: [])));
        self::assertSame(['Countdown has no end chosen.'], (new CountdownKind())->missing((new CountdownKind())->defaults(), $page));
        self::assertSame(['Countdown: the campaign has no end date, so there is nothing to count down to.'], (new CountdownKind())->missing([], $this->campaignPage(ends: null)));
        self::assertSame([], (new CountdownKind())->missing([], $this->campaignPage()));
    }

    public function testEachKindSaysInOneLineWhatItHolds(): void
    {
        $page = $this->offerPage();
        self::assertSame('3 questions', (new FaqKind())->summary(['items' => [[], [], []]], $page));
        self::assertSame('"More" · 1 question', (new FaqKind())->summary(['heading' => 'More', 'items' => [[]]], $page));
        self::assertSame('2 statements', (new AudienceKind())->summary(['items' => ['a', 'b']], $page));
        self::assertSame('1 outcome', (new OutcomesKind())->summary(['items' => ['a']], $page));
        self::assertSame('0 benefits', (new BenefitsKind())->summary([], $page));
        self::assertSame('"Become a barista"', (new HeroKind())->summary(['headline' => 'Become a barista'], $page));
        self::assertSame('The product\'s name as the headline', (new HeroKind())->summary([], $page));
        self::assertSame('The campaign\'s name as the headline', (new HeroKind())->summary([], $this->campaignPage()));
        self::assertSame('The buy button', (new CtaKind())->summary([], $page));
        self::assertSame('"Start today"', (new CtaKind())->summary(['heading' => 'Start today'], $page));
        self::assertSame('2 chosen, the first marked First', (new OffersKind())->summary(['offers' => ['product:a', 'product:b'], 'first_marked' => true], $page));
        self::assertSame('No video chosen yet', (new VideoKind())->summary([], $page));
        self::assertSame('Tea in a minute, 38 seconds', (new VideoKind())->summary(['title' => 'Tea in a minute', 'length' => '38 seconds', 'address' => 'https://video.example.com/1'], $page));
        self::assertSame('No picture chosen yet', (new PictureKind())->summary([], $page));
        self::assertSame('Counts down to 30 Nov 2026, 23:59', (new CountdownKind())->summary([], $this->campaignPage()));
        self::assertSame('"Why" · 2 paragraphs', (new WordsKind())->summary(['heading' => 'Why', 'text' => "One\n\nTwo"], $page));
    }

    public function testTheOffersSectionKeepsOffersByKeyAndShowsOnlyThoseVisitorsMaySee(): void
    {
        $kind = new OffersKind(new ExampleMarketingProject());
        $campaign = $this->campaignPage();
        $data = $kind->normalise(['offers' => ['bundle:gift-box', 'product:tea-100', 'bundle:gift-box', ''], 'buttons' => ['buy', 'view'], 'first_marked' => '1', 'show_promotion' => 'on'], $campaign);
        self::assertSame(['bundle:gift-box', 'product:tea-100'], $data['offers'], 'Each once, in order.');
        self::assertSame(['view', 'buy'], $data['buttons'], 'In the buttons\' own order.');
        self::assertTrue($data['show_promotion']);
        self::assertFalse($kind->normalise(['show_promotion' => 'on'], $this->offerPage())['show_promotion'], 'Only a campaign\'s page has a promotion.');
        $this->refuses(static fn() => $kind->normalise(['buttons' => ['delete']], $campaign), 'Choose the buttons', 'buttons');
        $this->refuses(static fn() => $kind->normalise(['offers' => ['not a key']], $campaign), 'Choose the offers', 'offers');
        $this->refuses(static fn() => $kind->normalise(['offers' => array_map(static fn(int $n): string => 'product:p' . $n, range(1, 25))], $campaign), 'at most 24', 'offers');
        $view = $kind->view($data + ['heading' => '', 'text' => ''], $campaign, new LandingVisit(null, false, '/c/spring-sale', 'abcdefgh'));
        self::assertNotNull($view);
        self::assertSame(['bundle:gift-box', 'product:tea-100'], $view['offers']);
        self::assertTrue($view['first_marked']);
        self::assertTrue($view['show_promotion'], 'Shown while the campaign runs and it has a promotion.');
        self::assertSame('Products and bundles', $view['heading'], 'The project\'s heading when none is written.');
        $stopped = $kind->view($data, $this->campaignPage(running: false), new LandingVisit(null, false, '/c/spring-sale', 'abcdefgh'));
        self::assertFalse($stopped['show_promotion'] ?? true, 'Never while the campaign is not running.');
        self::assertNull($kind->view(['offers' => ['product:gone']], $campaign, new LandingVisit(null, false, '/', 'abcdefgh')), 'An offer the project no longer has is left out, and an empty section with it.');
        self::assertSame(['Products and bundles has no offers chosen yet.'], $kind->missing([], $campaign));
    }

    public function testTheCountdownCountsToTheCampaignsEndOrATypedOneAndGoesAtZero(): void
    {
        $kind = new CountdownKind();
        $visit = new LandingVisit(null, false, '/', 'abcdefgh');
        $view = $kind->view([], $this->campaignPage(), $visit);
        self::assertNotNull($view);
        self::assertSame(['The special ends in', 20, 13, 59], [$view['words'], $view['days'], $view['hours'], $view['minutes']], 'From 10 Nov 10:00 to 30 Nov 23:59.');
        self::assertNull($kind->view([], $this->campaignPage(running: false), $visit), 'Never while the campaign is not running.');
        self::assertNull($kind->view([], $this->campaignPage(ends: '2026-11-10 09:00'), $visit), 'Gone once the time is up.');
        $offer = $this->offerPage();
        self::assertSame('2026-12-24 18:00', $kind->normalise(['ends_at' => '2026-12-24T18:00', 'words' => 'Christmas ends in'], $offer)['ends_at']);
        self::assertSame('2026-12-24 23:59', $kind->normalise(['ends_at' => '2026-12-24'], $offer)['ends_at'], 'A date alone is its last minute.');
        self::assertSame('', $kind->normalise(['ends_at' => '2026-12-24 18:00'], $this->campaignPage())['ends_at'], 'A campaign\'s page counts to the campaign\'s end.');
        $this->refuses(static fn() => $kind->normalise(['ends_at' => 'Christmas'], $offer), 'date and time', 'ends_at');
        self::assertSame('Christmas ends in', $kind->view(['ends_at' => '2026-12-24 18:00', 'words' => 'Christmas ends in'], $offer, $visit)['words'] ?? null);
    }

    public function testTheCallToActionsButtonLeadsOnlyWhereItsAuthorChose(): void
    {
        $kind = new CtaKind();
        $page = $this->campaignPage();
        self::assertSame(['button' => 'section', 'offer' => '', 'target' => 'faq'], array_intersect_key($kind->normalise(['button' => 'section', 'target' => 'faq', 'offer' => 'product:tea-100'], $page), ['button' => 0, 'offer' => 0, 'target' => 0]));
        self::assertSame('/shop', $kind->normalise(['button' => 'address', 'target' => '/shop'], $page)['target']);
        $this->refuses(static fn() => $kind->normalise(['button' => 'address', 'target' => 'https://example.com/elsewhere'], $page), 'path on the site', 'target');
        $this->refuses(static fn() => $kind->normalise(['button' => 'address', 'target' => '//example.com'], $page), 'path on the site', 'target');
        $this->refuses(static fn() => $kind->normalise(['button' => 'download'], $page), 'what the button does', 'button');
        $view = $kind->view($kind->defaults(), $page, new LandingVisit(null, false, '/', 'abcdefgh'));
        self::assertSame(['buy', 'product:tea-100', 'Ready to start?'], [$view['button'], $view['offer'], $view['heading']], 'Buying the page\'s first offer when none is chosen.');
    }

    public function testAVideoIsEmbeddedOnlyFromYouTubeAndVimeoAndOtherwiseLinked(): void
    {
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', VideoKind::embed('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10'));
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', VideoKind::embed('https://youtu.be/dQw4w9WgXcQ'));
        self::assertSame('https://www.youtube-nocookie.com/embed/abcdefGHIJK', VideoKind::embed('https://www.youtube.com/shorts/abcdefGHIJK'));
        self::assertSame('https://player.vimeo.com/video/76979871', VideoKind::embed('https://vimeo.com/76979871'));
        self::assertNull(VideoKind::embed('https://video.example.com/watch/1'));
        self::assertNull(VideoKind::embed('https://www.youtube.com/watch?v=<script>'));
        $page = $this->offerPage();
        $kind = new VideoKind();
        $view = $kind->view($kind->normalise(['address' => 'https://vimeo.com/76979871', 'title' => 'Tea in a minute'], $page), $page, new LandingVisit(null, false, '/', 'abcdefgh'));
        self::assertSame(['https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871', 'player.vimeo.com'], [$view['address'] ?? null, $view['embed'] ?? null, $view['embed_host'] ?? null]);
        $this->refuses(static fn() => $kind->normalise(['address' => 'http://video.example.com/1'], $page), 'starting with https://', 'address');
        $this->refuses(static fn() => $kind->normalise(['advert' => 'NOT AN ADVERT'], $page), 'video adverts', 'advert');
        self::assertNull($kind->view($kind->defaults(), $page, new LandingVisit(null, false, '/', 'abcdefgh')), 'No video, no section.');
    }

    public function testTheHeroShowsItsNotRunningWordsWhileTheCampaignIsNotRunning(): void
    {
        $hero = new HeroKind();
        $data = $hero->normalise(['kicker' => 'November special', 'headline' => 'Spring tea, half price', 'text' => 'This week only.', 'not_running_headline' => 'Spring tea', 'not_running_text' => 'Our spring teas.'], $this->campaignPage());
        $visit = new LandingVisit(null, false, '/c/spring-sale', 'abcdefgh');
        $running = $hero->view($data, $this->campaignPage(), $visit);
        self::assertSame(['November special', 'Spring tea, half price', [['This week only.']]], [$running['kicker'], $running['headline'], $running['paragraphs']]);
        $stopped = $hero->view($data, $this->campaignPage(running: false), $visit);
        self::assertSame(['', 'Spring tea', [['Our spring teas.']]], [$stopped['kicker'], $stopped['headline'], $stopped['paragraphs']]);
        self::assertSame('Spring sale', $running['fallback_headline'], 'The campaign\'s name when no headline is written.');
        self::assertSame(['source' => 'offer', 'ref' => ''], $hero->view($hero->defaults(), $this->offerPage(), $visit)['picture']);
        self::assertSame('Rooibos tea, 100 bags', $hero->view($hero->defaults(), $this->offerPage(), $visit)['fallback_headline']);
    }

    public function testTwoKindsWithTheSameKeyOrABadKeyAreRefused(): void
    {
        try {
            new SectionKinds([new FaqKind(), new FaqKind()]);
            self::fail('Two kinds with one key were taken.');
        } catch (\LogicException $refused) {
            self::assertStringContainsString('Two landing section kinds have the key "faq"', $refused->getMessage());
        }
        $bad = new class extends \CattoPHP\Marketing\Landing\Kinds\BaseKind {
            public function key(): string { return 'Not-A-Key'; }
            public function label(): string { return 'Bad'; }
            public function description(): string { return 'Bad'; }
            public function icon(): string { return 'box'; }
            public function once(): bool { return false; }
            public function defaultHeading(): string { return ''; }
            public function defaults(): array { return []; }
            public function normalise(array $input, LandingContext $page): array { return []; }
            public function missing(array $data, LandingContext $page): array { return []; }
        };
        $this->expectException(\LogicException::class);
        new SectionKinds([$bad]);
    }

    private function kinds(): SectionKinds
    {
        return new SectionKinds([new HeroKind(), new WordsKind(), new PictureKind(), new VideoKind(), new OffersKind(new ExampleMarketingProject()), new AudienceKind(), new OutcomesKind(),
            new BenefitsKind(), new CountdownKind(), new FaqKind(), new CtaKind()]);
    }

    /** @param list<Offer>|null $offers */
    private function campaignPage(bool $running = true, ?string $ends = '2026-11-30 23:59', ?array $offers = null): LandingContext
    {
        $zone = new DateTimeZone('Africa/Johannesburg');
        $now = new DateTimeImmutable('2026-11-10 10:00', $zone);
        $campaign = new Campaign(1, 'spring-sale', 'Spring sale', '', '', 'active', new DateTimeImmutable('2026-11-01', $zone), $ends === null ? null : new DateTimeImmutable($ends, $zone), null, '/shop',
            'SPRING10', null, $now, $now);
        $project = new ExampleMarketingProject();

        return new LandingContext(LandingContext::CAMPAIGN, $campaign, $running, $offers ?? [$project->offers()[0], $project->offers()[2]], $now, offersHeading: 'Products and bundles', promotion: 'SPRING10');
    }

    private function offerPage(): LandingContext
    {
        return new LandingContext(LandingContext::OFFER, null, true, [(new ExampleMarketingProject())->offers()[0]], new DateTimeImmutable('2026-11-10 10:00', new DateTimeZone('Africa/Johannesburg')),
            offersHeading: 'Products and bundles', offerWord: 'product');
    }

    private function refuses(callable $call, string $message, ?string $field): void
    {
        try {
            $call();
            self::fail('Expected a refusal mentioning ' . $message);
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString($message, $refused->getMessage());
            if ($field !== null) {
                self::assertInstanceOf(CopyRefused::class, $refused);
                self::assertSame($field, $refused->field);
            }
        }
    }
}
