<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\HtmlQuery;
use CattoPHP\Tests\Support\UiHarness;
use PHPUnit\Framework\TestCase;

/** A choice from a hierarchy: a native popover, every level choosable, working without JavaScript. */
final class TreeSelectTest extends TestCase
{
    private const ITEMS = [
        ['value' => '', 'label' => 'All categories', 'depth' => 0, 'path' => 'All categories'],
        ['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'children' => [
            ['value' => '2', 'label' => 'Management', 'depth' => 2, 'path' => 'Business › Management', 'children' => [
                ['value' => '3', 'label' => 'Project Management', 'depth' => 3, 'path' => 'Business › Management › Project Management'],
            ]],
            ['value' => '4', 'label' => 'Finance', 'depth' => 2, 'path' => 'Business › Finance'],
        ]],
        ['value' => '5', 'label' => 'Technology', 'depth' => 1, 'path' => 'Technology'],
    ];

    public function testTheHierarchyNestsWithDisclosureButtonsBesideTheOptions(): void
    {
        $html = UiHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => self::ITEMS, 'selected' => '3', 'placeholder' => 'All categories', 'list_label' => 'Categories']);
        $xpath = self::xpath($html);
        $toggle = $xpath->query('//button[@type="button" and @id="picker" and @popovertarget="picker-options"]');
        self::assertSame(1, $toggle->length);
        self::assertSame('Business › Management › Project Management', trim((string) $toggle->item(0)?->textContent), 'The closed control shows the full path of the choice.');
        $top = $xpath->query('//*[@id="picker-options" and @popover]/ul[@aria-label="Categories"]/li');
        self::assertSame(3, $top->length, 'The top level holds the depth-0 choice and the main categories only.');
        self::assertSame(1, $xpath->query('//ul[@aria-label="Categories"]/li/ul[@id="picker-branch-1" and @role="group"]/li/ul[@id="picker-branch-2"]/li//button[@value="3"]')->length, 'Three levels nest as ul > li > ul.');
        $expanders = $xpath->query('//button[contains(@class,"cl-ui-tree-select-expand")]');
        self::assertSame(2, $expanders->length, 'Only options with children have a disclosure button.');
        foreach ($expanders as $expander) {
            self::assertInstanceOf(\DOMElement::class, $expander);
            self::assertSame(['button', 'true'], [$expander->getAttribute('type'), $expander->getAttribute('aria-expanded')], 'Rendered open, so the tree works without JavaScript.');
            self::assertTrue($expander->hasAttribute('hidden'), 'Shown by core script.');
            self::assertSame(1, $xpath->query('//ul[@id="' . $expander->getAttribute('aria-controls') . '"]')->length);
            self::assertStringStartsWith('Show what is inside ', $expander->getAttribute('aria-label'));
            self::assertFalse($expander->hasAttribute('name'), 'A disclosure button never chooses.');
        }
        self::assertSame(0, $xpath->query('//button[contains(@class,"cl-ui-tree-select-expand")]//button | //button[contains(@class,"cl-ui-tree-select-option")]//button')->length, 'The disclosure button sits beside the option, not inside it.');
        self::assertSame(6, $xpath->query('//*[@popover]//button[@type="submit" and @name="category"]')->length, 'Every level can be chosen.');
        self::assertSame('3', self::attribute($xpath, '//button[@aria-current="true" and @autofocus]', 'value'), 'Opening the list focuses the chosen option.');
        self::assertSame('Business › Management', self::attribute($xpath, '//button[@value="2"]', 'aria-label'));
    }

    public function testFieldModeStoresTheChoiceInRadiosForTheFormsOwnSave(): void
    {
        $items = [
            ['value' => '0', 'label' => 'Uncategorised', 'depth' => 0, 'path' => 'Uncategorised'],
            ['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'children' => [['value' => '2', 'label' => 'Management', 'depth' => 2, 'path' => 'Business › Management']]],
        ];
        $html = UiHarness::render('form.tree-select', ['id' => 'category', 'name' => 'category_id', 'mode' => 'field', 'items' => $items, 'selected' => '2', 'placeholder' => 'Uncategorised', 'list_label' => 'Categories']);
        $xpath = self::xpath($html);
        self::assertSame(1, $xpath->query('//div[@data-tree-select="field"]')->length);
        self::assertSame('Business › Management', trim((string) $xpath->query('//button[@id="category"]')->item(0)?->textContent));
        self::assertSame(0, $xpath->query('//button[@type="submit"]')->length, 'Choosing must not submit the form.');
        self::assertSame(1, $xpath->query('//*[@popover]/ul[@role="radiogroup" and @aria-label="Categories"]')->length);
        self::assertSame(3, $xpath->query('//label[contains(@class,"cl-ui-tree-select-option")]/input[@type="radio" and @name="category_id"]')->length);
        self::assertSame('2', self::attribute($xpath, '//input[@checked and @autofocus]', 'value'));
        self::assertSame('Uncategorised', self::attribute($xpath, '//input[@value="0"]', 'data-tree-select-path'));
        $uncategorised = self::xpath(UiHarness::render('form.tree-select', ['id' => 'category', 'name' => 'category_id', 'mode' => 'field', 'items' => $items, 'selected' => '0', 'placeholder' => 'Uncategorised', 'list_label' => 'Categories']));
        self::assertSame('Uncategorised', trim((string) $uncategorised->query('//button[@id="category"]')->item(0)?->textContent), 'No category shows Uncategorised.');
    }

    /** An option's keys are a set: in any order they draw the same list, and a refusal names the key that is wrong. */
    public function testAnOptionsKeysMayComeInAnyOrderAndARefusalNamesTheKey(): void
    {
        $shuffled = [
            ['path' => 'All categories', 'depth' => 0, 'label' => 'All categories', 'value' => ''],
            ['children' => [
                ['depth' => 2, 'path' => 'Business › Management', 'value' => '2', 'label' => 'Management', 'children' => [
                    ['label' => 'Project Management', 'value' => '3', 'path' => 'Business › Management › Project Management', 'depth' => 3],
                ]],
                ['path' => 'Business › Finance', 'label' => 'Finance', 'depth' => 2, 'value' => '4'],
            ], 'value' => '1', 'label' => 'Business', 'path' => 'Business', 'depth' => 1],
            ['label' => 'Technology', 'path' => 'Technology', 'value' => '5', 'depth' => 1],
        ];
        $props = ['id' => 'picker', 'name' => 'category', 'selected' => '3', 'placeholder' => 'All categories', 'list_label' => 'Categories'];
        self::assertSame(UiHarness::render('form.tree-select', $props + ['items' => self::ITEMS]), UiHarness::render('form.tree-select', $props + ['items' => $shuffled]));

        try {
            UiHarness::components()->properties('form.tree-select', $props + ['items' => [['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'colour' => 'red']]]);
            self::fail('Accepted a key it does not take.');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('in any order: "Business" has colour, which it does not take.', $refused->getMessage());
        }
    }

    public function testAnOptionMayCarryItsIcon(): void
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16"/></svg>';
        $items = [['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'icon' => $icon, 'children' => [
            ['value' => '2', 'label' => 'Finance', 'depth' => 2, 'path' => 'Business › Finance', 'icon' => $icon],
        ]], ['value' => '3', 'label' => 'Science', 'depth' => 1, 'path' => 'Science', 'icon' => '']];
        foreach (['submit', 'field'] as $mode) {
            $html = UiHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => $items, 'selected' => '2', 'placeholder' => 'Choose', 'list_label' => 'Categories', 'mode' => $mode]);
            self::assertSame(2, substr_count($html, '<span class="cl-ui-tree-select-icon" aria-hidden="true">' . $icon . '</span><span>'), $mode . ' mode draws each icon before its label, hidden from screen readers.');
            self::assertStringContainsString('Business › Finance', $html);
        }
        $this->expectException(\InvalidArgumentException::class);
        UiHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => [['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'icon' => ['not', 'markup']]], 'selected' => '', 'placeholder' => 'Choose', 'list_label' => 'Categories']);
    }

    /**
     * Review (an older gap, unchanged since 0.3.0): an option's icon is drawn inline through |authored, so it is checked as
     * a feature card's is, an SVG picture free of scripts and outside links, and anything else is refused with its label.
     */
    public function testAnOptionsIconIsAPictureFreeOfScriptsAndOutsideLinks(): void
    {
        foreach ([
            'a script' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'an event handler' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M4 4h16"/></svg>',
            'an outside link' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.org/a.png"/></svg>',
            'no SVG at all' => '<img src="x" onerror="alert(1)">',
        ] as $what => $icon) {
            try {
                UiHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => [['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'icon' => $icon]], 'selected' => '', 'placeholder' => 'Choose', 'list_label' => 'Categories']);
                self::fail($what . ' was drawn as an icon.');
            } catch (\InvalidArgumentException $refused) {
                self::assertStringContainsString('"Business"', $refused->getMessage(), $what);
            }
        }
    }

    public function testNothingChosenShowsThePlaceholder(): void
    {
        $html = UiHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => array_slice(self::ITEMS, 1), 'selected' => '', 'placeholder' => 'All categories', 'list_label' => 'Categories']);
        self::assertSame('All categories', trim((string) self::xpath($html)->query('//button[@id="picker"]')->item(0)?->textContent));
    }

    public function testOptionsAndIdentifiersAreBounded(): void
    {
        $leaf = ['value' => '3', 'label' => 'C', 'depth' => 3, 'path' => 'A › B › C'];
        foreach ([
            ['id' => 'Bad id', 'items' => self::ITEMS],
            ['id' => 'picker', 'items' => []],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 4, 'path' => 'A']]],
            ['id' => 'picker', 'items' => [['value' => 1, 'label' => 'A', 'depth' => 1, 'path' => 'A']]],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A', 'class' => 'x']]],
            ['id' => 'picker', 'items' => [['value' => '2', 'label' => 'B', 'depth' => 2, 'path' => 'B']]],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A', 'children' => [$leaf]]]],
            ['id' => 'picker', 'items' => [['value' => '0', 'label' => 'None', 'depth' => 0, 'path' => 'None', 'children' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A']]]]],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A', 'children' => [['value' => '2', 'label' => 'B', 'depth' => 2, 'path' => 'A › B', 'children' => [['value' => '3', 'label' => 'C', 'depth' => 3, 'path' => 'A › B › C', 'children' => [['value' => '4', 'label' => 'D', 'depth' => 4, 'path' => 'D']]]]]]]]],
        ] as $properties) {
            try {
                UiHarness::components()->properties('form.tree-select', $properties + ['name' => 'category', 'list_label' => 'Categories']);
                self::fail('Invalid tree select accepted: ' . json_encode($properties));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('tree select', $exception->getMessage());
            }
        }
        try {
            UiHarness::components()->properties('form.tree-select', ['id' => 'parent', 'name' => 'parent_id', 'mode' => 'dropdown', 'items' => self::ITEMS, 'list_label' => 'Parents']);
            self::fail('Unknown mode accepted');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('tree select', $exception->getMessage());
        }
    }

    private static function attribute(HtmlQuery $xpath, string $query, string $name): string
    {
        $node = $xpath->query($query)->item(0);
        self::assertInstanceOf(\DOMElement::class, $node, $query);
        return $node->getAttribute($name);
    }

    private static function xpath(string $html): HtmlQuery
    {
        return UiHarness::xpath($html);
    }
}
