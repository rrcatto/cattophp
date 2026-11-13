<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Search;

use CattoPHP\Search\PlainText;
use CattoPHP\Search\StructuredData;
use PHPUnit\Framework\TestCase;

/** The structured data any site has, ported from cattolms's search tests. */
final class StructuredDataTest extends TestCase
{
    public function testALengthOfTimeIsWordedAsAPageWordsIt(): void
    {
        foreach ([0 => null, 3 => 'PT5M', 23 => 'PT25M', 54 => 'PT55M', 60 => 'PT1H', 95 => 'PT1H30M', 150 => 'PT2H30M', 599 => 'PT10H', 7500 => 'PT125H', 9000 => 'PT150H'] as $minutes => $iso) {
            self::assertSame($iso, StructuredData::duration($minutes), $minutes . ' minutes');
        }
    }

    public function testEachNodeIsEncodedSoItCannotEndItsScript(): void
    {
        $json = StructuredData::encode(['@context' => StructuredData::CONTEXT, '@type' => 'Thing', 'url' => 'https://example.com/things/one', 'description' => 'Ends here </script><script>alert(1)</script> & more']);
        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<', $json);
        self::assertSame('Ends here </script><script>alert(1)</script> & more', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['description']);
        self::assertStringContainsString('https://example.com/things/one', $json, 'Addresses are not escaped.');
    }

