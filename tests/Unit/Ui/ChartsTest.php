<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\HtmlQuery;
use CattoPHP\Tests\Support\UiHarness;
use CattoPHP\Ui\Contract\TemplateContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The column chart and the bar list: every figure in words for a screen reader and without colour, lengths as classes
 * (never a style), links only to this site or the web, an empty state, and what a phone needs.
 */
final class ChartsTest extends TestCase
{
    /** @return list<array{label:string,value:int,words:string}> */
    private static function days(int $count): array
    {
        $days = [];
        for ($day = 1; $day <= $count; $day++) {
            $days[] = ['label' => $day . ' Nov', 'value' => $day % 4 * 3, 'words' => ($day % 4 * 3) . ' clicks'];
        }

        return $days;
    }

    private static function nodes(HtmlQuery $page, string $expression): int
    {
        return $page->query($expression)->length;
    }

    /** @return list<string> each node's words, spaces run together */
    private static function texts(HtmlQuery $page, string $expression): array
    {
        $texts = [];
        foreach ($page->query($expression) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
    }

    private static function text(HtmlQuery $page, string $expression): string
    {
        return self::texts($page, $expression)[0] ?? '';
    }

    public function testAColumnChartSaysEachColumnsFigureAndSizesItByAClass(): void
    {
        $html = UiHarness::render('data.column-chart', ['label' => 'Clicks by day, <November>', 'columns' => [
            ['label' => '1 Nov', 'value' => 4, 'words' => '4 clicks', 'href' => '/marketing?day=2026-11-01'],
            ['label' => '2 Nov', 'value' => 0, 'words' => '0 clicks'],
            ['label' => '3 Nov', 'value' => 8, 'words' => '8 clicks'],
        ]]);
        $page = UiHarness::xpath($html);
        self::assertSame(1, self::nodes($page, '//figure[@class="cl-ui-column-chart"][@aria-label="Clicks by day, <November>"]'));
        self::assertStringContainsString('Clicks by day, &lt;November&gt;', $html, 'The chart\'s name is escaped.');
        self::assertSame(3, self::nodes($page, '//ol[@class="cl-ui-column-chart-columns"]/li'));
        // A column with href opens what it adds up, named for a screen reader; one without says its figure in hidden words.
        self::assertSame(1, self::nodes($page, '//a[@class="cl-ui-column-chart-bar cl-share cl-share-50"][@href="/marketing?day=2026-11-01"][@aria-label="1 Nov: 4 clicks"]'));
        self::assertSame('2 Nov: 0 clicks', self::text($page, '//span[contains(@class,"cl-share-0")]/span[@class="visually-hidden"]'));
        self::assertSame(1, self::nodes($page, '//span[@class="cl-ui-column-chart-bar cl-share cl-share-100"][@title="3 Nov: 8 clicks"]'), 'The tallest reaches the top.');
        // The scale's top is the tallest column's words, and only for the eye.
        self::assertSame('8 clicks0', self::text($page, '//div[@class="cl-ui-column-chart-scale"][@aria-hidden="true"]'));
        self::assertStringNotContainsString('style=', $html, 'No inline style under the strict policy.');
    }

    public function testTheSameFiguresFollowAsATableOpenedByHand(): void
    {
        $html = UiHarness::render('data.column-chart', ['label' => 'Clicks by day', 'label_heading' => 'Day', 'value_heading' => 'Clicks', 'columns' => [
            ['label' => '1 Nov', 'value' => 1200, 'href' => '/marketing?day=2026-11-01'],
            ['label' => '2 Nov', 'value' => 2.5],
        ]]);
        $page = UiHarness::xpath($html);
        self::assertSame('Show the figures', self::text($page, '//details/summary'));
        self::assertSame('Clicks by day', self::text($page, '//details//table/caption'));
        self::assertSame(['Day', 'Clicks'], self::texts($page, '//details//table/thead//th'));
        self::assertSame(['1 Nov', '2 Nov'], self::texts($page, '//details//table/tbody//th[@scope="row"]'));
        self::assertSame(['1,200', '2.5'], self::texts($page, '//details//table/tbody//td'), 'A figure given no words is written as a number.');
        self::assertSame(1, self::nodes($page, '//details//table//th/a[@href="/marketing?day=2026-11-01"]'));
        self::assertSame(0, self::nodes(UiHarness::xpath(UiHarness::render('data.column-chart', ['label' => 'Clicks', 'columns' => [['label' => '1 Nov', 'value' => 1]], 'table' => false])), '//table'));
        self::assertSame([], TemplateContract::duplicates(TemplateContract::cattophpTemplates()), 'The chart draws its table with data.table.');
    }

    public function testAboutSixColumnsAreNamedBeneathAndHalfOfThemOnAPhone(): void
    {
        $page = UiHarness::xpath(UiHarness::render('data.column-chart', ['label' => 'Clicks by day', 'columns' => self::days(30)]));
        self::assertSame(['1 Nov', '6 Nov', '11 Nov', '16 Nov', '21 Nov', '26 Nov'], self::texts($page, '//span[contains(@class,"cl-ui-column-chart-label")]'));
        self::assertSame(['6 Nov', '16 Nov', '26 Nov'], self::texts($page, '//span[contains(@class,"cl-ui-column-chart-label--wide")]'), 'Every other name waits for a wider screen.');
        self::assertSame(0, self::nodes($page, '//span[contains(@class,"cl-ui-column-chart-label")][not(@aria-hidden="true")]'), 'The names beneath repeat what each column says.');

        $few = UiHarness::xpath(UiHarness::render('data.column-chart', ['label' => 'Clicks by month', 'columns' => self::days(4)]));
        self::assertSame(4, self::nodes($few, '//span[contains(@class,"cl-ui-column-chart-label")]'));
        self::assertSame(0, self::nodes($few, '//span[contains(@class,"--wide")]'), 'A few names all fit a phone.');

        $chosen = self::days(10);
        $chosen[9]['named'] = true;
        $chosen[0]['named'] = false;
        self::assertSame(['3 Nov', '5 Nov', '7 Nov', '9 Nov', '10 Nov'], self::texts(UiHarness::xpath(UiHarness::render('data.column-chart', ['label' => 'Clicks', 'columns' => $chosen])), '//span[contains(@class,"cl-ui-column-chart-label")]'), 'The page may name a column or leave one unnamed.');

        self::assertStringContainsString('<figure class="cl-ui-column-chart" data-density="dense"', UiHarness::render('data.column-chart', ['label' => 'Clicks by day', 'columns' => self::days(60)]), 'Many columns stand closer, to fit a phone.');
        self::assertStringNotContainsString('cl-ui-column-chart" data-density', UiHarness::render('data.column-chart', ['label' => 'Clicks by day', 'columns' => self::days(30)]));
    }

    public function testAColumnChartOfNothingSaysSo(): void
    {
        foreach ([[], [['label' => '1 Nov', 'value' => 0], ['label' => '2 Nov', 'value' => 0]]] as $columns) {
            $html = UiHarness::render('data.column-chart', ['label' => 'Clicks by day', 'columns' => $columns, 'empty' => 'No clicks in these days: share a tracking link to start.']);
            self::assertStringContainsString('cl-ui-empty-state', $html);
            self::assertStringContainsString('No clicks in these days: share a tracking link to start.', $html);
            self::assertStringNotContainsString('<figure', $html);
        }
        self::assertSame('6 clicks0', self::text(UiHarness::xpath(UiHarness::render('data.column-chart', ['label' => 'Clicks', 'columns' => [['label' => '1 Nov', 'value' => 3]], 'top' => '6 clicks'])), '//div[@class="cl-ui-column-chart-scale"]'), 'The page may set the scale\'s top.');
    }

    public function testAColumnChartRefusesWhatIsNotAFigure(): void
    {
        foreach ([
            [['label' => '1 Nov'], 'column 1 lacks value'],
            [['label' => '1 Nov', 'value' => -1], 'column 1 has a value that is not a number of nothing or more'],
            [['label' => '1 Nov', 'value' => '4'], 'column 1 has a value that is not a number of nothing or more'],
            [['label' => '1 Nov', 'value' => 1, 'href' => 'javascript:alert(1)'], 'column 1 has an href that is neither a path on this site nor a web address'],
            [['label' => '1 Nov', 'value' => 1, 'href' => '//example.org/'], 'column 1 has an href that is neither'],
            [['label' => '1 Nov', 'value' => 1, 'colour' => 'red'], 'column 1 has colour, which it does not take'],
            [['label' => '', 'value' => 1], 'column 1 has no label in words'],
            [['label' => '1 Nov', 'value' => 1, 'named' => 'yes'], 'column 1 has a named that is not true or false'],
        ] as [$column, $message]) {
            try {
                UiHarness::components()->properties('data.column-chart', ['label' => 'Clicks', 'columns' => [$column]]);
                self::fail('Accepted ' . json_encode($column));
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString($message, $refused->getMessage());
            }
        }
        $this->expectExceptionMessage('Required UI property: data.column-chart.label');
        UiHarness::components()->properties('data.column-chart', ['columns' => []]);
    }

    public function testABarListGivesEachRowsLabelAndValueInWordsAndABarForTheEye(): void
    {
        $html = UiHarness::render('data.bar-list', ['label' => 'Clicks by channel', 'rows' => [
            ['label' => 'Email <newsletter>', 'value' => 120, 'words' => '120 clicks', 'href' => '/marketing/channels/email', 'note' => '60% of clicks'],
            ['label' => 'Print', 'value' => 30],
        ]]);
        $page = UiHarness::xpath($html);
        self::assertSame(1, self::nodes($page, '//ul[@class="cl-ui-bar-list"][@aria-label="Clicks by channel"]'));
        self::assertSame(['Email <newsletter>', 'Print'], self::texts($page, '//li/span[@class="cl-ui-bar-list-label"]'));
        self::assertStringContainsString('Email &lt;newsletter&gt;', $html);
        self::assertSame(1, self::nodes($page, '//span[@class="cl-ui-bar-list-label"]/a[@href="/marketing/channels/email"]'));
        self::assertSame('120 clicks 60% of clicks', self::text($page, '(//span[@class="cl-ui-bar-list-value"])[1]'));
        self::assertSame('30', self::text($page, '(//span[@class="cl-ui-bar-list-value"])[2]'), 'A value given no words is written as a number.');
        self::assertSame(['cl-ui-bar-list-bar cl-share cl-share-100', 'cl-ui-bar-list-bar cl-share cl-share-25'], self::texts($page, '//span[@class="cl-ui-bar-list-track"][@aria-hidden="true"]/span/@class'));
        // Read in order, a screen reader hears the label, then the value; the bar comes last and is hidden from it.
        self::assertSame(['cl-ui-bar-list-label', 'cl-ui-bar-list-value', 'cl-ui-bar-list-track'], self::texts($page, '(//li)[1]/span/@class'));
        self::assertStringNotContainsString('style=', $html);
    }

    public function testABarListMayMeasureAgainstATotalAndSaysWhenItHasNothing(): void
    {
        $page = UiHarness::xpath(UiHarness::render('data.bar-list', ['label' => 'Clicks by country', 'max' => 200, 'rows' => [
            ['label' => 'South Africa', 'value' => 150], ['label' => 'Kenya', 'value' => 0], ['label' => 'Namibia', 'value' => 250],
        ]]));
        self::assertSame(['cl-ui-bar-list-bar cl-share cl-share-75', 'cl-ui-bar-list-bar cl-share cl-share-0', 'cl-ui-bar-list-bar cl-share cl-share-100'], self::texts($page, '//span[@class="cl-ui-bar-list-track"]/span/@class'), 'A part of the total, never past its end.');

        $empty = UiHarness::render('data.bar-list', ['label' => 'Clicks by country', 'rows' => [], 'empty' => 'No clicks yet.']);
        self::assertStringContainsString('cl-ui-empty-state', $empty);
        self::assertStringContainsString('No clicks yet.', $empty);
        self::assertStringNotContainsString('cl-ui-bar-list', $empty);

        foreach ([['max' => -1], ['max' => '10'], ['rows' => [['label' => 'Kenya', 'value' => 1, 'note' => 3]]], ['rows' => [['label' => 'Kenya']]], ['label' => ' ']] as $broken) {
            try {
                UiHarness::components()->properties('data.bar-list', $broken + ['label' => 'Clicks', 'rows' => [['label' => 'Kenya', 'value' => 1]]]);
                self::fail('Accepted ' . json_encode($broken));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAPageThatDrawsItsOwnChartIsFoundAndTheStylesheetSizesEachBarByItsClass(): void
    {
        self::assertSame([
            'page.html.twig writes cl-ui-column-chart, which @CattoPHP/ui/data/column-chart.html.twig owns: draw it with its component instead.',
            'page.html.twig writes cl-ui-bar-list, which @CattoPHP/ui/data/bar-list.html.twig owns: draw it with its component instead.',
        ], TemplateContract::duplicates(['page.html.twig' => '<ul class="cl-ui-bar-list"></ul><figure class="cl-ui-column-chart"></figure>']));

        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/cattophp.css');
        self::assertStringContainsString('.cl-ui-column-chart-bar{display:block;width:100%;height:var(--cl-share,0%)', $css);
        self::assertStringContainsString('.cl-ui-bar-list-bar{display:block;width:var(--cl-share,0%)', $css);
        self::assertStringContainsString('.cl-ui-progress-bar.cl-share{width:var(--cl-share)}', $css, 'A progress bar is as long as its share, not always full.');
        self::assertMatchesRegularExpression('/@media\(max-width:575\.98px\)\{\.cl-ui-column-chart-label--wide\{display:none\}/', $css);
        self::assertStringContainsString('@media(forced-colors:active){.cl-ui-column-chart-bar,.cl-ui-bar-list-bar', $css, 'With the system\'s own colours forced, the bars still show.');
    }
}
