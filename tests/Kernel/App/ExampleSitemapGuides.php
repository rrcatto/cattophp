<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Search\LlmsTxtContent;
use CattoPHP\Search\SitemapSource;

/** A project's own things in its sitemap, after its public pages, and what its llms.txt says. */
final class ExampleSitemapGuides implements SitemapSource, LlmsTxtContent
{
    public function count(): int
    {
        return 1;
    }

    public function entries(int $limit, int $offset): array
    {
        return array_slice([['path' => '/guides/soil', 'changed_at' => '2026-11-01 08:00:00+00']], $offset, $limit);
    }

    public function name(): string
    {
        return 'Example Site';
    }

    public function summary(): string
    {
        return 'Example Site is a garden design site.';
    }

    public function notes(): array
    {
        return [];
    }

    public function sections(string $base): array
    {
        return [['title' => 'Guides', 'entries' => [['name' => 'Soil', 'url' => $base . '/guides/soil', 'notes' => 'What a garden grows in.']]]];
    }
}
