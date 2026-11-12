<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use CattoPHP\Installation\Installation;
use CattoPHP\Settings\EnvironmentValues;

/**
 * What a public page's head tells search engines, AI assistants and the sites it is shared on, beyond what the page sets
 * itself: an administrator's own title and description for a public page (PageOverrides), else its own description
 * (PublicPages); its canonical address, the path with its page number beyond the first and nothing else, so a reader's
 * view, page size and sort are never indexed apart (Google: give each page of a list its own canonical); a search's
 * results asked not to be indexed; the Open Graph set and X's card, with the site's own picture (DefaultSharePicture)
 * when the page has none; and ", page N" after the title of a later page of a list, so no two pages share a title. Every
 * address is absolute, from the site's public address; a site without one gets its description and nothing else.
 *
 * A head is an array the project's template prints: description, title, robots, canonical, og (property => content),
 * twitter_card, and whatever the project adds.
 */
final class PageHead
{
    public function __construct(
        private readonly PublicPages $pages,
        private readonly Installation $installation,
        private readonly EnvironmentValues $environment,
        private readonly ?PageOverrides $overrides = null,
        private readonly ?DefaultSharePicture $picture = null,
    ) {
    }

    /** The site's public address without its closing slash, or null when it is not a web address. */
    public function base(): ?string
    {
        return StructuredData::base($this->installation->address());
    }

    /** The pages' language, from APP_LOCALE (en_ZA is en-ZA). */
    public function language(): string
    {
        return StructuredData::language($this->environment->get('APP_LOCALE'));
    }

    /**
     * What a public page's head needs and it did not set.
     *
     * @param string $page the page's name, a public page's key when it is one
     * @param array<string,mixed> $meta the page's own head
     * @param array<string,mixed> $query the request's query
     * @param string $title the document's title as the page gives it
     * @return array<string,mixed> the head, with title when the document's title changes
     */
    public function complete(string $page, array $meta, string $path, array $query, string $title): array
    {
        $key = $this->pages->key($page, $path);
        $own = $key === null ? ['title' => '', 'description' => ''] : ($this->overrides?->page($key) ?? ['title' => '', 'description' => '']);
        if ($own['description'] !== '') {
            $meta['description'] = $own['description'];
        } elseif ($key !== null && trim(self::text($meta['description'] ?? '')) === '') {
            $meta['description'] = $this->pages->find($key)->description ?? '';
        }
        $named = trim(self::text($meta['title'] ?? ''));
        if ($own['title'] !== '') {
            $named = $own['title'];
        }
        $number = max(1, (int) self::text($query['page'] ?? '1'));
        $searched = false;
        foreach ($query as $name => $value) {
            if (is_string($value) && trim($value) !== '' && preg_match('/(^|_)q$/', (string) $name) === 1) {
                $searched = true;
            }
        }
        if ($searched && trim(self::text($meta['robots'] ?? '')) === '') {
            $meta['robots'] = 'noindex, follow';
        }
        $shown = ($named !== '' ? $named : $title) . ($number > 1 ? ', page ' . $number : '');
        if ($shown !== $title) {
            $meta['title'] = $shown;
        }
        $base = $this->base();
        // A page that asks not to be indexed (a search's results, a preview) claims no address and no share card.
        if ($base === null || str_contains(self::text($meta['robots'] ?? ''), 'noindex')) {
            return $meta;
        }
        if (trim(self::text($meta['canonical'] ?? '')) === '') {
            $meta['canonical'] = $base . ($path === '' ? '/' : $path) . ($number > 1 ? '?page=' . $number : '');
        }
        $og = is_array($meta['og'] ?? null) ? $meta['og'] : [];
        if ($og === []) {
            $shared = $this->shared(self::text($meta['canonical']), $named !== '' ? $named : $title, self::text($meta['description'] ?? ''), $this->pictureUrl($base), $this->picture?->alt() ?? '', $this->picture?->type() ?? 'image/jpeg');
            $meta['og'] = $shared['og'];
            $meta['twitter_card'] = $shared['twitter_card'];
        } elseif ($named !== '' && isset($og['title'])) {
            $og['title'] = $named;
            $meta['og'] = $og;
        }
        if ($own['description'] !== '' && isset($meta['og']['description'])) {
            $meta['og']['description'] = $own['description'];
        }

        return $meta;
    }

    /**
     * The canonical address and the Open Graph set, with X's large card when there is a picture (at 1200 by 630).
     *
     * @return array{description:string,canonical:string,og:array<string,string>,twitter_card:string}
     */
    public function shared(string $url, string $title, string $description, string $picture, string $alt, string $type = 'image/png'): array
    {
        $og = ['type' => 'website', 'title' => $title, 'description' => $description, 'url' => $url, 'locale' => str_replace('-', '_', $this->language())];
        if ($picture !== '') {
            $og += ['image' => $picture, 'image:type' => $type, 'image:width' => (string) SharePictures::WIDTH, 'image:height' => (string) SharePictures::HEIGHT, 'image:alt' => $alt];
        }

        return ['description' => $description, 'canonical' => $url, 'og' => $og, 'twitter_card' => $picture !== '' ? 'summary_large_image' : 'summary'];
    }

    /**
     * What a page says in a search result and a shared link now, for the previews beside an administrator's fields: their
     * words where given, else the page's own; its address and picture (the site's own when it has none).
     *
     * @return array{url:string,title:string,description:string,default_title:string,default_description:string,picture:string}
     */
    public function preview(string $path, string $defaultTitle, string $defaultDescription, string $title, string $description, string $picture): array
    {
        $base = $this->base() ?? '';
        $title = PlainText::plain($title);
        $description = PlainText::plain($description);
        if ($picture === '' && $base !== '') {
            $picture = $this->pictureUrl($base);
        }

        return ['url' => $base . $path, 'title' => $title !== '' ? $title : $defaultTitle, 'description' => $description !== '' ? $description : $defaultDescription,
            'default_title' => $defaultTitle, 'default_description' => $defaultDescription, 'picture' => $picture];
    }

    /**
     * A public page's preview.
     *
     * @return array{url:string,title:string,description:string,default_title:string,default_description:string,picture:string}
     */
    public function pagePreview(string $key): array
    {
        $page = $this->pages->find($key);
        $own = $this->overrides?->page($key) ?? ['title' => '', 'description' => ''];

        return $this->preview($page->path ?? '/', $page->title ?? '', $page->description ?? '', $own['title'], $own['description'], '');
    }

    /** The site's own picture's address, or '' without one. */
    public function pictureUrl(string $base): string
    {
        return $this->picture?->url($base) ?? '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
