<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Search;

use CattoPHP\Search\DefaultSharePicture;
use CattoPHP\Search\PageHead;
use CattoPHP\Search\PublicPage;
use CattoPHP\Search\PublicPageProvider;
use CattoPHP\Search\PublicPages;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** What every public page's head needs and did not set, ported from cattolms's search metadata tests. */
final class PageHeadTest extends TestCase
{
    public function testEveryPublicPageHasItsOwnDescriptionCanonicalAddressAndShareCard(): void
    {
        $head = self::head();
        $meta = $head->complete('guides', [], '/guides', [], 'Guides');
        self::assertSame('Every guide on the site.', $meta['description'], 'The public page\'s own description.');
        self::assertSame('https://example.com/guides', $meta['canonical']);
        self::assertSame(['type' => 'website', 'title' => 'Guides', 'description' => 'Every guide on the site.', 'url' => 'https://example.com/guides', 'locale' => 'en_ZA',
            'image' => 'https://example.com/picture-abc.jpg', 'image:type' => 'image/jpeg', 'image:width' => '1200', 'image:height' => '630', 'image:alt' => 'Example Gardens'], $meta['og']);
        self::assertSame('summary_large_image', $meta['twitter_card']);
        self::assertArrayNotHasKey('title', $meta, 'The title is the page\'s own.');

        $second = $head->complete('guides', ['description' => 'Its own words.'], '/guides', ['page' => '2', 'view' => 'grid', 'size' => '48'], 'Guides');
        self::assertSame('Its own words.', $second['description'], 'A page\'s own description is kept.');
        self::assertSame('https://example.com/guides?page=2', $second['canonical'], 'Its page beyond the first, and never the view or the size.');
        self::assertSame('Guides, page 2', $second['title']);
        self::assertSame('Guides', $second['og']['title']);

        $other = $head->complete('guide', ['description' => 'A guide.'], '/guides/soil', [], 'Soil');
        self::assertSame('https://example.com/guides/soil', $other['canonical'], 'A page that is not listed still gets its address.');
        self::assertSame('A guide.', $other['description']);
        $category = $head->complete('guides', [], '/guides/roses', [], 'Roses');
        self::assertArrayNotHasKey('description', $category, 'A list\'s page at another address is not the list itself.');

        $own = $head->complete('guides', ['og' => ['title' => 'Old', 'image' => 'https://example.com/own.png'], 'title' => 'Named'], '/guides', [], 'Guides');
        self::assertSame(['title' => 'Named', 'image' => 'https://example.com/own.png'], $own['og'], 'A page\'s own share card is kept, its title the page\'s.');
        self::assertArrayNotHasKey('twitter_card', $own);
    }

    public function testSearchesAndPagesThatAskNotToBeIndexedClaimNoAddress(): void
    {
        $head = self::head();
        foreach ([['q' => 'roses'], ['guides_q' => 'soil']] as $query) {
            $meta = $head->complete('guides', [], '/guides', $query, 'Guides');
            self::assertSame('noindex, follow', $meta['robots'], json_encode($query) ?: '');
            self::assertArrayNotHasKey('canonical', $meta);
            self::assertArrayNotHasKey('og', $meta);
        }
        self::assertArrayNotHasKey('robots', $head->complete('guides', [], '/guides', ['q' => '  '], 'Guides'), 'An empty search is the page.');
        self::assertArrayNotHasKey('robots', $head->complete('guides', [], '/guides', ['faq' => 'x'], 'Guides'), 'Only a search field (q, or one ending _q).');
        $preview = $head->complete('guides', ['robots' => 'noindex'], '/guides', [], 'Guides');
        self::assertSame('noindex', $preview['robots']);
        self::assertArrayNotHasKey('canonical', $preview);
        $nowhere = self::head('')->complete('guides', [], '/guides', [], 'Guides');
        self::assertSame(['description' => 'Every guide on the site.'], $nowhere, 'A site without its address gets its description and nothing else.');
    }

    public function testWithoutASitePictureTheCardIsTheSmallOne(): void
    {
        $meta = self::head('https://example.com', false)->complete('home', [], '/', [], 'Example Gardens');
        self::assertSame('https://example.com/', $meta['canonical']);
        self::assertArrayNotHasKey('image', $meta['og']);
        self::assertSame('summary', $meta['twitter_card']);
    }

    public function testPreviewsShowTheWordsGivenElseThePagesOwn(): void
    {
        $head = self::head();
        self::assertSame(['url' => 'https://example.com/guides', 'title' => 'Guides', 'description' => 'Every guide on the site.', 'default_title' => 'Guides', 'default_description' => 'Every guide on the site.', 'picture' => 'https://example.com/picture-abc.jpg'], $head->pagePreview('guides'));
        self::assertSame(['url' => 'https://example.com/guides/soil', 'title' => 'Soil & roots', 'description' => 'Its own.', 'default_title' => 'Soil', 'default_description' => 'About soil.', 'picture' => 'https://example.com/soil.png'],
            $head->preview('/guides/soil', 'Soil', 'About soil.', '<b>Soil &amp; roots</b>', ' Its own. ', 'https://example.com/soil.png'));
        self::assertSame('en-ZA', $head->language());
        self::assertSame('https://example.com', $head->base());
    }

    public function testThePagesLanguageIsTheInstallationsLocaleASetting(): void
    {
        $site = new Foundations();
        $head = new PageHead(new PublicPages([self::provider()]), $site->installation);
        self::assertSame('en-GB', $head->language(), 'None set: plain British English, never one read from the process\'s environment.');
        $site->settings->set('APP_LOCALE', 'en_ZA');
        self::assertSame('en-ZA', $head->language(), 'As the Settings page says.');
        self::assertSame('en_ZA', $head->complete('guides', [], '/guides', [], 'Guides')['og']['locale'] ?? null);
    }

    public function testAPublicPageIsCheckedAndFoundByItsKey(): void
    {
        $pages = new PublicPages([self::provider()]);
        self::assertSame(['home', 'guides'], array_keys($pages->all()));
        self::assertSame('home', $pages->key('home', '/'));
        self::assertSame('guides', $pages->key('guides', '/guides/'));
        self::assertNull($pages->key('guides', '/guides/roses'));
        self::assertNull($pages->key('guide', '/guides'), 'The page drawn must be the public page.');
        $this->expectException(InvalidArgumentException::class);
        new PublicPage('Guides', 'Guides', '/guides', '', '');
    }

    private static function head(string $address = 'https://example.com', bool $picture = true): PageHead
    {
        $site = new Foundations(['APP_URL' => $address, 'APP_LOCALE' => 'en_ZA']);
        $shared = $picture ? new class implements DefaultSharePicture {
            public function url(string $base): string
            {
                return $base . '/picture-abc.jpg';
            }

            public function alt(): string
            {
                return 'Example Gardens';
            }

            public function type(): string
            {
                return 'image/jpeg';
            }
        } : null;

        return new PageHead(new PublicPages([self::provider()]), $site->installation, null, $shared);
    }

    private static function provider(): PublicPageProvider
    {
        return new class implements PublicPageProvider {
            public function pages(): iterable
            {
                yield new PublicPage('home', 'Home page', '/', 'Gardens designed by hand.', 'Example Gardens');
                yield new PublicPage('guides', 'Guides', '/guides', 'Every guide on the site.', 'Guides');
            }
        };
    }
}
