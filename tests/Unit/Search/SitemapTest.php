<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Search;

use CattoPHP\Runtime\SiteMode;
use CattoPHP\Search\AiCrawlers;
use CattoPHP\Search\LlmsTxt;
use CattoPHP\Search\LlmsTxtContent;
use CattoPHP\Search\RobotsTxt;
use CattoPHP\Search\SearchSettings;
use CattoPHP\Search\Sitemap;
use CattoPHP\Search\SitemapSource;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** robots.txt, the sitemap and llms.txt, ported from cattolms's search and launch tests. */
final class SitemapTest extends TestCase
{
    private const PRIVATE = [
        ['why' => 'Single-use sign-in links: a crawler that follows one uses it up.', 'paths' => ['/auth/']],
        ['why' => 'Signed-in workspaces.', 'paths' => ['/account/', '/admin/']],
    ];

    public function testTheSourcesAreListedInTurnAndAnIndexBeyondTheLimit(): void
    {
        $sources = [self::source(['/', '/help'], ['2026-11-01 08:00:00+00', null]), self::source(['/guides/a', '/guides/b', '/guides/c'], ['2026-11-02 08:00:00+00', '2026-11-03 08:00:00+00', null])];
        $live = $this->sitemap($sources, SiteMode::LIVE);
        self::assertTrue($live->available());
        self::assertSame(1, $live->pages());
        $document = (string) $live->document();
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $document);
        preg_match_all('#<loc>([^<]+)</loc>#', $document, $locs);
        self::assertSame(['https://example.com/', 'https://example.com/help', 'https://example.com/guides/a', 'https://example.com/guides/b', 'https://example.com/guides/c'], $locs[1]);
        self::assertStringContainsString('<url><loc>https://example.com/</loc><lastmod>2026-11-01T08:00:00+00:00</lastmod></url>', $document);
        self::assertStringContainsString('<url><loc>https://example.com/help</loc></url>', $document, 'No date where none is known.');
        self::assertNull($live->page(1), 'One file: there are no numbered pages.');

        $small = $this->sitemap($sources, SiteMode::LIVE, 2);
        self::assertSame(3, $small->pages());
        self::assertStringContainsString('<sitemapindex', (string) $small->document());
        self::assertStringContainsString('<loc>https://example.com/sitemap-3.xml</loc>', (string) $small->document());
        $pages = [];
        foreach ([1, 2, 3] as $number) {
            preg_match_all('#<loc>([^<]+)</loc>#', (string) $small->page($number), $found);
            $pages[$number] = $found[1];
        }
        self::assertSame([1 => ['https://example.com/', 'https://example.com/help'], 2 => ['https://example.com/guides/a', 'https://example.com/guides/b'], 3 => ['https://example.com/guides/c']], $pages, 'Each page starts where the last ended, across the sources.');
        self::assertNull($small->page(4));
        self::assertNull($small->page(0));

