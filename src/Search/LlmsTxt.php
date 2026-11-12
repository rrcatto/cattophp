<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use CattoPHP\Runtime\SiteMode;

/**
 * GET /llms.txt: a summary of the site for AI assistants in the llms.txt format (llmstxt.org): the site's name as its
 * heading, a short description in a quote, a few lines on how it works, then sections listing pages with their
 * addresses and one line about each, from the project's LlmsTxtContent. Generated on each request from what is stored,
 * as robots.txt is. Like the sitemap, a demo site has none, nor a site that does not know its own address; robots.txt
 * names it on a site that has one.
 */
final class LlmsTxt
{
    /** The longest line said about one link. */
    public const LINE = 200;

    public function __construct(
        private readonly Sitemap $sitemap,
        private readonly SiteMode $site,
        private readonly ?LlmsTxtContent $content = null,
    ) {
    }

    /** Whether this site has one: a project that says what, on a live site that knows its own address. */
    public function available(): bool
    {
        return $this->content !== null && !$this->site->isDemo() && $this->sitemap->base() !== null;
    }

    /** The file, or null on a site that has none. */
    public function document(): ?string
    {
        $base = $this->sitemap->base();
        if ($this->content === null || $this->site->isDemo() || $base === null) {
            return null;
        }
        $lines = ['# ' . PlainText::line($this->content->name()), '', '> ' . PlainText::line($this->content->summary())];
        foreach ($this->content->notes() as $note) {
            $lines[] = '';
            $lines[] = PlainText::line($note);
        }
        foreach ($this->content->sections($base) as $section) {
            if ($section['entries'] === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = '## ' . PlainText::line($section['title']);
            $lines[] = '';
            foreach ($section['entries'] as $entry) {
                $lines[] = self::entry($entry['name'], $entry['url'], $entry['notes']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** One Markdown link with its line, as llmstxt.org lists a file: "- [name](url): notes". */
    public static function entry(string $name, string $url, string $notes): string
    {
        $notes = PlainText::excerpt($notes, self::LINE);

        return '- [' . str_replace(['[', ']'], ['(', ')'], PlainText::line($name)) . '](' . $url . ')' . ($notes === '' ? '' : ': ' . PlainText::line($notes));
    }
}
