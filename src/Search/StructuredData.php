<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/**
 * Structured data (schema.org JSON-LD) that search engines and AI assistants read from a public page: the nodes any site
 * has (the business behind it, the site itself, a FAQ, the trail above a page, a list of the things a page shows) and
 * the rules every node keeps; a project builds the nodes for its own things with the same rules and helpers.
 *
 * The rules, from Google's structured data guidelines (checked 2026-11-06):
 * - Nothing is marked up that the page does not show, and nothing on a page that is not public or asks not to be
 *   indexed.
 * - A rating only from approved reviews and only with at least one (an AggregateRating needs a ratingCount of 1 or
 *   more), as the page itself shows it, out of 5.
 * - Every address is absolute, from the site's public address; without one there is no structured data at all.
 * - Each node is encoded so it cannot end its script early (< and > as \u003C and \u003E).
 */
final class StructuredData
{
    public const CONTEXT = 'https://schema.org';

    /** The site's public address without its trailing slash, when it is a web address; null otherwise, and then nothing is marked up. */
    public static function base(string $appUrl): ?string
    {
        $base = rtrim(trim($appUrl), '/');
        return preg_match('#^https?://[^/\s]+#i', $base) === 1 ? $base : null;
    }

    /**
     * A list of the things a page shows, each by its own page's address, in the page's order: the summary page of
     * Google's list carousels (each item's page carries its own markup). Google shows a carousel for three items or more,
     * so a shorter list is not marked up; null then.
     *
     * @param list<string> $urls each item's page, absolute
     * @return array<string,mixed>|null
     */
    public static function itemList(string $url, string $name, array $urls): ?array
    {
        if (count($urls) < 3) return null;
        $items = [];
        foreach ($urls as $index => $item) $items[] = ['@type' => 'ListItem', 'position' => $index + 1, 'url' => $item];
        return ['@context' => self::CONTEXT, '@type' => 'ItemList', 'name' => $name, 'url' => $url, 'itemListElement' => $items];
    }

    /**
     * The business behind the site, on the home page.
     *
     * @param array{id:string,name:string,legal_name:string,url:string,logo:string,email:string,telephone:string,address:string,country:string,contact_url:string,same_as:list<string>,description:string} $owner
     * @return array<string,mixed>
     */
    public static function organisation(array $owner): array
    {
        $node = ['@context' => self::CONTEXT, '@type' => 'Organization', '@id' => $owner['id'], 'name' => $owner['name'], 'url' => $owner['url']];
        if ($owner['legal_name'] !== '' && $owner['legal_name'] !== $owner['name']) $node['legalName'] = $owner['legal_name'];
        if ($owner['description'] !== '') $node['description'] = $owner['description'];
        if ($owner['logo'] !== '') $node['logo'] = $owner['logo'];
        if ($owner['email'] !== '') $node['email'] = $owner['email'];
        if ($owner['telephone'] !== '') $node['telephone'] = $owner['telephone'];
        if ($owner['address'] !== '') {
            $node['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $owner['address']] + ($owner['country'] !== '' ? ['addressCountry' => $owner['country']] : []);
        }
        $contact = ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'url' => $owner['contact_url']];
        if ($owner['email'] !== '') $contact['email'] = $owner['email'];
        if ($owner['telephone'] !== '') $contact['telephone'] = $owner['telephone'];
        $node['contactPoint'] = [$contact];
        if ($owner['same_as'] !== []) $node['sameAs'] = $owner['same_as'];
        return $node;
    }

    /**
     * The site itself, on the home page: its name for search results, and who publishes it.
     *
     * @return array<string,mixed>
     */
    public static function website(string $id, string $name, string $url, string $language, string $publisherId): array
    {
        return ['@context' => self::CONTEXT, '@type' => 'WebSite', '@id' => $id, 'name' => $name, 'url' => $url, 'inLanguage' => $language, 'publisher' => ['@id' => $publisherId]];
    }

    /**
     * The FAQ's questions, each with its answer as the page shows it. Google stopped showing FAQ rich results on 7 May
     * 2026; FAQPage is still schema.org's, and other search engines and AI assistants read it.
     *
     * @param list<array{question:string,answer:string}> $questions the answer as HTML (paragraphs, links and lists)
     * @return array<string,mixed>
     */
    public static function faq(string $url, array $questions): array
    {
        return ['@context' => self::CONTEXT, '@type' => 'FAQPage', 'url' => $url, 'mainEntity' => array_map(static fn(array $q): array => [
            '@type' => 'Question',
            'name' => $q['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['answer']],
        ], $questions)];
    }

    /**
     * The trail the page shows above it (every theme draws the same one), with absolute addresses; null for a trail of
     * fewer than two steps or one with a step that has no address before the last.
     *
     * @param list<array<string,mixed>> $crumbs label, href ('' for the page itself), current
     * @return array<string,mixed>|null
     */
    public static function breadcrumbs(array $crumbs, string $base, string $here): ?array
    {
        if (count($crumbs) < 2) return null;
        $items = [];
        $last = count($crumbs) - 1;
        foreach ($crumbs as $index => $crumb) {
            $label = trim((string) ($crumb['label'] ?? ''));
            $href = trim((string) ($crumb['href'] ?? ''));
            if ($label === '') return null;
            if ($href === '' && $index !== $last) return null;
            $item = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $label];
            $item['item'] = $href === '' ? $base . $here : (str_starts_with($href, 'http') ? $href : $base . $href);
            $items[] = $item;
        }
        return ['@context' => self::CONTEXT, '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * One node as the text of its script: it can never close the script early.
     *
     * @param array<string,mixed> $node
     */
    public static function encode(array $node): string
    {
        return json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * A length of time as ISO 8601 (a workload), rounded as a page words a rough length ("about 125 hours" is
     * PT125H, "about an hour and a half" PT1H30M, "about 25 minutes" PT25M), so the markup says what the page says. Null
     * for no time.
     */
    public static function duration(int $minutes): ?string
    {
        if ($minutes <= 0) return null;
        if ($minutes < 55) return 'PT' . max(5, (int) (round($minutes / 5) * 5)) . 'M';
        if ($minutes >= 600) return 'PT' . (int) round($minutes / 60) . 'H';
        $halves = (int) round($minutes / 30);
        return 'PT' . intdiv($halves, 2) . 'H' . ($halves % 2 === 1 ? '30M' : '');
    }

    /** The page's language from a locale (en_ZA is en-ZA), en-GB when it is not one. */
    public static function language(string $locale): string
    {
        $locale = trim(str_replace('_', '-', $locale));
        return preg_match('/^[a-z]{2,3}(-[A-Za-z]{2})?$/', $locale) === 1 ? $locale : 'en-GB';
    }

    /**
     * An organisation named where it is not the page's subject (a provider, a brand): its id, name and address where known.
     *
     * @param array{name:string,url:string,id:string} $organisation
     * @return array<string,mixed>
     */
    public static function organisationReference(array $organisation): array
    {
        return array_filter(['@type' => 'Organization', '@id' => $organisation['id'], 'name' => $organisation['name'], 'url' => $organisation['url']], static fn(string $value): bool => $value !== '');
    }

    /**
     * A rating out of 5, as the page shows it: only ever from at least one approved review.
     *
     * @param array{value:string,count:int} $rating
     * @return array<string,mixed>
     */
    public static function rating(array $rating): array
    {
        return ['@type' => 'AggregateRating', 'ratingValue' => $rating['value'], 'ratingCount' => $rating['count'], 'bestRating' => 5, 'worstRating' => 1];
    }
}
