<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use CattoPHP\Installation\Installation;
use CattoPHP\Runtime\SiteMode;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * GET /sitemap.xml: the addresses the project's SitemapSource services list, absolute from the site's public address,
 * each with its last change where known. A sitemap holds at most 50,000 addresses (sitemaps.org): beyond that
 * /sitemap.xml becomes an index of /sitemap-1.xml, /sitemap-2.xml and so on. A rendered sitemap is kept for an hour; it
 * does not need to be live.
 *
 * A demo site has none (its addresses answer "not found"), nor a site that does not know its own address; its pages keep
 * their "do not index" note.
 */
final class Sitemap
{
    /** The most addresses one sitemap may list (sitemaps.org). */
    public const LIMIT = 50000;
    private const CACHE_SECONDS = 3600;

    /** @var list<SitemapSource>|null */
    private ?array $listed = null;

    /** @param iterable<SitemapSource> $sources */
    public function __construct(
        private readonly iterable $sources,
        private readonly SiteMode $site,
        private readonly Installation $installation,
        private readonly CacheInterface $cache,
        private readonly int $limit = self::LIMIT,
    ) {
    }

    /** Whether this site has a sitemap: a live site that knows its own address. */
    public function available(): bool
    {
        return !$this->site->isDemo() && $this->base() !== null;
    }

    /** The site's public address without its closing slash, when it is a web address. */
    public function base(): ?string
    {
        return StructuredData::base($this->installation->address());
    }

    /** The number of sitemap files: 1 is /sitemap.xml itself, more makes it an index. */
    public function pages(): int
    {
        $total = 0;
        foreach ($this->sources() as $source) {
            $total += $source->count();
        }

        return max(1, (int) ceil($total / $this->limit));
    }

    /** /sitemap.xml: the sitemap itself, or the index of its pages when there are too many addresses; null without one. */
    public function document(): ?string
    {
        if (!$this->available()) {
            return null;
        }

        return $this->cache->get('sitemap.document.' . $this->limit, function (ItemInterface $item): string {
            $item->expiresAfter(self::CACHE_SECONDS);
            $pages = $this->pages();

            return $pages === 1 ? $this->urlset(0) : $this->index($pages);
        });
    }

    /** /sitemap-{page}.xml, when /sitemap.xml is an index; null for a page that does not exist. */
    public function page(int $page): ?string
    {
        if (!$this->available() || $page < 1) {
            return null;
        }
        $pages = $this->pages();
        if ($pages === 1 || $page > $pages) {
            return null;
        }

        return $this->cache->get('sitemap.page.' . $this->limit . '.' . $page, function (ItemInterface $item) use ($page): string {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->urlset(($page - 1) * $this->limit);
        });
    }

    /** @return list<SitemapSource> */
    private function sources(): array
    {
        if ($this->listed === null) {
            $this->listed = [];
            foreach ($this->sources as $source) {
                $this->listed[] = $source;
            }
        }

        return $this->listed;
    }

    private function urlset(int $offset): string
    {
        $base = (string) $this->base();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $skip = $offset;
        $wanted = $this->limit;
        foreach ($this->sources() as $source) {
            if ($wanted <= 0) {
                break;
            }
            $count = $source->count();
            if ($skip >= $count) {
                $skip -= $count;
                continue;
            }
            $entries = $source->entries($wanted, $skip);
            $skip = 0;
            $wanted -= count($entries);
            foreach ($entries as $entry) {
                $changed = $entry['changed_at'] === null ? '' : '<lastmod>' . (new DateTimeImmutable($entry['changed_at']))->format(DATE_ATOM) . '</lastmod>';
                $xml .= '<url><loc>' . self::escape($base . $entry['path']) . '</loc>' . $changed . '</url>' . "\n";
            }
        }

        return $xml . '</urlset>' . "\n";
    }

    private function index(int $pages): string
    {
        $base = (string) $this->base();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        for ($page = 1; $page <= $pages; $page++) {
            $xml .= '<sitemap><loc>' . self::escape($base . '/sitemap-' . $page . '.xml') . '</loc></sitemap>' . "\n";
        }

        return $xml . '</sitemapindex>' . "\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
