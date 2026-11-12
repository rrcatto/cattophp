<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\UiHarness;
use PHPUnit\Framework\TestCase;

/** What each component draws: its words escaped, its states and variants, and nothing it was not asked for. */
final class ComponentTemplatesTest extends TestCase
{
    public function testAFieldOwnsItsLabelHelpErrorAndWidth(): void
    {
        $html = UiHarness::render('form.field', ['label' => '<Name>', 'for' => 'sample', 'help' => 'Help & guidance', 'error' => '<Error>', 'required' => true, 'full_width' => true]);
        foreach (['for="sample"', 'cl-ui-field--full', '&lt;Name&gt;', 'id="sample-help"', 'id="sample-error"', 'role="alert"', 'cl-ui-required'] as $token) self::assertStringContainsString($token, $html);
        $plain = UiHarness::render('form.field', ['label' => 'Name']);
        self::assertStringNotContainsString('cl-ui-field-help', $plain);
        self::assertStringNotContainsString('cl-ui-field-error', $plain);
        self::assertStringContainsString('data-columns="wide"', UiHarness::render('form.grid', ['columns' => 'wide']));
        foreach (['quarter', 'third', 'half', 'two-thirds'] as $span) {
            self::assertStringContainsString('cl-ui-field--' . $span, UiHarness::render('form.field', ['label' => 'Title', 'span' => $span]));
            self::assertStringContainsString('cl-ui-field--' . $span, UiHarness::render('form.choice', ['label' => 'Active', 'span' => $span]));
        }
        $full = UiHarness::render('form.field', ['label' => 'Title', 'span' => 'third', 'full_width' => true]);
        self::assertStringContainsString('cl-ui-field--full', $full);
        self::assertStringNotContainsString('cl-ui-field--third', $full);
    }

    public function testAFieldsControlIsDescribedByItsHelpWhenEmbedded(): void
    {
        $html = UiHarness::source("{% embed ui_template('form.field') with {props: ui_props('form.field', {label: 'Name', for: 'name', help: 'As on your ID'})} %}{% block control %}<input id=\"name\" {{ ui_field_attrs(props) }}>{% endblock %}{% endembed %}");
        self::assertStringContainsString('<input id="name" aria-describedby="name-help">', $html);
    }

    public function testActionsAreRealLinksAndNativeButtons(): void
    {
        foreach (['primary', 'secondary', 'quiet', 'danger'] as $variant) {
            $html = UiHarness::render('action.link', ['label' => '<Go>', 'href' => '/items?q=a&b=1', 'variant' => $variant]);
            self::assertStringContainsString('href="/items?q=a&amp;b=1"', $html);
            self::assertStringContainsString('cl-ui-action--' . $variant, $html);
            self::assertStringContainsString('&lt;Go&gt;', $html);
        }
        self::assertStringContainsString('type="submit"', UiHarness::render('action.button', ['label' => 'Save']));
        self::assertStringNotContainsString('<svg', UiHarness::source("{{ ui('action.link', {label: 'Next', href: '/next'}) }}"), 'A link has an icon only when it names one.');
        self::assertStringContainsString('#action-add', UiHarness::source("{{ ui('action.link', {label: 'Create', href: '/create', icon: 'action-add'}) }}"));
    }

    /** A table is fitted to the page by the script unless it asks to keep its natural widths and scroll sideways. */
    public function testATableCanKeepItsNaturalWidths(): void
    {
        self::assertStringNotContainsString('data-column-fit', UiHarness::render('data.table'));
        self::assertStringContainsString('<div class="cl-ui-table" data-density="normal" data-column-fit="off">', UiHarness::render('data.table', ['fit' => false]));
    }

    /** A link that changes the page in place carries what a GET form does, aimed at its own address; one that opens something says whether it is open. */
    public function testALinkCanChangeThePageInPlaceAndSayWhetherItOpensSomething(): void
    {
        $html = UiHarness::render('action.link', ['label' => 'Next', 'href' => '/items?page=2', 'in_place' => true]);
        self::assertStringContainsString(' hx-get="/items?page=2" hx-target="#cl-main" hx-select="#cl-main" hx-swap="outerMorph" hx-push-url="true" hx-sync="#cl-main:replace"', $html);
        self::assertStringContainsString('href="/items?page=2"', $html, 'Without JavaScript it is an ordinary link.');
        $plain = UiHarness::render('action.link', ['label' => 'Next', 'href' => '/items?page=2']);
        self::assertStringNotContainsString('hx-', $plain);
        self::assertStringNotContainsString('aria-expanded', $plain);
        self::assertStringContainsString('aria-expanded="true"', UiHarness::render('action.link', ['label' => 'Close', 'href' => '/items', 'expanded' => true]));
        self::assertStringContainsString('aria-expanded="false"', UiHarness::render('action.link', ['label' => 'Open', 'href' => '/items?open=1', 'expanded' => false]));
    }

