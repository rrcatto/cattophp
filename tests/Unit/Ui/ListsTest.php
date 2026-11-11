<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\UiHarness;
use CattoPHP\Ui\ListView;
use CattoPHP\Ui\Pager;
use CattoPHP\Ui\Pagination;
use PHPUnit\Framework\TestCase;

/** A list's pager, its view as a table or a grid, and its sorting. */
final class ListsTest extends TestCase
{
    public function testThePagerKeepsTheListsFiltersAndSizeOnEveryLink(): void
    {
        $pager = Pager::payload(Pagination::create(3, 25, 260), 'people', '/people', 'People', ['people_q' => 'Ada', 'empty' => '', 'zero' => 0]);
        self::assertSame(['people', 'people_page', 'people_page_size', 'people-region', 'people-results'], [$pager['prefix'], $pager['page_param'], $pager['size_param'], $pager['region'], $pager['results']]);
        self::assertSame(['people_q' => 'Ada'], $pager['filters'], 'Empty values are left off.');
        self::assertSame('people_q=Ada&', $pager['query']);
        self::assertSame('/people?people_q=Ada&people_page=1&people_page_size=25', $pager['first_href']);
        self::assertSame('/people?people_q=Ada&people_page=2&people_page_size=25', $pager['previous_href']);
        self::assertSame('/people?people_q=Ada&people_page=4&people_page_size=25', $pager['next_href']);
        self::assertSame('/people?people_q=Ada&people_page=11&people_page_size=25', $pager['last_href']);
        self::assertSame(Pagination::PAGE_SIZES, $pager['page_sizes']);
        self::assertTrue($pager['has_sizes']);
        foreach ($pager['pages'] as $item) {
            self::assertSame($item['is_gap'] ? '' : '/people?people_q=Ada&people_page=' . $item['page'] . '&people_page_size=25', $item['href']);
        }
        $grid = Pager::payload(Pagination::within(1, 30, 40, [30, 60, 90]), 'people', '/people', 'People', [], null, null, [30, 60, 90]);
        self::assertSame([30, 60, 90], $grid['page_sizes']);
        self::assertTrue($grid['has_sizes'], 'More people than the smallest size: another size would page them differently.');
        self::assertFalse(Pager::payload(Pagination::create(1, 25, 20), 'people', '/people', 'People')['has_sizes'], 'Fewer than the smallest size: nothing to choose.');
        self::assertFalse(Pager::payload(Pagination::within(1, 24, 300, [24]), 'cards', '/cards', 'Cards', [], null, null, [24])['has_sizes'], 'One size: nothing to choose.');
        self::assertSame('/cards?q=x&p=2&s=24', Pager::payload(Pagination::within(1, 24, 300, [24]), 'cards', '/cards', 'Cards', ['q' => 'x'], 'p', 's', [24])['next_href']);
    }

    public function testTheAddressWinsThenTheRememberedChoiceThenTheDefault(): void
    {
        self::assertSame('list', ListView::chosen('people', [], []));
        self::assertSame('grid', ListView::chosen('people', [], [], ListView::GRID), 'A list that opens as cards.');
        self::assertSame('grid', ListView::chosen('people', [], ['cl_view_people' => 'grid']), 'Remembered.');
        self::assertSame('list', ListView::chosen('people', ['people_view' => 'list'], ['cl_view_people' => 'grid']), 'Asked for now.');
        self::assertSame('list', ListView::chosen('people', ['people_view' => 'tiles'], ['cl_view_people' => '<x>']), 'Nothing else is a view.');
        self::assertSame('list', ListView::chosen('people', ['people_view' => ['grid']], []), 'Nor an array.');
        self::assertSame('list', ListView::chosen('items', [], ['cl_view_people' => 'grid']), 'Each list remembers its own.');
        self::assertSame([30, 60, 90], ListView::pageSizes('grid', [30, 60, 90]));
        self::assertSame(Pagination::PAGE_SIZES, ListView::pageSizes('list', [30, 60, 90]), 'A table keeps the tables\' sizes.');
        self::assertSame(Pagination::PAGE_SIZES, ListView::pageSizes('grid', null), 'A list without a grid of its own sizes.');
    }

