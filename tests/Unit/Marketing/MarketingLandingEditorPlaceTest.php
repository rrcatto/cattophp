<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\Landing\LandingAccess;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Pages\EditorPlace;
use CattoPHP\Marketing\Pages\LandingEditor;
use CattoPHP\Ui\Contract\AddressContract;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\RouteCollection;

/** Where a landing page's editor is mounted (EditorPlace), and the routes LandingEditor::routes() gives a project. */
final class MarketingLandingEditorPlaceTest extends TestCase
{
    public function testAPlaceIsForAPageOrForAnOfferToMakeOneFor(): void
    {
        $place = new EditorPlace('shop_landing', ['reference' => 'tea-100'], LandingAccess::viewOnly(), offerType: 'product', offerReference: 'tea-100', backHref: '/shop');
        self::assertSame(['shop_landing_edit', 'shop_landing_section_save', null], [$place->routeName('edit'), $place->routeName('section_save'), $place->page]);
        $page = self::page();
        $mounted = $place->withPage($page);
        self::assertSame([$page, 'product', 'tea-100', '/shop', false], [$mounted->page, $mounted->offerType, $mounted->offerReference, $mounted->backHref, $mounted->access->edit],
            'The same place, its access kept, now for the page made.');

        foreach ([
            fn (): EditorPlace => new EditorPlace('Shop landing', [], LandingAccess::all(), $page),
            fn (): EditorPlace => new EditorPlace('shop_landing', [], LandingAccess::all()),
            fn (): EditorPlace => new EditorPlace('shop_landing', [], LandingAccess::all(), $page, backHref: 'https://example.com/'),
        ] as $number => $refused) {
            try {
                $refused();
                self::fail('Place ' . $number . ' is refused.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheRoutesAreNamedByOneNameEachWithItsEndingAndTheActionItAsks(): void
    {
        $collection = new RouteCollection();
        $routes = new RoutingConfigurator($collection, new PhpFileLoader(new FileLocator()), __FILE__, __FILE__);
        LandingEditor::routes($routes, 'shop_landing', '/shop/{reference}/landing', 'App\\Shop::answer', ['reference' => '[a-z0-9-]{1,40}'], true);
        self::assertCount(count(LandingEditor::ROUTES), $collection->all(), 'Every route, with the post that makes the page.');
        $save = $collection->get('shop_landing_section_save');
        self::assertNotNull($save);
        self::assertSame(['/shop/{reference}/landing/sections/{section}', ['POST'], 'section_save', '[a-z0-9]{8}', '[a-z0-9-]{1,40}', 'App\\Shop::answer'],
            [$save->getPath(), $save->getMethods(), $save->getDefault('action'), $save->getRequirement('section'), $save->getRequirement('reference'), $save->getDefault('_controller')]);
        self::assertSame(['/shop/{reference}/landing/versions/{version}', 'v[1-9][0-9]{0,5}'], [$collection->get('shop_landing_version')?->getPath(), $collection->get('shop_landing_version')?->getRequirement('version')]);
        self::assertSame([], AddressContract::rowNumbers($collection), 'A kept version is v and its number, never a bare number.');

        $without = new RouteCollection();
        LandingEditor::routes(new RoutingConfigurator($without, new PhpFileLoader(new FileLocator()), __FILE__, __FILE__), 'campaign_landing', '/pages/{reference}', 'App\\Pages::answer');
        self::assertNull($without->get('campaign_landing_make'), 'A page that always exists has no post that makes it.');
        self::assertCount(count(LandingEditor::ROUTES) - 1, $without->all());
    }

    private static function page(): LandingPage
    {
        $now = new DateTimeImmutable('2026-11-14 10:00');

        return new LandingPage(1, 'abcd1234', null, 'product', 'tea-100', 'rooibos', LandingPage::DRAFT, null, null, null, null, null, 'keep', null, null, null, $now, $now);
    }
}
