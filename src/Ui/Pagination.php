<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * A list's page: an immutable value made from untrusted request input once the list's total is known. It clamps a page
 * past the end, gives the offset and limit for the query, the "26–50 of 100 · Pg 2 of 4" values and the window of
 * numbered pages the pager shows (Ui\Pager::payload makes the pager's data from it; @CattoPHP/ui/data/pagination.html.twig
 * draws it). Count first, then page, then query with its offset:
 *
 *     $total = $repository->count($filters);
 *     $page = Pagination::create($request->query->get('people_page'), $request->query->get('people_page_size'), $total, 50);
 *     $rows = $repository->rows($page->pageSize, $page->offset, $filters);
 *
 * Only the approved page sizes ever reach a query: anything else falls back to the list's default, never to a size
 * near it, so a tampered address cannot widen a page.
 */
final class Pagination
{
    /**
     * The only page sizes that may ever reach SQL. Any other requested value falls back to
     * the dataset default rather than being clamped to a neighbouring size, so a tampered
     * request cannot widen a page beyond the approved contract.
     *
     * @var list<int>
     */
    public const PAGE_SIZES = [25, 50, 100, 150, 250];

    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * How many pages either side of the current one the numbered control shows.
     *
     * Two either side is what makes `1 ... 47 48 49 50 51 ... 4000` read as a position rather
     * than as a list: the reader sees where they are, one step in each direction, and the two
     * ends of the dataset. A wider window buys nothing once the ellipsis is there.
     */
    public const WINDOW_RADIUS = 2;

    /**
     * Most numbered links the control may render, ellipses included.
     *
     * first + gap + (2 * radius + 1) + gap + last. Below this many pages there is nothing to
     * elide, so every page is listed and no gap appears at all.
     */
    public const MAX_PAGE_LINKS = 2 * self::WINDOW_RADIUS + 5;

    /** Final page actually rendered, after normalisation and over-range clamping. */
    public readonly int $page;

    /** Normalised page originally requested, before clamping. Retained for diagnostics. */
    public readonly int $requestedPage;

    public readonly int $pageSize;
    public readonly int $total;
    public readonly int $totalPages;
    public readonly int $offset;

    /** First row number on this page in human terms; 0 when there are no rows at all. */
    public readonly int $from;

    /** Last row number on this page in human terms; 0 when there are no rows at all. */
    public readonly int $to;

    public readonly bool $hasPrevious;
    public readonly bool $hasNext;

    /**
     * The two ends of the dataset.
     *
     * First is always 1 and last is always totalPages, so an empty dataset reports 1 and 1
     * rather than 1 and 0. They are readable properties rather than something the caller
     * derives, because "go to the last page" is a control the reader operates, and a control
     * whose target is recomputed at each call site is a control that eventually disagrees.
     */
    public readonly int $firstPage;
    public readonly int $lastPage;

    /** True when the requested page exceeded the final page and was clamped back. */
    public readonly bool $wasClamped;

    private function __construct(int $requestedPage, int $pageSize, int $total)
    {
        $this->requestedPage = $requestedPage;
        $this->pageSize = $pageSize;
        $this->total = $total;

        // Total pages never drops below 1, so an empty dataset still renders "Page 1 of 1"
        // rather than the nonsensical "Page 1 of 0".
        $this->totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

        $this->page = min($requestedPage, $this->totalPages);
        $this->wasClamped = $requestedPage > $this->totalPages;

        $this->offset = ($this->page - 1) * $pageSize;

        // An empty dataset reports "Showing 0-0 of 0"; a populated page reports its real span.
        $this->from = $total > 0 ? $this->offset + 1 : 0;
        $this->to = $total > 0 ? min($this->offset + $pageSize, $total) : 0;

        $this->hasPrevious = $this->page > 1;
        $this->hasNext = $this->page < $this->totalPages;

        $this->firstPage = 1;
        $this->lastPage = $this->totalPages;
    }

    /**
     * The adaptive numbered-page window: `1 ... 47 48 49 50 51 ... 4000`.
     *
     * Every item is either a page or a gap, and a gap carries page 0 so a template can branch on
     * one flag rather than on a magic string. The window is shifted rather than truncated near
     * either end, so the control keeps a constant width instead of collapsing to two links on
     * page 1 and growing as the reader moves inwards - a control that changes size under the
     * cursor is one people misclick.
     *
     * Both ends of the dataset are always present, because "go to the last page" of a
     * four-thousand page list is otherwise unreachable in fewer than eight hundred clicks.
     *
     * @return list<array{page:int,is_current:bool,is_gap:bool}>
     */
    public function window(int $radius = self::WINDOW_RADIUS): array
    {
        $radius = max(1, $radius);
        $last = $this->totalPages;

        // Nothing to elide: list every page and emit no gap at all.
        if ($last <= self::MAX_PAGE_LINKS) {
            return array_map(fn(int $page): array => $this->pageItem($page), range(1, $last));
        }

        $start = $this->page - $radius;
        $end = $this->page + $radius;

        // Shift the window back inside the interior rather than clipping it, so it is always
        // the same number of links wide.
        if ($start < 2) {
            $end += 2 - $start;
            $start = 2;
        }
        if ($end > $last - 1) {
            $start -= $end - ($last - 1);
            $end = $last - 1;
        }
        $start = max(2, $start);

        $items = [$this->pageItem(1)];
        if ($start > 2) $items[] = self::gapItem();
        for ($page = $start; $page <= $end; $page++) $items[] = $this->pageItem($page);
        if ($end < $last - 1) $items[] = self::gapItem();
        $items[] = $this->pageItem($last);

        return $items;
    }

