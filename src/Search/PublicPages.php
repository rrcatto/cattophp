<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/** Every public page the project's providers list (PublicPageProvider), by key, in their order. */
final class PublicPages
{
    /** @var array<string,PublicPage>|null */
    private ?array $pages = null;

    /** @param iterable<PublicPageProvider> $providers */
    public function __construct(private readonly iterable $providers = [])
    {
    }

    /** @return array<string,PublicPage> */
    public function all(): array
    {
        if ($this->pages === null) {
            $this->pages = [];
            foreach ($this->providers as $provider) {
                foreach ($provider->pages() as $page) {
                    $this->pages[$page->key] ??= $page;
                }
            }
        }

        return $this->pages;
    }

    public function find(string $key): ?PublicPage
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Which public page a page drawn at a path is: its key when the page's name is a public page's key and the path is
     * that page's own address (a page that also draws other addresses, such as a list's categories, is only that page at
     * its own), else null.
     */
    public function key(string $page, string $path): ?string
    {
        $found = $this->find($page);

        return $found !== null && rtrim($path, '/') === rtrim($found->path, '/') ? $page : null;
    }
}
