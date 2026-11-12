<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/**
 * Addresses for the sitemap: a project's public pages, or a kind of thing it publishes. The sitemap lists each source's
 * addresses in turn, the sources in their tag's priority order (highest first).
 */
interface SitemapSource
{
    public const TAG = 'cattophp.sitemap_sources';

    /** How many addresses it lists now. */
    public function count(): int;

    /**
     * Its addresses in its own order, from $offset, at most $limit: each a path on the site and when what it shows last
     * changed (an ISO 8601 moment, or null where no date is known, which the sitemap protocol allows).
     *
     * @return list<array{path:string,changed_at:?string}>
     */
    public function entries(int $limit, int $offset): array;
}