    public function testTheSwitchKeepsTheSearchAndSortAndIsPlainLinks(): void
    {
        $switch = ListView::switcher('people', 'grid', '/people', ['people_q' => 'Ada', 'people_sort' => 'name', 'empty' => ''], 5);
        self::assertSame(['people', 'grid', 5], [$switch['name'], $switch['view'], $switch['columns']]);
        self::assertSame('/people?people_q=Ada&people_sort=name&people_view=list', $switch['list_href']);
        self::assertSame('/people?people_q=Ada&people_sort=name&people_view=grid', $switch['grid_href']);
        $html = UiHarness::source("{% include '@CattoPHP/ui/data/view-switch.html.twig' %}", ['vs' => $switch]);
        $xpath = UiHarness::xpath($html);
        self::assertSame(1, $xpath->query('//div[@class="cl-view-switch" and @role="group"]/a[@href="/people?people_q=Ada&people_sort=name&people_view=list" and @aria-label="Show as a list" and @hx-target="#people-view"]')->length);
        self::assertSame(1, $xpath->query('//a[@aria-label="Grid, shown now" and contains(@class,"cl-ui-action--primary")]')->length);
    }

    public function testAGridIsSortedByTheTablesSortsAndAHeadingSortsTheTable(): void
    {
        $headers = [['key' => 'name', 'label' => 'Name', 'sortable' => true, 'active' => true, 'direction' => 'desc'], ['key' => 'email', 'label' => 'Email', 'sortable' => false, 'active' => false, 'direction' => 'asc']];
        $pager = Pager::payload(Pagination::create(2, 50, 300), 'people', '/people', 'People', ['people_q' => 'Ada', 'people_sort' => 'name']);
        $html = UiHarness::source("{% include '@CattoPHP/ui/data/grid-sort.html.twig' %}", ['gs_name' => 'people', 'gs_headers' => $headers, 'gs_pager' => $pager]);
        $xpath = UiHarness::xpath($html);
        self::assertSame(1, $xpath->query('//form[@class="cl-grid-sort" and @action="/people"]/input[@name="people_q" and @value="Ada"]')->length, 'The search is kept.');
        self::assertSame(0, $xpath->query('//input[@name="people_sort" or @name="people_page"]')->length, 'The sort is chosen here, and a new sort returns to page one.');
        self::assertSame(1, $xpath->query('//input[@name="people_page_size" and @value="50"]')->length);
        self::assertSame(['name'], array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), $xpath->elements('//select[@name="people_sort"]/option')), 'Only the sortable headings.');
        self::assertSame(1, $xpath->query('//select[@name="people_dir"]/option[@value="desc" and @selected]')->length);
        self::assertSame('', trim(UiHarness::source("{% include '@CattoPHP/ui/data/grid-sort.html.twig' %}", ['gs_name' => 'people', 'gs_headers' => [$headers[1]], 'gs_pager' => $pager])), 'Nothing to sort by: no form.');

        $heading = UiHarness::source("<table><tr>{% include '@CattoPHP/ui/data/sortable-header.html.twig' %}</tr></table>", ['sh' => ['label' => 'Name', 'href' => '/people?people_sort=name&people_dir=asc', 'sortable' => true, 'active' => true, 'direction' => 'desc', 'width' => 30, 'wide' => true], 'sort_region' => 'people-region', 'sort_results' => 'people-results']);
        $xpath = UiHarness::xpath($heading);
        self::assertSame(1, $xpath->query('//th[contains(@class,"is-sorted") and @aria-sort="descending" and @data-phone="hide"]/a[@hx-target="#people-region" and @hx-select="#people-results"]')->length);
        self::assertStringContainsString('▼', $heading);
        $plain = UiHarness::source("<table><tr>{% include '@CattoPHP/ui/data/sortable-header.html.twig' %}</tr></table>", ['sh' => ['label' => 'Email', 'sortable' => false, 'width' => 0]]);
        self::assertSame(0, UiHarness::xpath($plain)->query('//th/a')->length);
    }
}