    public function testATrailOfTwoStepsOrMoreBecomesABreadcrumbList(): void
    {
        self::assertNull(StructuredData::breadcrumbs([['label' => 'Home', 'href' => '', 'current' => true]], 'https://example.com', '/'), 'The home page has no trail.');
        $trail = StructuredData::breadcrumbs([['label' => 'Home', 'href' => '/', 'current' => false], ['label' => 'Guides', 'href' => '/guides', 'current' => false], ['label' => 'Pruning roses', 'href' => '', 'current' => true]], 'https://example.com', '/guides/pruning-roses');
        self::assertNotNull($trail);
        self::assertSame('BreadcrumbList', $trail['@type']);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://example.com/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Guides', 'item' => 'https://example.com/guides'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Pruning roses', 'item' => 'https://example.com/guides/pruning-roses'],
        ], $trail['itemListElement']);
        self::assertNull(StructuredData::breadcrumbs([['label' => 'My account', 'href' => '', 'current' => false], ['label' => 'Orders', 'href' => '', 'current' => true]], 'https://example.com', '/x'), 'A step without an address before the last is no trail.');
        self::assertNull(StructuredData::breadcrumbs([['label' => '', 'href' => '/', 'current' => false], ['label' => 'Guides', 'href' => '', 'current' => true]], 'https://example.com', '/guides'), 'A step without words is no trail.');
    }

    public function testAListNeedsThreeItems(): void
    {
        self::assertNull(StructuredData::itemList('https://example.com/guides', 'Guides', ['https://example.com/guides/a', 'https://example.com/guides/b']));
        $list = StructuredData::itemList('https://example.com/guides', 'Guides', ['https://example.com/guides/a', 'https://example.com/guides/b', 'https://example.com/guides/c']);
        self::assertNotNull($list);
        self::assertSame(['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Guides', 'url' => 'https://example.com/guides'], array_diff_key($list, ['itemListElement' => true]));
        self::assertSame(['@type' => 'ListItem', 'position' => 3, 'url' => 'https://example.com/guides/c'], $list['itemListElement'][2]);
    }

    public function testTheHomePageNamesTheBusinessAndTheSite(): void
    {
        $organisation = StructuredData::organisation(['id' => 'https://example.com/#organization', 'name' => 'Example Gardens', 'legal_name' => 'Example Gardens (Pty) Ltd', 'url' => 'https://example.com/',
            'logo' => 'https://example.com/img/logo.png', 'email' => 'hello@example.com', 'telephone' => '+27 21 000 0000', 'address' => '1 Example Road, Cape Town, South Africa', 'country' => 'ZA',
            'contact_url' => 'https://example.com/contact', 'same_as' => [], 'description' => '']);
        self::assertSame('Example Gardens (Pty) Ltd', $organisation['legalName']);
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => '1 Example Road, Cape Town, South Africa', 'addressCountry' => 'ZA'], $organisation['address']);
        self::assertSame(['@type' => 'ContactPoint', 'contactType' => 'customer service', 'url' => 'https://example.com/contact', 'email' => 'hello@example.com', 'telephone' => '+27 21 000 0000'], $organisation['contactPoint'][0]);
        self::assertArrayNotHasKey('sameAs', $organisation, 'No profile, no sameAs: a platform\'s home page is not the business\'s profile.');
        self::assertArrayNotHasKey('description', $organisation);
        $same = StructuredData::organisation(['id' => 'https://example.com/#organization', 'name' => 'Example Gardens', 'legal_name' => 'Example Gardens', 'url' => 'https://example.com/', 'logo' => '', 'email' => '', 'telephone' => '',
            'address' => '', 'country' => '', 'contact_url' => 'https://example.com/contact', 'same_as' => ['https://www.linkedin.com/company/example-gardens'], 'description' => 'Gardens, designed.']);
        self::assertArrayNotHasKey('legalName', $same, 'A legal name that is the name is not said twice.');
        self::assertSame(['https://www.linkedin.com/company/example-gardens'], $same['sameAs']);
        self::assertSame('Gardens, designed.', $same['description']);
        $site = StructuredData::website('https://example.com/#website', 'Example Gardens', 'https://example.com/', 'en-ZA', 'https://example.com/#organization');
        self::assertSame(['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => 'https://example.com/#website', 'name' => 'Example Gardens', 'url' => 'https://example.com/', 'inLanguage' => 'en-ZA', 'publisher' => ['@id' => 'https://example.com/#organization']], $site);
    }

    public function testTheFaqIsEachQuestionWithItsAnswer(): void
    {
        $node = StructuredData::faq('https://example.com/faq', [['question' => 'Can I return a plant?', 'answer' => '<p>Within <a href="https://example.com/returns">30 days</a>.</p>']]);
        self::assertSame(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'url' => 'https://example.com/faq', 'mainEntity' => [
            ['@type' => 'Question', 'name' => 'Can I return a plant?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '<p>Within <a href="https://example.com/returns">30 days</a>.</p>']],
        ]], $node);
    }

    public function testAnOrganisationNamedElsewhereAndARating(): void
    {
        self::assertSame(['@type' => 'Organization', '@id' => 'https://example.com/#organization', 'name' => 'Example Gardens', 'url' => 'https://example.com/'], StructuredData::organisationReference(['name' => 'Example Gardens', 'url' => 'https://example.com/', 'id' => 'https://example.com/#organization']));
        self::assertSame(['@type' => 'Organization', 'name' => 'A Supplier'], StructuredData::organisationReference(['name' => 'A Supplier', 'url' => '', 'id' => '']), 'Only what is known.');
        self::assertSame(['@type' => 'AggregateRating', 'ratingValue' => '4.5', 'ratingCount' => 2, 'bestRating' => 5, 'worstRating' => 1], StructuredData::rating(['value' => '4.5', 'count' => 2]));
    }

    public function testTheAddressAndTheLanguage(): void
    {
        self::assertSame('https://example.com', StructuredData::base(' https://example.com/ '));
        self::assertSame('http://localhost:8080', StructuredData::base('http://localhost:8080'));
        self::assertNull(StructuredData::base(''));
        self::assertNull(StructuredData::base('example.com'), 'Not a web address: nothing is marked up.');
        self::assertSame('en-ZA', StructuredData::language('en_ZA'));
        self::assertSame('fr', StructuredData::language('fr'));
        self::assertSame('en-GB', StructuredData::language(''), 'Plain British English when the installation names none.');
        self::assertSame('en-GB', StructuredData::language('not a locale'));
    }

    public function testPlainWordsAndExcerpts(): void
    {
        self::assertSame('Prune & feed', PlainText::plain("<p>Prune\n &amp; <b>feed</b></p> "));
        self::assertSame('<b>kept</b> on one line', PlainText::line("  <b>kept</b>\n on   one line "));
        self::assertSame('First. Second', PlainText::excerpt('<p>First.</p><p>Second</p>', 100), 'A block\'s end is a space.');
        self::assertSame('A long sentence about…', PlainText::excerpt('A long sentence about pruning roses in winter', 25), 'Cut at the last space past the half.');
        self::assertSame('Abcdefghij…', PlainText::excerpt('Abcdefghijklmnop qr', 10), 'No space past the half: cut where it is.');
        self::assertSame('', PlainText::excerpt('<br>', 10));
    }
}