        $demo = $this->sitemap($sources, SiteMode::DEMO);
        self::assertFalse($demo->available());
        self::assertNull($demo->document(), 'A demo site has no sitemap.');
        self::assertNull($demo->page(1));
        self::assertNull($this->sitemap($sources, SiteMode::LIVE, Sitemap::LIMIT, '')->document(), 'Nor a site that does not know its own address.');
    }

    public function testRobotsTxtFollowsEachAiCrawlerChoice(): void
    {
        $groups = static function (string $robots): array {
            $rules = [];
            foreach (preg_split('/\n\n/', $robots) ?: [] as $block) {
                if (preg_match('/^User-agent: (\S+)$/m', $block, $agent) === 1) {
                    $rules[$agent[1]] = str_contains($block, "Disallow: /\n") || str_ends_with($block, 'Disallow: /') ? 'out' : 'in';
                }
            }
            return $rules;
        };
        foreach ([SearchSettings::ALLOW_ALL, SearchSettings::SEARCH_ONLY, SearchSettings::BLOCK_ALL] as $choice) {
            $robots = $this->robots($choice, SiteMode::DEMO)->document();
            $rules = $groups($robots);
            self::assertSame('in', $rules['*'], 'Search engines are never kept away entirely.');
            foreach (AiCrawlers::all() as $token => $crawler) {
                self::assertSame(AiCrawlers::allowed($crawler['kind'], $choice) ? 'in' : 'out', $rules[$token] ?? null, $choice . ': ' . $token);
            }
        }
        $robots = $this->robots(SearchSettings::ALLOW_ALL, SiteMode::DEMO)->document();
        self::assertStringStartsWith("# The crawl rules for this site. They ask crawlers to stay out of private and single-use\n# pages; they are not access control.\n\nUser-agent: *\n# Single-use sign-in links: a crawler that follows one uses it up.\nDisallow: /auth/\n# Signed-in workspaces.\nDisallow: /account/\nDisallow: /admin/\nAllow: /\n", $robots);
        self::assertStringContainsString("\n# OpenAI: collects pages to train OpenAI's models.\nUser-agent: GPTBot\nDisallow: /auth/\nDisallow: /account/\nDisallow: /admin/\nAllow: /\n", $robots, 'A crawler let in repeats the private paths, without their reasons.');
        self::assertStringNotContainsString('Sitemap:', $robots, 'A demo site names no sitemap.');
        self::assertStringNotContainsString('llms.txt', $robots);
        $live = $this->robots(SearchSettings::BLOCK_ALL, SiteMode::LIVE)->document();
        self::assertStringEndsWith("\nSitemap: https://example.com/sitemap.xml\n# A summary of this site for AI assistants, in the llms.txt format (llmstxt.org): https://example.com/llms.txt\n", $live);
        self::assertStringContainsString("User-agent: GPTBot\nDisallow: /\n", $live);
        self::assertStringNotContainsString('llms.txt', $this->robots(SearchSettings::ALLOW_ALL, SiteMode::LIVE, false)->document(), 'Without llms.txt, robots.txt does not name it.');
    }

    public function testLlmsTxtIsALiveSitesOnly(): void
    {
        $llms = new LlmsTxt($this->sitemap([], SiteMode::LIVE), SiteMode::fixed(SiteMode::LIVE), self::content());
        self::assertTrue($llms->available());
        self::assertSame("# Example Gardens\n\n> Example Gardens designs gardens.\n\nEvery design is drawn by hand.\n\nVisits are booked online.\n\n## Guides\n\n- [Pruning (roses)](https://example.com/guides/pruning): When and how to prune.\n- [Soil](https://example.com/guides/soil)\n", $llms->document(), 'One line each, a section without links left out, brackets in a name made round.');
        self::assertNull((new LlmsTxt($this->sitemap([], SiteMode::DEMO), SiteMode::fixed(SiteMode::DEMO), self::content()))->document(), 'A demo site has none.');
        $none = new LlmsTxt($this->sitemap([], SiteMode::LIVE), SiteMode::fixed(SiteMode::LIVE));
        self::assertFalse($none->available());
        self::assertNull($none->document(), 'Nor a project that says nothing.');
        self::assertSame('- [Long](https://example.com/long): ' . str_repeat('word ', 39) . 'word…', LlmsTxt::entry('Long', 'https://example.com/long', str_repeat('word ', 60)), 'A line about a link is at most 200 characters.');
    }

    /** @param list<SitemapSource> $sources */
    private function sitemap(array $sources, string $mode, int $limit = Sitemap::LIMIT, string $address = 'https://example.com'): Sitemap
    {
        $site = new Foundations(['APP_URL' => $address]);

        return new Sitemap($sources, SiteMode::fixed($mode), $site->installation, new ArrayAdapter(), $limit);
    }

    private function robots(string $choice, string $mode, bool $llms = true): RobotsTxt
    {
        $sitemap = $this->sitemap([], $mode);

        return new RobotsTxt(SearchSettings::fixed(['ai_crawlers' => $choice]), $sitemap, $llms ? new LlmsTxt($sitemap, SiteMode::fixed($mode), self::content()) : null, self::PRIVATE);
    }

    /**
     * @param list<string> $paths
     * @param list<?string> $changed
     */
    private static function source(array $paths, array $changed): SitemapSource
    {
        return new class ($paths, $changed) implements SitemapSource {
            /**
             * @param list<string> $paths
             * @param list<?string> $changed
             */
            public function __construct(private readonly array $paths, private readonly array $changed)
            {
            }

            public function count(): int
            {
                return count($this->paths);
            }

            public function entries(int $limit, int $offset): array
            {
                $entries = [];
                foreach (array_slice($this->paths, $offset, $limit, true) as $index => $path) {
                    $entries[] = ['path' => $path, 'changed_at' => $this->changed[$index] ?? null];
                }

                return $entries;
            }
        };
    }

    private static function content(): LlmsTxtContent
    {
        return new class implements LlmsTxtContent {
            public function name(): string
            {
                return 'Example Gardens';
            }

            public function summary(): string
            {
                return "Example Gardens designs\n   gardens.";
            }

            public function notes(): array
            {
                return ['Every design is drawn by hand.', 'Visits are booked online.'];
            }

            public function sections(string $base): array
            {
                return [
                    ['title' => 'Guides', 'entries' => [['name' => 'Pruning [roses]', 'url' => $base . '/guides/pruning', 'notes' => '<p>When and how to prune.</p>'], ['name' => 'Soil', 'url' => $base . '/guides/soil', 'notes' => '']]],
                    ['title' => 'Nothing yet', 'entries' => []],
                ];
            }
        };
    }
}
