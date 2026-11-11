<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * What the pager (@CattoPHP/ui/data/pagination.html.twig, drawn above and below a data.dataset's results) needs for one
 * page of a list: the page's values, every link it offers (first, previous, numbered, next, last, each keeping the list's
 * filters and page size), the jump and the rows drop-down, and the region htmx swaps.
 */
final class Pager
{
    private function __construct()
    {
    }

    /**
     * @param string $list The list's name: its region is <list>-region and its results <list>-results, and its page and
     *                     size travel as <list>_page and <list>_page_size unless named otherwise.
     * @param array<string,mixed> $filters What the list shown depends on, kept on every link (empty values left off).
     * @param list<int>|null $sizes The list's own page sizes (a grid's whole rows), else the approved table sizes.
     * @return array<string,mixed>
     */
    public static function payload(Pagination $pagination, string $list, string $base, string $label, array $filters = [], ?string $pageParam = null, ?string $sizeParam = null, ?array $sizes = null): array
    {
        $pageParam ??= $list . '_page';
        $sizeParam ??= $list . '_page_size';
        $filters = array_filter($filters, static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== null);
        // The page size travels on every link: it is the list's state like any other, and leaving it off would drop a
        // reader who chose 250 rows back to the default the moment they left the first page.
        $href = static fn (int $page): string => $base . '?' . http_build_query($filters + [$pageParam => $page, $sizeParam => $pagination->pageSize]);
        $pages = [];
        foreach ($pagination->window() as $item) {
            $pages[] = $item + ['href' => $item['is_gap'] ? '' : $href($item['page'])];
        }
        $sizes ??= Pagination::PAGE_SIZES;

        return $pagination->toArray() + [
            'prefix' => $list,
            'page_param' => $pageParam,
            'size_param' => $sizeParam,
            'base' => $base,
            'label' => $label,
            'page_sizes' => $sizes,
            // The rows drop-down stays wherever another size would page the list differently, a list that fits on one
            // page included, so a reader who chose more rows than the list holds can always choose fewer again.
            'has_sizes' => count($sizes) > 1 && $pagination->total > min($sizes),
            'filters' => $filters,
            'query' => $filters === [] ? '' : http_build_query($filters) . '&',
            'region' => $list . '-region',
            'results' => $list . '-results',
            'pages' => $pages,
            'first_href' => $href($pagination->firstPage),
            'previous_href' => $href(max(1, $pagination->page - 1)),
            'next_href' => $href(min($pagination->totalPages, $pagination->page + 1)),
            'last_href' => $href($pagination->lastPage),
        ];
    }
}
