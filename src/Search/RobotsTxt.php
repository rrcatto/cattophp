<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/**
 * GET /robots.txt, generated so its Sitemap line carries the site's own full address: the crawl rules (the project's
 * private paths, each group with its reason), a group for each AI crawler as Settings says (AiCrawlers, SearchSettings),
 * and on a site with them the full address of its sitemap and of its summary for AI assistants (/llms.txt). It never
 * tells search engines to stay away entirely: one kept away never reads a page's "do not index" note.
 */
final class RobotsTxt
{
    /**
     * @param list<array{why:string,paths:list<string>}> $privatePaths the paths crawlers are asked to leave alone, in
     *        groups, each with the reason written above it
     */
    public function __construct(
        private readonly SearchSettings $search,
        private readonly Sitemap $sitemap,
        private readonly ?LlmsTxt $llms = null,
        private readonly array $privatePaths = [],
    ) {
    }

    public function document(): string
    {
        $lines = ['# The crawl rules for this site. They ask crawlers to stay out of private and single-use', '# pages; they are not access control.', '', 'User-agent: *'];
        $lines = [...$lines, ...$this->private(), 'Allow: /'];
        $choice = $this->search->aiCrawlers();
        $lines[] = '';
        $lines[] = '# AI crawlers, as Settings > Search engines and AI says: ' . match ($choice) {
            SearchSettings::BLOCK_ALL => 'none of them may come in.',
            SearchSettings::SEARCH_ONLY => 'AI search and answer assistants may come in, crawlers that only collect training data may not.',
            default => 'every one of them may come in.',
        };
        $lines[] = '# A crawler named here follows only its own group, so each one that may come in repeats the private paths.';
        $lines[] = '# The list as of ' . AiCrawlers::CHECKED . ', from each company\'s own documentation.';
        foreach (AiCrawlers::all() as $token => $crawler) {
            $lines[] = '';
            $lines[] = '# ' . $crawler['company'] . ': ' . $crawler['purpose'] . '.';
            $lines[] = 'User-agent: ' . $token;
            $lines = AiCrawlers::allowed($crawler['kind'], $choice) ? [...$lines, ...$this->private(false), 'Allow: /'] : [...$lines, 'Disallow: /'];
        }
        if ($this->sitemap->available()) {
            $base = (string) $this->sitemap->base();
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
            if ($this->llms?->available() === true) {
                $lines[] = '# A summary of this site for AI assistants, in the llms.txt format (llmstxt.org): ' . $base . '/llms.txt';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The private paths, each group with its reason as a comment, or the paths alone.
     *
     * @return list<string>
     */
    private function private(bool $comments = true): array
    {
        $lines = [];
        foreach ($this->privatePaths as $group) {
            if ($comments) {
                $lines[] = '# ' . $group['why'];
            }
            foreach ($group['paths'] as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
        }

        return $lines;
    }
}
