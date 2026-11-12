<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Search\PublicPage;
use CattoPHP\Search\PublicPageProvider;
use CattoPHP\Search\SitemapSource;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** A project's public pages, which it also lists first in its sitemap: found by autoconfiguration, as a project's are. */
#[AsTaggedItem(priority: 10)]
final class ExampleSitemapPages implements SitemapSource, PublicPageProvider
{
    private const PAGES = ['/' => 'Example Site', '/guides' => 'Guides'];

    public function pages(): iterable
    {
        yield new PublicPage('home', 'Home page', '/', 'Gardens designed by hand.', 'Example Site');
        yield new PublicPage('guides', 'Guides', '/guides', 'Every guide on the site.', 'Guides');
    }

    public function count(): int
    {
        return count(self::PAGES);
    }

    public function entries(int $limit, int $offset): array
    {
        return array_map(static fn (string $path): array => ['path' => $path, 'changed_at' => null], array_slice(array_keys(self::PAGES), $offset, $limit));
    }
}
