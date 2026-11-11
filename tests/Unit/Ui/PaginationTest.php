<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Ui\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testAllowedPageSizesAreExactlyTheApprovedContract(): void
    {
        self::assertSame([25, 50, 100, 150, 250], Pagination::PAGE_SIZES);
        self::assertSame(25, Pagination::DEFAULT_PAGE_SIZE);
    }

    public function testDatasetDefaultIsAppliedWhenNoPageSizeRequested(): void
    {
        self::assertSame(50, Pagination::create(1, null, 500, 50)->pageSize);
        self::assertSame(100, Pagination::create(1, null, 500, 100)->pageSize);
        self::assertSame(25, Pagination::create(1, null, 500)->pageSize);
    }

    public function testUnsupportedPageSizeFallsBackToTheDatasetDefaultRatherThanReachingSql(): void
    {
        // 10 000 is the case that matters: a tampered request must not widen the page.
        self::assertSame(50, Pagination::create(1, 10000, 500, 50)->pageSize);
        self::assertSame(50, Pagination::create(1, 30, 500, 50)->pageSize);
        self::assertSame(50, Pagination::create(1, 0, 500, 50)->pageSize);
        self::assertSame(50, Pagination::create(1, -25, 500, 50)->pageSize);
        self::assertSame(50, Pagination::create(1, 'all', 500, 50)->pageSize);
        self::assertSame(50, Pagination::create(1, '50; DROP TABLE users', 500, 50)->pageSize);
    }

    public function testSupportedPageSizeIsAcceptedFromStringRequestInput(): void
    {
        foreach (Pagination::PAGE_SIZES as $size) {
            self::assertSame($size, Pagination::create(1, (string) $size, 5000, 25)->pageSize);
        }
    }

    public function testAnInvalidDatasetDefaultFallsBackToThePlatformDefault(): void
    {
        self::assertSame(25, Pagination::create(1, null, 500, 37)->pageSize);
    }

    public function testMalformedPageValuesNormaliseToTheFirstPage(): void
    {
        foreach ([0, -1, -999, 'abc', '', null, '1.5', [], 2.7, '-4'] as $page) {
            self::assertSame(1, Pagination::create($page, 25, 500)->page, 'Failed for: ' . var_export($page, true));
        }
    }

    public function testOffsetIsDerivedFromTheNormalisedPageAndSize(): void
    {
        self::assertSame(0, Pagination::create(1, 25, 500)->offset);
        self::assertSame(25, Pagination::create(2, 25, 500)->offset);
        self::assertSame(100, Pagination::create(3, 50, 500)->offset);
        self::assertSame(500, Pagination::create(6, 100, 5000)->offset);
    }

    public function testTotalPagesIsCalculatedWithAPartialFinalPage(): void
    {
        self::assertSame(1, Pagination::create(1, 25, 25)->totalPages);
        self::assertSame(2, Pagination::create(1, 25, 26)->totalPages);
        self::assertSame(50, Pagination::create(1, 25, 1247)->totalPages);
        self::assertSame(25, Pagination::create(1, 50, 1247)->totalPages);
    }

    public function testZeroRowsRendersPageOneOfOneAndAZeroSpan(): void
    {
        $pagination = Pagination::create(1, 25, 0);

        self::assertSame(1, $pagination->page);
        self::assertSame(1, $pagination->totalPages);
        self::assertSame(0, $pagination->total);
        self::assertSame(0, $pagination->from);
        self::assertSame(0, $pagination->to);
        self::assertSame(0, $pagination->offset);
        self::assertFalse($pagination->hasPrevious);
        self::assertFalse($pagination->hasNext);
    }

    public function testOverRangePageClampsToTheFinalValidPageAndExposesTheClampedPage(): void
    {
        // 1 247 rows at 50 per page is 25 pages; page 700 must render page 25, never "Page 700 of 25".
        $pagination = Pagination::create(700, 50, 1247);

        self::assertSame(25, $pagination->page);
        self::assertSame(700, $pagination->requestedPage);
        self::assertTrue($pagination->wasClamped);
        self::assertSame(1200, $pagination->offset);
        self::assertFalse($pagination->hasNext);
        self::assertTrue($pagination->hasPrevious);
    }

    public function testOverRangePageOnAnEmptyDatasetClampsToPageOne(): void
    {
        $pagination = Pagination::create(9, 25, 0);

        self::assertSame(1, $pagination->page);
        self::assertTrue($pagination->wasClamped);
        self::assertSame(0, $pagination->offset);
    }

    public function testAPageWithinRangeIsNotReportedAsClamped(): void
    {
        self::assertFalse(Pagination::create(25, 50, 1247)->wasClamped);
        self::assertFalse(Pagination::create(1, 25, 0)->wasClamped);
    }

    public function testFromAndToDescribeTheRealRowSpan(): void
    {
        $second = Pagination::create(2, 50, 1247);
        self::assertSame(51, $second->from);
        self::assertSame(100, $second->to);

        // The final page is partial: 1 247 rows at 50 per page leaves 47 rows on page 25.
        $last = Pagination::create(25, 50, 1247);
        self::assertSame(1201, $last->from);
        self::assertSame(1247, $last->to);

        $single = Pagination::create(1, 25, 1);
        self::assertSame(1, $single->from);
        self::assertSame(1, $single->to);
    }

    public function testPreviousAndNextFlagsAndTargetsAreCorrect(): void
    {
        $first = Pagination::create(1, 25, 100);
        self::assertFalse($first->hasPrevious);
        self::assertTrue($first->hasNext);

        $middle = Pagination::create(2, 25, 100);
        self::assertTrue($middle->hasPrevious);
        self::assertTrue($middle->hasNext);

        $last = Pagination::create(4, 25, 100);
        self::assertTrue($last->hasPrevious);
        self::assertFalse($last->hasNext);

        $only = Pagination::create(1, 25, 10);
        self::assertFalse($only->hasPrevious);
        self::assertFalse($only->hasNext);
    }

    public function testPreviousAndNextTargetsNeverLeaveTheValidRange(): void
    {
        $first = Pagination::create(1, 25, 100)->toArray();
        self::assertSame(1, $first['previous_page']);
        self::assertSame(2, $first['next_page']);

        $last = Pagination::create(4, 25, 100)->toArray();
        self::assertSame(3, $last['previous_page']);
        self::assertSame(4, $last['next_page']);

        $empty = Pagination::create(1, 25, 0)->toArray();
        self::assertSame(1, $empty['previous_page']);
        self::assertSame(1, $empty['next_page']);
    }

    public function testFixedPaginationIgnoresRequestedPageSize(): void
    {
        $preview = Pagination::fixed(1, 25, 400);

        self::assertSame(25, $preview->pageSize);
        self::assertSame(16, $preview->totalPages);
    }

    public function testPrefixedArrayKeepsIndependentDatasetsSeparate(): void
    {
        $people = Pagination::create(2, 50, 1247)->toPrefixedArray('people');
        $requests = Pagination::create(1, 25, 3)->toPrefixedArray('requests');

        self::assertSame(2, $people['people_page']);
        self::assertSame(1247, $people['people_total']);
        self::assertSame(25, $people['people_total_pages']);
        self::assertSame(1, $requests['requests_page']);
        self::assertSame(3, $requests['requests_total']);
        self::assertSame(1, $requests['requests_total_pages']);

        self::assertSame([], array_intersect_key($people, $requests));
    }

    public function testToArrayExposesTheCompleteDisplayContract(): void
    {
        self::assertSame(
            ['page', 'page_size', 'total', 'total_pages', 'from', 'to', 'has_previous', 'has_next',
                'previous_page', 'next_page', 'first_page', 'last_page', 'has_pages'],
            array_keys(Pagination::create(1, 25, 0)->toArray())
        );
    }

    /**
     * A single page has nowhere to navigate to, so the control renders its summary alone.
     *
     * The boundary is one page rather than one page size: 40 records with 50 to a page is one page
     * and needs no navigation, and 40 records with 25 to a page is two and does.
     */
    public function testASinglePageOffersNoNavigation(): void
    {
        self::assertFalse(Pagination::create(1, 25, 0)->toArray()['has_pages'], 'An empty list has nowhere to go.');
        self::assertFalse(Pagination::create(1, 25, 25)->toArray()['has_pages'], 'A full single page has nowhere to go.');
        self::assertFalse(Pagination::create(1, 50, 40)->toArray()['has_pages'], 'Forty rows at fifty a page is one page.');
        self::assertTrue(Pagination::create(1, 25, 26)->toArray()['has_pages'], 'One row past the page size is two pages.');
        self::assertTrue(Pagination::create(1, 25, 40)->toArray()['has_pages'], 'Forty rows at twenty-five a page is two pages.');
    }

    /** The two ends are always reachable, and an empty dataset still has a first and a last page. */
    public function testFirstAndLastPageAreTheEndsOfTheDataset(): void
    {
        $empty = Pagination::create(1, 25, 0);
        self::assertSame(1, $empty->firstPage);
        self::assertSame(1, $empty->lastPage);

        $deep = Pagination::create(49, 25, 100000);
        self::assertSame(1, $deep->firstPage);
        self::assertSame(4000, $deep->lastPage);
    }

    /**
     * Renders a window the way the control does, so an expectation reads as the screen reads.
     *
     * @return string
     */
    private static function windowAsText(Pagination $pagination): string
    {
        $parts = [];
        foreach ($pagination->window() as $item) {
            if ($item['is_gap']) {
                $parts[] = '...';
                continue;
            }
            $parts[] = $item['is_current'] ? '[' . $item['page'] . ']' : (string) $item['page'];
        }

        return implode(' ', $parts);
    }

    public function testTheWindowShowsBothEndsTheCurrentPageAndTwoEitherSide(): void
    {
        self::assertSame(
            '1 ... 47 48 [49] 50 51 ... 4000',
            self::windowAsText(Pagination::create(49, 25, 100000))
        );
    }

    /**
     * A short dataset elides nothing at all. A control that renders an ellipsis over nine pages
     * is claiming pages exist that do not.
     */
    public function testAShortDatasetListsEveryPageWithNoGap(): void
    {
        self::assertSame('1 2 3 4 [5] 6 7 8 9', self::windowAsText(Pagination::create(5, 25, 9 * 25)));
        self::assertSame('[1]', self::windowAsText(Pagination::create(1, 25, 3)));
        self::assertSame('[1]', self::windowAsText(Pagination::create(1, 25, 0)));
    }

    /**
     * The window is shifted back inside the dataset rather than clipped at either end, so the
     * same number of pages is always offered. A control that grows from four links to nine as
     * the reader pages inwards moves every other link out from under the pointer.
     *
     * The count asserted is of pages, not of items: an ellipsis is not a link, and near either
     * end one of the two gaps has nothing left to stand for and is correctly absent.
     */
    public function testTheWindowAlwaysOffersTheSameNumberOfPages(): void
    {
        $counts = [];
        foreach ([1, 2, 3, 49, 3998, 3999, 4000] as $page) {
            $window = Pagination::create($page, 25, 100000)->window();
            $counts[] = count(array_filter($window, static fn(array $item): bool => !$item['is_gap']));
            self::assertLessThanOrEqual(Pagination::MAX_PAGE_LINKS, count($window));
        }

        self::assertSame([Pagination::MAX_PAGE_LINKS - 2], array_values(array_unique($counts)));
        self::assertSame('[1] 2 3 4 5 6 ... 4000', self::windowAsText(Pagination::create(1, 25, 100000)));
        self::assertSame('1 ... 3995 3996 3997 3998 3999 [4000]', self::windowAsText(Pagination::create(4000, 25, 100000)));
    }

    /** Exactly one item is ever the current page, and a gap is never one. */
    public function testExactlyOneWindowItemIsCurrentAndNoGapIsClickable(): void
    {
        foreach ([1, 2, 500, 3999, 4000] as $page) {
            $window = Pagination::create($page, 25, 100000)->window();
            $current = array_values(array_filter($window, static fn(array $item): bool => $item['is_current']));

            self::assertCount(1, $current, 'Page ' . $page . ' must mark exactly one item current.');
            self::assertSame($page, $current[0]['page']);
            foreach ($window as $item) {
                if ($item['is_gap']) {
                    self::assertSame(0, $item['page'], 'A gap must carry no page to link to.');
                    self::assertFalse($item['is_current']);
                }
            }
        }
    }

    /** An over-range request is clamped before the window is built, so it cannot point past the end. */
    public function testTheWindowOfAnOverRangePageIsTheWindowOfTheLastPage(): void
    {
        self::assertSame(
            self::windowAsText(Pagination::create(4000, 25, 100000)),
            self::windowAsText(Pagination::create(999999, 25, 100000))
        );
    }
}