    /** @return array{page:int,is_current:bool,is_gap:bool} */
    private function pageItem(int $page): array
    {
        return ['page' => $page, 'is_current' => $page === $this->page, 'is_gap' => false];
    }

    /** @return array{page:int,is_current:bool,is_gap:bool} */
    private static function gapItem(): array
    {
        return ['page' => 0, 'is_current' => false, 'is_gap' => true];
    }

    /**
     * Builds a pagination state from untrusted request values and a known total.
     *
     * @param mixed $page             Raw request page value of any type.
     * @param mixed $pageSize         Raw request page-size value of any type.
     * @param int   $total            Row count from the matching count query.
     * @param int   $defaultPageSize  Dataset default; must itself be an approved page size.
     */
    public static function create(mixed $page, mixed $pageSize, int $total, int $defaultPageSize = self::DEFAULT_PAGE_SIZE): self
    {
        return new self(
            self::normalisePage($page),
            self::normalisePageSize($pageSize, $defaultPageSize),
            max(0, $total)
        );
    }

    /**
     * A page from a list's own sizes instead of the tables' (a grid of cards pages in whole rows, ListView): a size the
     * list does not offer falls back to its first.
     *
     * @param list<int> $sizes
     */
    public static function within(mixed $page, mixed $pageSize, int $total, array $sizes): self
    {
        $sizes = array_values(array_filter($sizes, static fn(int $size): bool => $size >= 1 && $size <= max(self::PAGE_SIZES)));
        $chosen = is_string($pageSize) && preg_match('/^\d+$/', trim($pageSize)) === 1 ? (int) trim($pageSize) : (is_int($pageSize) ? $pageSize : 0);

        return new self(
            self::normalisePage($page),
            in_array($chosen, $sizes, true) ? $chosen : ($sizes[0] ?? self::DEFAULT_PAGE_SIZE),
            max(0, $total)
        );
    }

    /**
     * Builds a fixed-size pagination state for surfaces that deliberately expose no page-size
     * selector, such as the bounded consolidated workspace previews.
     */
    public static function fixed(mixed $page, int $pageSize, int $total): self
    {
        return new self(
            self::normalisePage($page),
            self::isSupportedPageSize($pageSize) ? $pageSize : self::DEFAULT_PAGE_SIZE,
            max(0, $total)
        );
    }

    /**
     * Normalises a requested page to an integer of at least 1. Anything non-numeric, zero,
     * negative or fractional falls back to the first page rather than raising, because a
     * malformed page number in a URL must never break a list screen.
     */
    public static function normalisePage(mixed $page): int
    {
        if (is_int($page)) return max(1, $page);
        if (is_string($page) && preg_match('/^\d+$/', trim($page)) === 1) return max(1, (int) trim($page));

        return 1;
    }

    /**
     * Normalises a requested page size to one of the approved sizes. An unsupported value
     * falls back to the dataset default, and an invalid dataset default falls back to the
     * platform default, so no caller can smuggle an arbitrary LIMIT into SQL.
     */
    public static function normalisePageSize(mixed $pageSize, int $defaultPageSize = self::DEFAULT_PAGE_SIZE): int
    {
        $default = self::isSupportedPageSize($defaultPageSize) ? $defaultPageSize : self::DEFAULT_PAGE_SIZE;

        if (is_int($pageSize) && self::isSupportedPageSize($pageSize)) return $pageSize;
        if (is_string($pageSize) && preg_match('/^\d+$/', trim($pageSize)) === 1) {
            $candidate = (int) trim($pageSize);
            if (self::isSupportedPageSize($candidate)) return $candidate;
        }

        return $default;
    }

    public static function isSupportedPageSize(int $pageSize): bool
    {
        return in_array($pageSize, self::PAGE_SIZES, true);
    }

    /**
     * Flat scalar representation for view binding. Callers prefix these keys per dataset
     * (people_page, companies_page, ...) so several independent lists can coexist on one
     * route without sharing state.
     *
     * @return array<string,int|bool>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'total' => $this->total,
            'total_pages' => $this->totalPages,
            'from' => $this->from,
            'to' => $this->to,
            'has_previous' => $this->hasPrevious,
            'has_next' => $this->hasNext,
            'previous_page' => max(1, $this->page - 1),
            'next_page' => min($this->totalPages, $this->page + 1),
            'first_page' => $this->firstPage,
            'last_page' => $this->lastPage,
            // Whether there is anywhere to navigate to. A single page of results has no first,
            // last, next, previous, numbered page or jump target that is not where the reader
            // already is, so the control renders its summary and nothing else. The row count is
            // still worth stating; a row of disabled buttons underneath it is not.
            'has_pages' => $this->totalPages > 1,
        ];
    }

    /**
     * Same scalars, keyed for one named dataset.
     *
     * @return array<string,int|bool>
     */
    public function toPrefixedArray(string $prefix): array
    {
        $prefixed = [];
        foreach ($this->toArray() as $key => $value) $prefixed[$prefix . '_' . $key] = $value;

        return $prefixed;
    }

    /** @return list<int> */
    public static function pageSizes(): array
    {
        return self::PAGE_SIZES;
    }
}