    public function testFiguresEscapeTheirWordsAndShowOnlyWhatTheyAreGiven(): void
    {
        $html = UiHarness::render('data.stat-card', ['value' => 24, 'label' => '<Items>']);
        self::assertStringContainsString('&lt;Items&gt;', $html);
        self::assertStringNotContainsString('cl-ui-stat-note', $html);
        self::assertStringNotContainsString('cl-ui-stat-card--large', $html);
        self::assertStringNotContainsString('cl-ui-stat-icon', $html, 'No icon unless one is named.');
        self::assertStringContainsString('class="cl-ui-stat-card cl-ui-stat-card--large"', UiHarness::render('data.stat-card', ['value' => '10.00', 'label' => 'Balance', 'size' => 'large']));
        $small = UiHarness::render('data.stat-card', ['value' => '54,177.05', 'label' => 'Money received', 'size' => 'small', 'icon' => 'figure-money-in', 'href' => '/payments']);
        self::assertStringContainsString('<a class="cl-ui-stat-card cl-ui-stat-card--small cl-ui-stat-link" href="/payments"><span class="cl-ui-stat-icon">', $small);
        self::assertStringContainsString('#figure-money-in', $small);
        self::assertStringContainsString('<div class="cl-ui-stat-value"><span>54,177.05</span></div>', $small);
        self::assertStringContainsString('data-columns="5"', UiHarness::render('data.stat-grid', ['columns' => 5]));
        try {
            UiHarness::render('data.stat-card', ['value' => 1, 'label' => 'x', 'size' => 'huge']);
            self::fail('An unknown stat card size was accepted.');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('size', $refused->getMessage());
        }
        self::assertStringContainsString('&lt;value&gt;', UiHarness::render('data.key-value-list', ['items' => [['label' => 'Name', 'value' => '<value>']]]));
        // A value marked down from a was value: the was value struck through first, named for screen readers.
        $marked = UiHarness::render('data.key-value-list', ['items' => [['label' => 'Price', 'value' => '1,800.00', 'was' => '<2,400.00>']]]);
        self::assertStringContainsString('<dd><s class="cl-ui-key-value-was"><span class="visually-hidden">Was </span>&lt;2,400.00&gt;</s> <span class="visually-hidden">now </span>1,800.00</dd>', $marked);
        self::assertStringNotContainsString('<s', UiHarness::render('data.key-value-list', ['items' => [['label' => 'Price', 'value' => '1,800.00', 'was' => null]]]));
    }

    /** A person: their name linked to their profile; the page's own person once with the reference and email on lines of their own. */
    public function testAPersonIsTheirNameLinkedToTheirAccount(): void
    {
        $name = UiHarness::render('data.person', ['name' => '<Ada Lovelace>', 'reference' => 'CL202610200001X', 'href' => '/people/CL202610200001X', 'email' => 'ada@example.com']);
        self::assertStringContainsString('<span class="cl-ui-person"><a class="cl-ui-person-account" href="/people/CL202610200001X"><span class="cl-ui-person-name">&lt;Ada Lovelace&gt;</span></a></span>', $name);
        self::assertStringNotContainsString('CL-', $name, 'A mention names the person without their reference.');
        self::assertStringNotContainsString('mailto:', $name, 'or their email.');
        $full = UiHarness::render('data.person', ['name' => 'Ada Lovelace', 'reference' => 'CL202610200001X', 'href' => '/people/CL202610200001X', 'email' => 'ada@example.com', 'full' => true]);
        self::assertStringContainsString('<span class="cl-ui-person cl-ui-person--full"><span class="cl-ui-person-line"><a class="cl-ui-person-account" href="/people/CL202610200001X"><span class="cl-ui-person-name">Ada Lovelace</span></a></span><span class="cl-ui-person-line cl-ui-person-reference">CL-20261020-0001-X</span><span class="cl-ui-person-line"><a class="cl-ui-person-email" href="mailto:ada@example.com">ada@example.com</a></span></span>', $full);
        self::assertStringNotContainsString('<a', UiHarness::render('data.person', ['name' => 'Sender']), 'No account to open: the name alone.');
    }

