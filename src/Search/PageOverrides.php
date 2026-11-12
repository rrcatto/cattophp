<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * An administrator's own words for search engines: a title and a description for each public page (PublicPages), kept
 * in cattophp_search_pages; empty means the ones the site makes from the page. Plain text only, one line, as a search
 * result shows it: tags are removed and spaces run together. A title may be up to TITLE_LIMIT characters and a
 * description DESCRIPTION_LIMIT; a search result shows about TITLE_SHOWN and DESCRIPTION_SHOWN, which a form's previews
 * mark, never refuse. A project with words of its own for its own things (a product's search title) checks them with
 * checked(). Every change is in the activity log.
 */
final class PageOverrides
{
    public const TITLE_LIMIT = 120;
    public const DESCRIPTION_LIMIT = 320;
    /** About as much as a search result shows (Google cuts by width, not by count). */
    public const TITLE_SHOWN = 60;
    public const DESCRIPTION_SHOWN = 160;

    public function __construct(
        private readonly Connection $connection,
        private readonly PublicPages $pages,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /** @return array<string,array{title:string,description:string}> every public page's own words, empty where it has none */
    public function pages(): array
    {
        $saved = [];
        foreach ($this->connection->fetchAllAssociative('SELECT page_key, title, description FROM cattophp_search_pages') as $row) {
            $saved[(string) $row['page_key']] = ['title' => PlainText::plain((string) $row['title']), 'description' => PlainText::plain((string) $row['description'])];
        }
        $pages = [];
        foreach (array_keys($this->pages->all()) as $key) {
            $pages[$key] = $saved[$key] ?? ['title' => '', 'description' => ''];
        }

        return $pages;
    }

    /** @return array{title:string,description:string} */
    public function page(string $key): array
    {
        return $this->pages()[$key] ?? ['title' => '', 'description' => ''];
    }

    /**
     * Saves every public page's own words from the form (pages[key][title], pages[key][description]), each checked first;
     * nothing is saved when one is too long.
     *
     * @param array<string,mixed> $input
     * @return list<string> the names of the pages that changed
     */
    public function savePages(array $input, ?Actor $actor = null): array
    {
        $before = $this->pages();
        $after = [];
        $changed = [];
        foreach ($this->pages->all() as $key => $page) {
            $typed = is_array($input['pages'] ?? null) && is_array($input['pages'][$key] ?? null) ? $input['pages'][$key] : [];
            $after[$key] = self::checked($typed, $page->label);
            if ($after[$key] !== $before[$key]) {
                $changed[$key] = $page->label;
            }
        }
        if ($changed === []) {
            return [];
        }
        $this->connection->transactional(function () use ($changed, $after, $actor): void {
            foreach (array_keys($changed) as $key) {
                $own = $after[$key];
                if ($own['title'] === '' && $own['description'] === '') {
                    $this->connection->executeStatement('DELETE FROM cattophp_search_pages WHERE page_key = :key', ['key' => $key]);
                    continue;
                }
                $this->connection->executeStatement(
                    'INSERT INTO cattophp_search_pages (page_key, title, description, updated_by) VALUES (:key, :title, :description, :by)
                     ON CONFLICT (page_key) DO UPDATE SET title = EXCLUDED.title, description = EXCLUDED.description, updated_at = NOW(), updated_by = EXCLUDED.updated_by',
                    ['key' => $key, 'title' => $own['title'], 'description' => $own['description'], 'by' => $actor->id ?? $this->activity?->signedIn()?->id]
                );
            }
        });
        $this->activity?->record('search.pages_updated', ['pages' => array_values($changed)], actor: $actor);

        return array_values($changed);
    }

    /**
     * A title and a description as typed, made plain and checked.
     *
     * @param array<string,mixed> $input title, description
     * @return array{title:string,description:string}
     * @throws InvalidArgumentException in plain words when one is too long
     */
    public static function checked(array $input, string $what): array
    {
        $title = PlainText::plain(is_string($input['title'] ?? null) ? $input['title'] : '');
        $description = PlainText::plain(is_string($input['description'] ?? null) ? $input['description'] : '');
        if (mb_strlen($title) > self::TITLE_LIMIT) {
            throw new InvalidArgumentException($what . '\'s search title can be at most ' . self::TITLE_LIMIT . ' characters. Search results show about ' . self::TITLE_SHOWN . '.');
        }
        if (mb_strlen($description) > self::DESCRIPTION_LIMIT) {
            throw new InvalidArgumentException($what . '\'s search description can be at most ' . self::DESCRIPTION_LIMIT . ' characters. Search results show about ' . self::DESCRIPTION_SHOWN . '.');
        }

        return ['title' => $title, 'description' => $description];
    }
}
