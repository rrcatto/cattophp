<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing\Landing;

use CattoPHP\Marketing\Landing\BuiltInTemplates;
use CattoPHP\Marketing\Landing\CardOptions;
use CattoPHP\Marketing\Landing\GenericLanding;
use CattoPHP\Marketing\Landing\Kinds\AudienceKind;
use CattoPHP\Marketing\Landing\Kinds\CountdownKind;
use CattoPHP\Marketing\Landing\Kinds\CtaKind;
use CattoPHP\Marketing\Landing\Kinds\FaqKind;
use CattoPHP\Marketing\Landing\Kinds\HeroKind;
use CattoPHP\Marketing\Landing\Kinds\OffersKind;
use CattoPHP\Marketing\Landing\LandingAccess;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingTemplate;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Marketing\Landing\LandingVersion;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Landing\OfferAction;
use CattoPHP\Marketing\Landing\OfferCard;
use CattoPHP\Marketing\Landing\SectionKinds;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\Offer;
use CattoPHP\Tests\Kernel\App\ExampleMarketingProject;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The landing pages' smaller parts: a template applied to a page (its kinds that do not belong left out and named, the
 * page's offers taken in, the hero first), CattoPHP's two built-in templates, an offer's card and actions as data, who may
 * edit a page when the project gives no rule of its own, and a kept version's state in words.
 */
final class LandingValuesTest extends TestCase
{
    public function testATemplateAppliedLeavesOutKindsThatDoNotBelongAndSaysWhich(): void
    {
        $offers = (new ExampleMarketingProject())->offers();
        $kinds = new SectionKinds([new HeroKind(), new OffersKind(new ExampleMarketingProject()), new FaqKind(), new CtaKind(), new CountdownKind(), new AudienceKind()]);
        $templates = new LandingTemplates(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $kinds, [new BuiltInTemplates()]);
        $campaign = new LandingContext(LandingContext::CAMPAIGN, null, true, [$offers[2], $offers[0]], new DateTimeImmutable('2026-11-10 10:00'));
        $template = new LandingTemplate('test', 'A test', 'For the test.', 'both', [
            ['kind' => 'faq', 'width' => 'half', 'data' => ['heading' => 'Asked often']],
            ['kind' => 'hero', 'width' => 'half', 'data' => ['headline' => 'Tea for spring']],
            ['kind' => 'offers'],
            ['kind' => 'contents'],
            ['kind' => 'audience'],
            ['kind' => 'audience'],
            ['kind' => 'countdown', 'shown_when' => 'running', 'width' => 'half'],
            ['kind' => 'cta', 'data' => ['heading' => str_repeat('x', 500)]],
        ]);
        $applied = $templates->apply($template, $campaign);
        self::assertSame(['hero', 'faq', 'offers', 'audience', 'countdown', 'cta'], array_column($applied->sections, 'kind'), 'The hero first, a kind held once kept once.');
        self::assertSame(['contents', 'Who it is for'], $applied->skipped, 'A kind the site does not have, and a second of a kind held once, named.');
        self::assertSame(['full', 'half', 'full', 'full', 'full', 'full'], array_column($applied->sections, 'width'), 'The hero and the countdown always full.');
        self::assertSame(['bundle:gift-box', 'product:tea-100'], $applied->sections[2]['data']['offers'], 'The offers section takes the page\'s offers.');
        self::assertSame('running', $applied->sections[4]['shown_when']);
        self::assertSame('', $applied->sections[5]['data']['heading'], 'Data that does not pass is put back to the kind\'s defaults.');
        self::assertSame('Tea for spring', $applied->sections[0]['data']['headline']);

        $offerPage = new LandingContext(LandingContext::OFFER, null, true, [$offers[0]], new DateTimeImmutable('2026-11-10 10:00'));
        self::assertSame('always', $templates->apply($template, $offerPage)->sections[4]['shown_when'], 'An offer\'s page is always running.');
        self::assertSame(['several-offers'], array_map(static fn(LandingTemplate $t): string => $t->key, $templates->builtIn($offerPage)), 'The special suits campaign pages only.');
        self::assertSame(['several-offers', 'special-with-end-date'], array_map(static fn(LandingTemplate $t): string => $t->key, $templates->builtIn($campaign)));
        $special = $templates->apply($templates->get('special-with-end-date') ?? throw new \LogicException('No special.'), $campaign);
        self::assertSame(['hero', 'countdown', 'offers', 'faq', 'cta'], array_column($special->sections, 'kind'));
        self::assertTrue($special->sections[2]['data']['show_promotion'], 'The special shows the promotion\'s price.');
        self::assertSame('offers', $special->sections[0]['data']['secondary']);
        self::assertSame([], $special->skipped);
        self::assertNull($templates->get('no-such'));
    }

    public function testASavedTemplateKeepsCopyAndLeavesTheOffersOut(): void
    {
        self::assertSame(['heading' => 'Ours', 'offers' => [], 'offer' => '', 'picture' => ['source' => 'offer', 'ref' => '']],
            LandingTemplates::withoutOffers(['heading' => 'Ours', 'offers' => ['product:tea-100'], 'offer' => 'product:tea-100', 'picture' => ['source' => 'offer', 'ref' => 'product:tea-100']]));
        self::assertSame(['picture' => ['source' => 'upload', 'ref' => 'a.png']], LandingTemplates::withoutOffers(['picture' => ['source' => 'upload', 'ref' => 'a.png']]));
        $this->expectException(\InvalidArgumentException::class);
        new LandingTemplate('x', 'X', '', 'everywhere', []);
    }