    public function testACardGridHoldsCardsWithAPictureTitleDetailsAndActions(): void
    {
        $grid = UiHarness::render('data.card-grid', ['columns' => 5, 'label' => '<People>']);
        self::assertStringContainsString('<ul class="cl-ui-card-grid" data-columns="5" aria-label="&lt;People&gt;">', $grid);
        self::assertStringContainsString('data-columns="4"', UiHarness::render('data.card-grid', []), 'Four to a row unless five are asked for.');
        $card = UiHarness::render('data.card', ['title' => '<Ada>', 'href' => '/people/CL202610200001X', 'picture' => 'round']);
        self::assertStringContainsString('<li class="cl-ui-card" data-picture="round">', $card);
        self::assertStringContainsString('<h3 class="cl-ui-card-title"><a href="/people/CL202610200001X">&lt;Ada&gt;</a></h3>', $card);
        self::assertStringContainsString('<h3 class="cl-ui-card-title">Example Trading</h3>', UiHarness::render('data.card', ['title' => 'Example Trading', 'picture' => 'square']), 'No page: the name alone.');
        foreach ([['data.card-grid', ['columns' => 6], 'columns'], ['data.card-grid', ['columns' => 'auto'], 'columns'], ['data.card', ['title' => 'x', 'picture' => 'oval'], 'picture']] as [$component, $properties, $key]) {
            try {
                UiHarness::render($component, $properties);
                self::fail($component . ' accepted ' . json_encode($properties));
            } catch (\Throwable $refused) {
                self::assertStringContainsString($key, $refused->getMessage());
            }
        }
    }

    /** A list: its pager above and below, one region that changes in place, and its search across every record. */
    public function testADatasetHasTwoPagersOneRegionAndItsSearch(): void
    {
        $html = UiHarness::render('data.dataset', ['name' => 'example', 'pagination' => self::page(), 'search' => ['ds_name' => 'example', 'ds_action' => '/example', 'ds_label' => 'Search examples', 'ds_value' => 'ada']]);
        self::assertSame(2, substr_count($html, 'data-pagination="example"'));
        self::assertSame(1, substr_count($html, 'id="example-region"'));
        self::assertSame(1, substr_count($html, 'id="example-results"'));
        self::assertStringContainsString('href="/example?example_page=1&amp;example_page_size=25"', $html);
        self::assertStringContainsString('<span>26–50 of 100 ·</span>', $html);
        self::assertStringContainsString('name="example_q" value="ada"', $html);
        self::assertStringContainsString('Searches every matching record, not only the rows on this page.', $html);
        preg_match_all('/\bid="([^"]+)"/', $html, $ids);
        self::assertSame($ids[1], array_values(array_unique($ids[1])), 'Paired pagers must give each label and control a unique ID.');
        $single = UiHarness::render('data.dataset', ['name' => 'example', 'pagination' => ['has_pages' => false, 'has_sizes' => false, 'page' => 1, 'total_pages' => 1, 'total' => 3, 'from' => 1, 'to' => 3] + self::page()]);
        self::assertStringContainsString('Pg 1 of 1', $single, 'One page still says why there is one page.');
        self::assertStringNotContainsString('pagination-jump', $single);
        self::assertStringNotContainsString('pagination-page-size', $single);
    }