    public function testAnOffersCardAndItsActionsAreWordsAndListsOnly(): void
    {
        $buy = new OfferAction('buy', 'Buy now', 'shopping-cart', 'POST', action: '/basket/add', fields: ['offer' => 'tea-100', '_token' => 'abc']);
        $card = new OfferCard('product:tea-100', 'Rooibos tea', '/shop/tea-100', ['kind' => 'image', 'src' => '/tea.jpg', 'alt' => 'A cup of tea'], ['Organic'], 'A smooth tea.', ['100 bags'],
            'R95', 'R120', 'You save R25', note: '', rating: ['filled' => 4, 'average' => '4.5', 'count' => 12], actions: [new OfferAction('view', 'View', 'eye', href: '/shop/tea-100'), $buy],
            links: ['preview' => ['label' => 'Try a sample', 'href' => '/shop/tea-100/sample']]);
        self::assertSame($buy, $card->action('buy'));
        self::assertSame('A smooth tea.', $card->heroWords(), 'Without words of its own for the hero, its line.');
        $about = new OfferCard('product:tea-100', 'Rooibos tea', line: 'A smooth tea.', about: "Grown on one farm.\n\nPicked by hand.");
        self::assertSame("Grown on one farm.\n\nPicked by hand.", $about->heroWords(), 'Its own words for the hero, whole.');
        self::assertSame("Grown on one farm.\n\nPicked by hand.", $about->toArray()['about']);
        self::assertSame('A smooth tea.', (new OfferCard('product:tea-100', 'Rooibos tea', line: 'A smooth tea.', about: "  \n "))->heroWords(), 'Blank words are none.');
        self::assertNull($card->action('favourite'));
        self::assertTrue($buy->isForm());
        $array = $card->toArray();
        self::assertSame(['view', 'buy'], array_column($array['actions'], 'kind'));
        array_walk_recursive($array, static fn(mixed $value) => self::assertTrue(is_scalar($value) || $value === null, 'Only words and numbers.'));
        $this->expectException(\InvalidArgumentException::class);
        new OfferAction('delete', 'Delete', 'trash');
    }

    public function testWithoutTheProjectsOwnRuleAnOffersPageIsEditedByWhoeverManagesCampaigns(): void
    {
        $page = new LandingPage(1, 'abcdefgh', null, 'product', 'tea-100', 'tea', 'draft', null, null, null, null, null, 'keep', null, null, null, new DateTimeImmutable(), new DateTimeImmutable());
        $reader = new InMemoryUser('ayanda@example.com', null);
        $manager = new GenericLanding($this->granting([MarketingPermissions::MANAGE, MarketingPermissions::VIEW]));
        self::assertEquals(LandingAccess::all(), $manager->access($page, $reader));
        self::assertEquals(LandingAccess::viewOnly(), (new GenericLanding($this->granting([MarketingPermissions::VIEW])))->access($page, $reader));
        self::assertEquals(LandingAccess::none(), (new GenericLanding($this->granting([])))->access($page, $reader));
        self::assertEquals(LandingAccess::none(), $manager->access($page, null), 'Nobody signed in sees nothing.');
        $visit = new LandingVisit(null, false, '/landing/tea', 'abcdefgh');
        $card = $manager->card(new Offer('product', 'tea-100', 'Rooibos tea', '/shop/tea-100', 'R95', 'R120'), $visit, new CardOptions());
        self::assertSame(['Rooibos tea', 'R95', 'R120', '', ['view']], [$card->label, $card->price, $card->was, $card->note, array_map(static fn(OfferAction $a): string => $a->kind, $card->actions)]);
        $closed = $manager->card(new Offer('product', 'mug-01', 'Enamel mug', null, 'R150', '', false), $visit, new CardOptions(['buy']));
        self::assertSame(['Not on sale at the moment.', []], [$closed->note, $closed->actions], 'No View link without its page, nor when View is not asked for.');
        self::assertNull($manager->purchase(new Offer('product', 'tea-100', 'Rooibos tea'), $visit, 'full'));
    }

    public function testAKeptVersionSaysWhatItWasInWords(): void
    {
        $at = new DateTimeImmutable('2026-11-05 14:10');
        self::assertSame('Published now', (new LandingVersion(1, 1, 2, 'published', $at, null, 'The price block changed', ['sections' => []]))->state());
        self::assertSame('Replaced 7 Nov', (new LandingVersion(1, 1, 2, 'published', $at, null, '', [], new DateTimeImmutable('2026-11-07 09:00'), 'published'))->state());
        self::assertSame('Taken down 7 Nov', (new LandingVersion(1, 1, 2, 'published', $at, null, '', [], new DateTimeImmutable('2026-11-07 09:00'), 'unpublished'))->state());
        self::assertSame('Taken down', (new LandingVersion(1, 1, 3, 'unpublished', $at, null, 'Taken down', null))->state());
        $version = new LandingVersion(1, 1, 1, 'published', $at, null, '', ['settings' => ['meta_title' => 'Tea'], 'sections' => [['kind' => 'hero'], 'not a section']]);
        self::assertSame([['kind' => 'hero']], $version->sections());
        self::assertSame(['meta_title' => 'Tea'], $version->settings());
        self::assertTrue($version->isCurrent());
    }

    /** @param list<string> $granted */
    private function granting(array $granted): AuthorizationCheckerInterface
    {
        return new class ($granted) implements AuthorizationCheckerInterface {
            /** @param list<string> $granted */
            public function __construct(private readonly array $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?\Symfony\Component\Security\Core\Authorization\AccessDecision $accessDecision = null): bool
            {
                return in_array($attribute, $this->granted, true);
            }
        };
    }
}