    public function testABreadcrumbMarksWhereTheReaderIs(): void
    {
        $html = UiHarness::render('layout.breadcrumb', ['items' => [['label' => 'Home', 'href' => '/'], ['label' => '<Current>', 'href' => null, 'current' => true]], 'aria_label' => 'Location']);
        self::assertStringContainsString('href="/"', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('&lt;Current&gt;', $html);
    }

    public function testEveryToneEscapesItsWords(): void
    {
        foreach (['neutral', 'info', 'success', 'warning', 'danger', 'permanent'] as $tone) {
            $html = UiHarness::render('feedback.badge', ['label' => '<b>Unsafe</b>', 'tone' => $tone]);
            self::assertStringContainsString('cl-ui-badge--' . $tone, $html);
            self::assertStringContainsString('&lt;b&gt;', $html);
            self::assertStringNotContainsString('<b>', $html);
        }
        foreach (['info', 'success', 'warning', 'danger'] as $tone) {
            $html = UiHarness::render('feedback.notice', ['tone' => $tone, 'heading' => '<strong>Title</strong>']);
            self::assertStringContainsString('cl-ui-notice--' . $tone, $html);
            self::assertStringContainsString('&lt;strong&gt;', $html);
        }
    }

    public function testASolidNoticeHasItsIconAndAnOrdinaryOneHasNeither(): void
    {
        $html = UiHarness::render('feedback.notice', ['tone' => 'warning', 'solid' => true, 'icon' => 'notice-warning']);
        self::assertStringContainsString('cl-ui-notice--solid', $html);
        self::assertStringContainsString('#notice-warning', $html);
        $plain = UiHarness::render('feedback.notice', ['tone' => 'warning']);
        self::assertStringNotContainsString('cl-ui-notice--solid', $plain);
        self::assertStringNotContainsString('cl-ui-notice-icon', $plain);
        self::assertStringNotContainsString('<h3', UiHarness::render('feedback.empty', ['summary' => 'Nothing yet']));
        self::assertStringNotContainsString('<strong', UiHarness::render('feedback.notice'));
    }

    public function testProgressClampsItsValueAndCarriesItsRangeAndLabel(): void
    {
        foreach ([[-10, 0], [45, 45], [200, 100]] as [$input, $expected]) {
            $html = UiHarness::render('feedback.progress', ['value' => $input, 'label' => 'Progress']);
            self::assertStringContainsString('role="progressbar"', $html);
            self::assertStringContainsString('aria-valuenow="' . $expected . '"', $html);
            self::assertStringContainsString('aria-valuemin="0"', $html);
            self::assertStringContainsString('aria-valuemax="100"', $html);
            self::assertStringContainsString('aria-label="Progress"', $html);
        }
        $this->expectExceptionMessage('Invalid progress range.');
        UiHarness::render('feedback.progress', ['value' => 1, 'label' => 'Progress', 'min' => 2, 'max' => 2]);
    }

    public function testAWindowHasOneFootAndATitleAndWorksWithoutJavaScript(): void
    {
        $html = UiHarness::render('overlay.modal', ['id' => 'example', 'title' => '<Example>', 'action' => '/example']);
        foreach (['role="dialog"', 'aria-labelledby="example-title"', 'id="example-title"', '&lt;Example&gt;', 'action="/example"', 'method="post"'] as $token) self::assertStringContainsString($token, $html);
        self::assertSame(1, substr_count($html, 'class="cl-ui-modal-foot"'));
        self::assertStringNotContainsString(' hidden', $html, 'Without JavaScript the same forms must be reachable.');
        self::assertStringNotContainsString('data-modal-open', $html, 'A window opens when asked.');
        // A window whose form was refused comes back open, holding what was typed.
        self::assertStringContainsString('data-modal-open', UiHarness::render('overlay.modal', ['id' => 'example', 'title' => 'Example', 'action' => '/example', 'open' => true]));
    }

    public function testAnAccordionIsANativeDisclosure(): void
    {
        $html = UiHarness::render('overlay.accordion-section', ['heading' => 'Settings', 'open' => true]);
        self::assertStringContainsString('<details', $html);
        self::assertStringContainsString(' open>', $html);
        self::assertStringNotContainsString('cl-ui-accordion-description', $html);
        try {
            UiHarness::render('overlay.accordion-section', ['heading' => 'x', 'picture' => 'https://example.com/logo.png']);
            self::fail('Another site\'s picture was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('An accordion picture is a path on this site.', $exception->getMessage());
        }
    }

    public function testAnIconIsDecorationOrNamedForScreenReaders(): void
    {
        self::assertStringContainsString('aria-hidden="true"', UiHarness::render('icon', ['name' => 'nav-home']));
        self::assertStringContainsString('aria-label="Home"', UiHarness::render('icon', ['name' => 'nav-home', 'decorative' => false, 'label' => 'Home']));
        $this->expectException(\InvalidArgumentException::class);
        UiHarness::render('icon', ['name' => 'nav-home', 'decorative' => false]);
    }

    /**
     * A list's page as a project's pagination gives it: page 2 of 4, 25 rows a page.
     *
     * @return array<string,mixed>
     */
    private static function page(): array
    {
        $href = static fn (int $page): string => '/example?example_page=' . $page . '&example_page_size=25';
        return [
            'prefix' => 'example', 'label' => 'Examples', 'base' => '/example', 'region' => 'example-region', 'results' => 'example-results',
            'page' => 2, 'page_size' => 25, 'total' => 100, 'total_pages' => 4, 'from' => 26, 'to' => 50,
            'has_pages' => true, 'has_previous' => true, 'has_next' => true, 'has_sizes' => true, 'page_sizes' => [25, 50, 100],
            'first_href' => $href(1), 'previous_href' => $href(1), 'next_href' => $href(3), 'last_href' => $href(4),
            'pages' => [['is_gap' => false, 'is_current' => false, 'page' => 1, 'href' => $href(1)], ['is_gap' => false, 'is_current' => true, 'page' => 2, 'href' => $href(2)], ['is_gap' => true, 'is_current' => false, 'page' => 0, 'href' => ''], ['is_gap' => false, 'is_current' => false, 'page' => 4, 'href' => $href(4)]],
            'filters' => [], 'page_param' => 'example_page', 'size_param' => 'example_page_size',
        ];
    }
}
