<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Twig\Environment;
use Twig\Markup;

/**
 * The component registry: the one map of a component's name to its template and the properties it takes, so no page
 * invents its own card, table, field, window or notice. A page draws a component with `ui('name', {…})`, or embeds it
 * with `ui_template('name')` and `ui_props('name', {…})` to fill its slots with its own markup. Properties are data
 * (words, numbers, choices from a fixed list), never an attribute bag, a class or rendered markup; variable HTML belongs
 * in a slot. CattoPHP's components are at `@CattoPHP/ui/`; a project adds its own through `ComponentSet`.
 */
final class Components
{
    /** A form field's name, as a tick's card or a list of ticks is named: such as news, or slide[build]. */
    private const FIELD_NAME = '/^[a-z][a-z0-9_]*(\[[a-z0-9_]+\])*$/';

    /** A word a project's own script finds an element by: an accordion's `data-<word>-section`, a button's `data-<hook>`. */
    private const WORD = '/^([a-z][a-z0-9-]*)?$/';

    /** Each component's template under `@CattoPHP/ui/` and its properties' defaults (null: required). */
    public const COMPONENTS = [
        'layout.surface' => ['template' => 'layout/surface', 'defaults' => ['variant' => 'standard', 'section_spacing' => false, 'id' => '', 'sticky' => false, 'tone' => 'neutral', 'labelledby' => '']],
        // columns 'main-side': a main column and a narrower side column.
        'layout.section-grid' => ['template' => 'layout/section-grid', 'defaults' => ['columns' => 'auto', 'section_spacing' => true]],
        'layout.section-head' => ['template' => 'layout/section-head', 'defaults' => ['eyebrow' => '', 'heading' => null, 'summary' => '', 'heading_level' => 2, 'heading_id' => '']],
        'layout.toolbar' => ['template' => 'layout/toolbar', 'defaults' => []],
        'layout.breadcrumb' => ['template' => 'layout/breadcrumb', 'defaults' => ['items' => null, 'aria_label' => 'Breadcrumb']],
        'layout.tabs' => ['template' => 'layout/tabs', 'defaults' => ['items' => null, 'current' => '', 'aria_label' => null]],
        'action.link' => ['template' => 'actions/link', 'defaults' => ['lookup_clear' => '', 'close_disclosure' => false, 'in_place' => false, 'expanded' => null, 'new_window' => false, 'rel' => '', 'aria_label' => '', 'navigation_key' => '', 'get' => '', 'target' => '', 'select' => '', 'swap' => 'innerHTML', 'push_url' => false, 'indicator' => '', 'label' => null, 'variant' => 'secondary', 'size' => 'normal', 'icon' => '', 'icon_only' => false, 'title' => '', 'id' => '', 'confirm' => '', 'modal' => '', 'full_width' => false, 'href' => null]],
        // hook: a word the project's own script finds the button by, data-<hook> (such as a picker's "add one" switch).
        'action.button' => ['template' => 'actions/button', 'defaults' => ['lookup_clear' => '', 'aria_label' => '', 'icon_only' => false, 'get' => '', 'target' => '', 'select' => '', 'swap' => 'innerHTML', 'push_url' => false, 'indicator' => '', 'label' => null, 'variant' => 'secondary', 'size' => 'normal', 'icon' => '', 'title' => '', 'id' => '', 'confirm' => '', 'modal' => '', 'close_modal' => false, 'form' => '', 'form_action' => '', 'form_target' => '', 'name' => '', 'value' => '', 'stimulus_action' => '', 'disabled' => false, 'hidden' => false, 'expanded' => null, 'controls' => '', 'full_width' => false, 'type' => 'submit', 'skip_validation' => false, 'hook' => '']],
        'action.group' => ['template' => 'actions/group', 'defaults' => []],
        'action.row' => ['template' => 'actions/row-actions', 'defaults' => ['menu' => false, 'narrow_menu' => false, 'label' => 'Actions', 'aria_label' => '', 'icon' => 'action-open', 'compact' => false]],
        'form.grid' => ['template' => 'forms/form-grid', 'defaults' => ['columns' => 'auto']],
        'form.field' => ['template' => 'forms/field', 'defaults' => ['label' => null, 'for' => '', 'required' => false, 'full_width' => false, 'span' => '', 'help' => '', 'error' => '']],
        'form.actions' => ['template' => 'forms/form-actions', 'defaults' => []],
        'form.choice' => ['template' => 'forms/choice-field', 'defaults' => ['label' => null, 'help' => '', 'full_width' => false, 'span' => '']],
        // A choice made by picture or feature card: a radio group whose options are pictures, SVG icons or the project's
        // own drawings, each with a label and an optional note. columns 4: at most four cards to a row, further rows
        // beneath, left-aligned. multiple: each card a checkbox named by its value, the ticked ones listed in selected.
        // list: the cards share one field, list[], each sent as its value. tick_value is what a named card sends when
        // ticked, for a field that reads something other than 1. required makes every control required, an item's
        // disabled greys out that one, legend_hidden keeps the legend for screen readers when the card's own title says
        // it all, and columns 1 stacks the cards in a narrow place. error: why the choice was refused, drawn as form.field
        // draws one (under the legend and help), the fieldset described by it and every control aria-invalid; its id is
        // <id>-error, else <name or list>-error (ticks named by their values need an id for it).
        'form.picture-choice' => ['template' => 'forms/picture-choice', 'defaults' => ['name' => '', 'legend' => null, 'items' => null, 'selected' => '', 'help' => '', 'error' => '', 'id' => '', 'size' => 'normal', 'columns' => 'auto', 'form' => '', 'multiple' => false, 'list' => '', 'tick_value' => '1', 'required' => false, 'legend_hidden' => false], 'props' => ['size' => ['normal', 'small'], 'columns' => ['auto', 1, 4]]],
        // form (field mode): the choice belongs to a form elsewhere on the page (a row edited in place).
        'form.tree-select' => ['template' => 'forms/tree-select', 'defaults' => ['id' => null, 'name' => null, 'items' => null, 'selected' => '', 'placeholder' => 'Choose', 'list_label' => null, 'mode' => 'submit', 'form' => '']],
        'form.compact-action' => ['template' => 'forms/compact-action', 'defaults' => []],
        // stack: each row a card of label and value on a narrow screen (data-stack="narrow"), each cell giving its column's
        // heading in data-label; for a table of more columns than a phone holds. fit: false keeps the columns' natural
        // widths and lets the table scroll sideways in its box instead.
        'data.table' => ['template' => 'data/data-table', 'defaults' => ['section_spacing' => false, 'density' => 'normal', 'caption' => '', 'fit' => true, 'stack' => false]],
        'data.dataset' => ['template' => 'data/dataset-layout', 'defaults' => ['name' => null, 'heading' => '', 'summary' => '', 'search' => [], 'pagination' => null]],
        'data.stat-grid' => ['template' => 'data/stat-grid', 'defaults' => ['columns' => 'auto', 'density' => 'normal'], 'props' => ['columns' => ['auto', 1, 2, 3, 4, 5]]],
        // A list shown as cards: the grid holds whole rows of cards, 4 or 5 to a row at full width, fewer on a narrow
        // screen; each card a picture, its title, details and actions.
        'data.card-grid' => ['template' => 'data/card-grid', 'defaults' => ['columns' => 4, 'label' => ''], 'props' => ['columns' => [4, 5]]],
        'data.card' => ['template' => 'data/card', 'defaults' => ['title' => '', 'href' => '', 'picture' => 'square'], 'props' => ['picture' => ['round', 'square', 'wide', 'none']]],
        'data.stat-card' => ['template' => 'data/stat-card', 'defaults' => ['value' => null, 'label' => null, 'note' => '', 'href' => '', 'size' => 'normal', 'icon' => ''], 'props' => ['size' => ['normal', 'large', 'small']]],
        'data.list' => ['template' => 'data/item-list', 'defaults' => []],
        'data.list-item' => ['template' => 'data/list-item', 'defaults' => []],
        'data.key-value-list' => ['template' => 'data/key-value-list', 'defaults' => ['items' => null]],
        'data.person' => ['template' => 'data/person', 'defaults' => ['name' => null, 'reference' => '', 'href' => '', 'email' => '', 'full' => false]],
        // Amounts over time as columns, one for each slice of a period, the empty ones included: label names the chart,
        // columns are each label and value (words, href and named optional). top is the scale's top in words (the
        // largest column's words unless given); label_heading and value_heading head the table of the same figures under
        // the chart (table: false leaves it out); empty is what is said when every column is nothing.
        'data.column-chart' => ['template' => 'data/column-chart', 'defaults' => ['label' => null, 'columns' => null, 'top' => '', 'label_heading' => 'Period', 'value_heading' => 'Amount', 'empty' => 'Nothing to show for this period.', 'table' => true]],
        // Things compared by one figure, a row each: its label (a link with href), its value in words and a bar of it
        // against the largest (or against max, such as a total), with an optional note under the value.
        'data.bar-list' => ['template' => 'data/bar-list', 'defaults' => ['label' => null, 'rows' => null, 'max' => 0, 'empty' => 'Nothing to show yet.']],
        // A rating out of five: the stars, the mean to one decimal and how many reviews it comes from (count), the same in
        // words for a screen reader. With none, empty's words (nothing at all when empty is ''), never a row of empty
        // stars, which would read as a rating of nothing. compact: smaller, for a card.
        'data.rating' => ['template' => 'data/rating', 'defaults' => ['average' => 0, 'count' => null, 'compact' => false, 'empty' => 'No reviews yet']],
        'feedback.badge' => ['template' => 'feedback/badge', 'defaults' => ['label' => null, 'tone' => 'neutral', 'size' => 'normal']],
        'feedback.notice' => ['template' => 'feedback/notice', 'defaults' => ['tone' => 'info', 'heading' => '', 'section_spacing' => false, 'flash' => false, 'solid' => false, 'icon' => '']],
        'feedback.empty' => ['template' => 'feedback/empty-state', 'defaults' => ['heading' => '', 'summary' => '']],
        'feedback.progress' => ['template' => 'feedback/progress', 'defaults' => ['value' => null, 'min' => 0, 'max' => 100, 'label' => null, 'show_value' => false, 'size' => 'normal']],
        // A count down to a moment (ends_at, a Unix time) by the server's own clock (now; 0: the clock now), so a
        // visitor's wrong clock cannot change it: words, then the time left from the largest unit (week or day) down to
        // the seconds, shown and ticking with JavaScript; then until and the moment in the site's time zone, shown always,
        // so without JavaScript the page still says when. Nothing once the moment has passed; at zero the script takes it
        // away, with the element around it marked data-cl-countdown-scope if there is one.
        'feedback.countdown' => ['template' => 'feedback/countdown', 'defaults' => ['ends_at' => null, 'now' => 0, 'words' => 'Time left:', 'until' => 'Until', 'largest' => 'week'], 'props' => ['largest' => ['week', 'day']]],
        'overlay.modal' => ['template' => 'overlay/modal', 'defaults' => ['id' => null, 'title' => null, 'size' => 'normal', 'action' => '', 'method' => 'post', 'upload' => false, 'open' => false]],
        // eyebrow and icon: a category line above the heading and an SVG icon before both. workspace and section_key:
        // data-<workspace>-section="<section_key>", for the project's script that remembers which sections were open.
        'overlay.accordion-section' => ['template' => 'overlay/accordion-section', 'defaults' => ['id' => '', 'heading' => null, 'summary' => '', 'summary_tone' => '', 'open' => false, 'workspace' => '', 'section_key' => '', 'load_url' => '', 'eyebrow' => '', 'icon' => '', 'picture' => '']],
        'icon' => ['template' => 'helpers/icon', 'defaults' => ['name' => null, 'label' => '', 'decorative' => true, 'size' => 'normal']],
    ];

    /** @var array<string, array{template: string, defaults: array<string, mixed>, props?: array<string, list<mixed>>}> */
    private array $projectComponents = [];

    /** @var array<string, ComponentSet> */
    private array $owners = [];

    /** @var list<string> */
    private array $sharedContext = [];

    /** @var list<DrawingCheck> */
    private array $drawings;

    private ?string $iconSprite = null;

    /**
     * @param iterable<ComponentSet> $sets The project's own components.
     * @param iterable<DrawingCheck> $drawings The drawings the project allows as a feature card's picture.
     * @param string $iconSpritePath The icon sprite's address on the site, such as /img/icons.svg.
     * @param string|null $publicRoot The served web root, for the icon sprite's fingerprint.
     */
    public function __construct(iterable $sets = [], iterable $drawings = [], private readonly string $iconSpritePath = '/img/icons.svg', private readonly ?string $publicRoot = null)
    {
        foreach ($sets as $set) {
            foreach ($set->components() as $name => $definition) {
                if (isset(self::COMPONENTS[$name]) || isset($this->projectComponents[$name])) {
                    throw new LogicException('Two components are named ' . $name . '.');
                }
                $this->projectComponents[$name] = $definition;
                $this->owners[$name] = $set;
            }
            $this->sharedContext = array_values(array_unique([...$this->sharedContext, ...$set->sharedContext()]));
        }
        $this->drawings = is_array($drawings) ? array_values($drawings) : iterator_to_array($drawings, false);
    }

    /**
     * Every component: CattoPHP's, then the project's.
     *
     * @return array<string, array{template: string, defaults: array<string, mixed>, props?: array<string, list<mixed>>}>
     */
    public function definitions(): array
    {
        return self::COMPONENTS + $this->projectComponents;
    }

    /**
     * The attributes a form.field's control carries: only its relationships to the field's help and error, never an
     * arbitrary attribute bag.
     *
     * @param array<string,mixed> $properties
     */
    public function fieldAttributes(array $properties): Markup
    {
        $field = $this->properties('form.field', $properties);
        if (!is_string($field['for']) || preg_match('/\s/u', $field['for'])) {
            throw new InvalidArgumentException('form.field.for must be a single control ID.');
        }
        $ids = [];
        if ($field['for'] !== '') {
            if ($field['help']) $ids[] = $field['for'] . '-help';
            if ($field['error']) $ids[] = $field['for'] . '-error';
        }
        $attributes = $ids === [] ? '' : 'aria-describedby="' . htmlspecialchars(implode(' ', $ids), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ($field['error']) $attributes .= ($attributes === '' ? '' : ' ') . 'aria-invalid="true"';
        return new Markup($attributes, 'UTF-8');
    }

    /** The template of a registered component, and only of one: no aliases or paths made up on the page. */
    public function template(string $component): string
    {
        if (isset(self::COMPONENTS[$component])) {
            return '@CattoPHP/ui/' . self::COMPONENTS[$component]['template'] . '.html.twig';
        }

        return ($this->projectComponents[$component] ?? throw new InvalidArgumentException('Unknown UI component: ' . $component))['template'];
    }

    /**
     * A component's properties checked against what it takes, with its defaults for the rest: the same for `ui()` and
     * for a template embedded with its slots.
     *
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    public function properties(string $component, array $properties = []): array
    {
        $this->template($component);
        $defaults = $this->definitions()[$component]['defaults'];
        if (array_diff_key($properties, $defaults) !== []) {
            throw new InvalidArgumentException('Unknown UI property for ' . $component . ': ' . implode(', ', array_keys(array_diff_key($properties, $defaults))));
        }
        array_walk_recursive($properties, static function (mixed $value): void {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('UI properties contain data, not objects or rendered markup.');
            }
        });
        $values = $properties + $defaults;
        foreach ($defaults as $key => $default) {
            if ($default === null && $values[$key] === null && !in_array($key, ['pagination', 'expanded'], true)) {
                throw new InvalidArgumentException('Required UI property: ' . $component . '.' . $key);
            }
        }
        if (isset($this->owners[$component])) {
            return $this->owners[$component]->check($component, $values);
        }

        return $this->checked($component, $values);
    }

    /**
     * Draws a component from its properties; variable HTML belongs in an embedded template's slots.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $properties
     */
    public function render(Environment $twig, array $context, string $component, array $properties = []): Markup
    {
        $shared = array_intersect_key($context, array_flip($this->sharedContext));
        $sprite = $component === 'icon' ? ['icon_sprite' => $this->iconSprite()] : [];
        return new Markup($twig->render($this->template($component), ['props' => $this->properties($component, $properties)] + $sprite + $shared), 'UTF-8');
    }

    /**
     * The sprite's address, fingerprinted by its content so an icon added to the sprite reaches a browser that cached
     * the previous file. A component is drawn in its own context and cannot see the page's own values.
     */
    public function iconSprite(): string
    {
        if ($this->iconSprite === null) {
            $file = $this->publicRoot === null ? '' : rtrim($this->publicRoot, '/') . $this->iconSpritePath;
            $hash = $file !== '' && is_file($file) ? hash_file('sha256', $file) : false;
            $this->iconSprite = $this->iconSpritePath . ($hash === false ? '' : '?v=' . substr($hash, 0, 16));
        }
        return $this->iconSprite;
    }

    /**
     * One of CattoPHP's components' values checked: each choice from its fixed list, and what each component's
     * structured properties must hold.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function checked(string $component, array $values): array
    {
        $enums = [
            'tone' => $component === 'feedback.notice' ? ['info', 'success', 'warning', 'danger', 'neutral'] : ['neutral', 'info', 'success', 'warning', 'danger', 'permanent'],
            'variant' => $component === 'layout.surface' ? ['standard', 'compact'] : ['primary', 'secondary', 'quiet', 'danger'],
            'size' => $component === 'overlay.modal' ? ['normal', 'wide', 'page'] : ($component === 'feedback.badge' ? ['small', 'normal'] : ($component === 'feedback.progress' ? ['normal', 'large'] : ['small', 'normal', 'large'])),
            'type' => ['button', 'submit', 'reset'], 'method' => ['get', 'post'],
            // What a button or link's htmx GET replaces: its target's inside, or the target itself (a row edited in
            // place), or the inside morphed to match (two lists on one page, each left as it is when the other changes).
            'swap' => ['innerHTML', 'outerHTML', 'innerMorph'],
            'columns' => $component === 'form.grid' ? ['auto', 1, 2, 'wide'] : ($component === 'form.picture-choice' ? ['auto', 1, 4] : ($component === 'data.stat-grid' ? ['auto', 1, 2, 3, 4, 5] : ($component === 'data.card-grid' ? [4, 5] : ($component === 'layout.section-grid' ? ['auto', 1, 2, 3, 4, 'main-side'] : ['auto', 1, 2, 3, 4])))),
            'span' => ['', 'quarter', 'third', 'half', 'two-thirds'],
            'heading_level' => [2, 3], 'density' => ['normal', 'compact'],
        ];
        if ($component === 'overlay.accordion-section') {
            // A picture is one on this site, by its path: never another site's or a script's.
            if (!is_string($values['picture']) || ($values['picture'] !== '' && preg_match('#^/(?!/)[A-Za-z0-9/_.?=&-]+$#', $values['picture']) !== 1)) {
                throw new InvalidArgumentException('An accordion picture is a path on this site.');
            }
            if (!is_string($values['workspace']) || preg_match(self::WORD, $values['workspace']) !== 1) {
                throw new InvalidArgumentException('Invalid semantic UI value: ' . $component . '.workspace');
            }
            $enums['summary_tone'] = ['', 'success', 'danger', 'neutral'];
        }
        // A card named by its heading (aria-labelledby on the card, the id on its heading): each an element's id or nothing.
        foreach (['layout.surface' => 'labelledby', 'layout.section-head' => 'heading_id'] as $owner => $key) {
            if ($component === $owner && (!is_string($values[$key]) || ($values[$key] !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $values[$key]) !== 1))) {
                throw new InvalidArgumentException('Invalid semantic UI value: ' . $component . '.' . $key);
            }
        }
        if ($component === 'action.button' && (!is_string($values['hook']) || preg_match(self::WORD, $values['hook']) !== 1)) {
            throw new InvalidArgumentException('Invalid semantic UI value: ' . $component . '.hook');
        }
        if ($component === 'data.card') {
            $enums['picture'] = ['round', 'square', 'wide', 'none'];
        }
        if ($component === 'data.table') {
            $enums['stack'] = [true, false];
        }
        if ($component === 'data.column-chart') {
            // A chart's columns are its figures, checked by columnChart(), not a grid's number of columns.
            unset($enums['columns']);
        }
        foreach ($enums as $key => $allowed) {
            if (array_key_exists($key, $values) && !in_array($values[$key], $allowed, true)) {
                throw new InvalidArgumentException('Invalid semantic UI value: ' . $component . '.' . $key);
            }
        }
        if ($component === 'feedback.progress') {
            foreach (['min', 'max', 'value'] as $key) {
                if (!is_numeric($values[$key]) || !is_finite((float) $values[$key])) throw new InvalidArgumentException('Progress must be finite.');
                $values[$key] = (float) $values[$key];
            }
            if ($values['max'] <= $values['min']) throw new InvalidArgumentException('Invalid progress range.');
            $values['value'] = max($values['min'], min($values['max'], $values['value']));
        }
        if ($component === 'data.column-chart') {
            $values = self::columnChart($values);
        }
        if ($component === 'data.bar-list') {
            $values = self::barList($values);
        }
        if ($component === 'data.rating') {
            $values = self::rating($values);
        }
        if ($component === 'feedback.countdown') {
            $values = self::countdown($values);
        }
        if ($component === 'form.tree-select') {
            if (!is_string($values['id']) || preg_match('/^[a-z][a-z0-9-]*$/', $values['id']) !== 1 || !is_string($values['name']) || $values['name'] === '' || !is_string($values['selected'])
                || !is_string($values['form']) || ($values['form'] !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $values['form']) !== 1)) {
                throw new InvalidArgumentException('A tree select needs an element ID, a field name and a selected value.');
            }
            if (!in_array($values['mode'], ['submit', 'field'], true)) throw new InvalidArgumentException('A tree select mode is submit or field.');
            if (!is_array($values['items']) || $values['items'] === []) throw new InvalidArgumentException('A tree select needs at least one option.');
            $values['selected_path'] = self::treeSelectOptions($values['items'], null, $values['selected']) ?? '';
        }
        if ($component === 'action.button' && (!is_string($values['form_action']) || ($values['form_action'] !== '' && preg_match('#^/(?!/)#', $values['form_action']) !== 1)
            || !is_string($values['form_target']) || ($values['form_target'] !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $values['form_target']) !== 1))) {
            throw new InvalidArgumentException('A button posts its form only to a path on this site, into a named frame on the page.');
        }
        if ($component === 'form.picture-choice') {
            $values = $this->pictureChoice($values);
        }
        if ($component === 'layout.tabs') {
            if (!is_array($values['items']) || $values['items'] === [] || !is_string($values['current'])) throw new InvalidArgumentException('Tabs need a non-empty item list and a current key.');
            foreach (array_values($values['items']) as $position => $item) {
                $problem = self::keyProblem($item, ['key', 'label', 'href']);
                if ($problem === null && array_filter((array) $item, static fn(mixed $value): bool => !is_string($value) || $value === '') !== []) {
                    $problem = 'each of them must be words';
                }
                if ($problem !== null) {
                    throw new InvalidArgumentException('Each tab has exactly a key, label and href, in any order: tab ' . ($position + 1) . ' ' . $problem . '.');
                }
            }
        }
        if ($component === 'icon' && (preg_match('/^[a-z][a-z0-9-]*$/', (string) $values['name']) !== 1 || (!$values['decorative'] && !$values['label']))) {
            throw new InvalidArgumentException('Icons need a sprite name and meaningful icons need a label.');
        }
        return $values;
    }

    /**
     * A picture choice's values checked: a field name (or ticks named by their values, or one list field), a legend, the
     * selected value or values, and each option a picture on this site, an SVG icon or one of the project's drawings.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function pictureChoice(array $values): array
    {
        $multiple = $values['multiple'];
        $list = $values['list'];
        if ($multiple === true && is_array($values['selected'])) $values['selected'] = array_values($values['selected']);
        if (!is_bool($multiple) || !is_string($list) || !is_bool($values['required']) || !is_bool($values['legend_hidden'])
            || !is_string($values['tick_value']) || preg_match('/^[a-z0-9]+$/', $values['tick_value']) !== 1
            // list: one field's values, so a field name, for feature card checkboxes, and each sends its own value.
            || ($list !== '' && (!$multiple || preg_match(self::FIELD_NAME, $list) !== 1 || $values['tick_value'] !== '1'))
            || (!$multiple && (!is_string($values['name']) || $values['name'] === '' || !is_scalar($values['selected'])))
            || ($multiple && ($values['name'] !== '' || !is_array($values['selected']) || !array_is_list($values['selected']) || array_filter($values['selected'], static fn(mixed $value): bool => !is_string($value) && ($list === '' || !is_int($value))) !== []))
            || !is_string($values['legend']) || $values['legend'] === ''
            || !is_string($values['id']) || ($values['id'] !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $values['id']) !== 1)
            || !is_string($values['form']) || ($values['form'] !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $values['form']) !== 1)
            || !is_string($values['error'])) {
            throw new InvalidArgumentException('A picture choice needs a field name, a legend and a selected value.');
        }
        // Why it was refused, under the legend and help: the element ids that describe the fieldset, from its id, else its
        // field's name ("slide[build]" is slide-build).
        [$values['error_id'], $values['help_id'], $values['described_by']] = ['', '', ''];
        if (trim($values['error']) !== '') {
            $base = $values['id'] !== '' ? $values['id'] : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($multiple ? $list : (is_string($values['name']) ? $values['name'] : ''))), '-');
            if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
                throw new InvalidArgumentException('A picture choice of ticks named by their values, or whose field name starts with no letter, needs an id to show its error: give it id.');
            }
            $values['error_id'] = $base . '-error';
            $values['help_id'] = is_string($values['help']) && trim($values['help']) !== '' ? $base . '-help' : '';
            $values['described_by'] = trim($values['help_id'] . ' ' . $values['error_id']);
        }
        if (!is_array($values['items']) || $values['items'] === []) throw new InvalidArgumentException('A picture choice needs at least one option.');
        foreach (array_values($values['items']) as $position => $item) {
            // Its keys as a set, in any order: value, label, one of image, icon or drawing, then note and disabled if wanted.
            $kinds = is_array($item) ? array_values(array_intersect(['image', 'icon', 'drawing'], array_map('strval', array_keys($item)))) : [];
            $problem = match (true) {
                !is_array($item) => 'is not a list of keys',
                count($kinds) > 1 => 'has ' . implode(' and ', $kinds) . ', where it takes one of them',
                $kinds === [] => 'has none of image, icon or drawing',
                default => self::keyProblem($item, ['value', 'label', $kinds[0]], ['note', 'disabled']),
            };
            [$picture, $icon, $drawing] = [$kinds === ['image'], $kinds === ['icon'], $kinds === ['drawing']];
            if ($problem === null && is_array($item)) {
                $problem = match (true) {
                    !(is_string($item['value']) || is_int($item['value'])) => 'has a value that is neither words nor a whole number',
                    !is_string($item['label']) || $item['label'] === '' => 'has no label in words',
                    !is_string($item['note'] ?? '') => 'has a note that is not words',
                    !is_bool($item['disabled'] ?? false) => 'has a disabled that is not true or false',
                    // A checkbox is named by its value: a field name.
                    $multiple && $list === '' && (!is_string($item['value']) || preg_match(self::FIELD_NAME, $item['value']) !== 1) => 'has a value that is not a field name, which a tick card is named by',
                    $picture && (!is_string($item['image']) || preg_match('#^/(?!/)#', $item['image']) !== 1) => 'has an image that is not a path on this site',
                    $icon && !self::pictureOnly($item['icon']) => 'has an icon that is not an SVG picture free of scripts and outside links',
                    $drawing && !$this->drawingAllowed($item['drawing']) => 'has a drawing the project does not allow',
                    default => null,
                };
            }
            if ($problem !== null) {
                throw new InvalidArgumentException('Each picture choice option has a value, a label, one of image (a picture on this site), icon (an SVG icon) or drawing (a drawn slide), and optionally a note and disabled, in any order: option ' . ($position + 1) . ' ' . $problem . '.');
            }
        }
        return $values;
    }

    /**
     * A column chart's values checked, and what its template draws worked out: each column's height as a share of the
     * tallest, the words a screen reader and a pointer get ("3 Nov: 12 clicks"), which columns are named beneath (about
     * six, half of them on a phone), the scale's top, and whether there is anything to draw at all.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function columnChart(array $values): array
    {
        foreach (['label', 'label_heading', 'value_heading'] as $key) {
            if (!is_string($values[$key]) || trim($values[$key]) === '') throw new InvalidArgumentException('A column chart needs ' . $key . ' in words.');
        }
        if (!is_string($values['top']) || !is_string($values['empty']) || !is_bool($values['table'])) {
            throw new InvalidArgumentException('A column chart\'s top and empty are words and its table true or false.');
        }
        if (!is_array($values['columns']) || !array_is_list($values['columns'])) throw new InvalidArgumentException('A column chart\'s columns are a list.');
        $columns = $values['columns'];
        foreach ($columns as $position => $column) {
            $problem = self::keyProblem($column, ['label', 'value'], ['words', 'href', 'named']) ?? (is_array($column) ? self::figureProblem($column) : null);
            if ($problem === null && is_array($column) && !is_bool($column['named'] ?? false)) {
                $problem = 'has a named that is not true or false';
            }
            if ($problem !== null) {
                throw new InvalidArgumentException('Each column of a column chart has a label and a value, and optionally words, href and named, in any order: column ' . ($position + 1) . ' ' . $problem . '.');
            }
        }
        /** @var list<array{label:string,value:int|float,words?:string,href?:string,named?:bool}> $columns */
        $count = count($columns);
        $tallest = max([0, ...array_map(static fn (array $column): int|float => $column['value'], $columns)]);
        // About six named beneath, the first among them; each one the page names itself is named whatever the step.
        $step = $count <= 8 ? 1 : (int) ceil($count / 6);
        $drawn = [];
        $named = 0;
        $top = '';
        foreach ($columns as $position => $column) {
            $words = ($column['words'] ?? '') !== '' ? (string) $column['words'] : self::numberWords($column['value']);
            $isNamed = $column['named'] ?? ($position % $step === 0);
            $drawn[] = [
                'label' => $column['label'],
                'value' => $column['value'],
                'words' => $words,
                'href' => $column['href'] ?? '',
                'named' => $isNamed,
                // On a phone every other name is left out, so the names beneath never run into each other.
                'wide' => $isNamed && $named++ % 2 === 1,
                'share' => $tallest > 0 ? $column['value'] / $tallest * 100 : 0,
                'title' => $column['label'] . ': ' . $words,
            ];
            if ($top === '' && $column['value'] === $tallest) {
                $top = $words;
            }
        }
        if ($named <= 4) {
            foreach ($drawn as $position => $column) $drawn[$position]['wide'] = false;
        }
        $values['columns'] = $drawn;
        $values['top'] = $values['top'] !== '' ? $values['top'] : ($top !== '' ? $top : '0');
        $values['blank'] = $tallest <= 0;
        // Many columns stand closer together, so a year of days still fits a phone.
        $values['dense'] = $count > 48;

        return $values;
    }

    /**
     * A bar list's values checked, and each bar's length worked out as a share of the longest, or of max when the page
     * gives one (a total, so each bar is its part of it).
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function barList(array $values): array
    {
        if (!is_string($values['label']) || trim($values['label']) === '') throw new InvalidArgumentException('A bar list needs label in words.');
        if (!is_string($values['empty'])) throw new InvalidArgumentException('A bar list\'s empty is words.');
        if (!(is_int($values['max']) || is_float($values['max'])) || !is_finite((float) $values['max']) || $values['max'] < 0) {
            throw new InvalidArgumentException('A bar list\'s max is a number of nothing or more (nothing: the largest value).');
        }
        if (!is_array($values['rows']) || !array_is_list($values['rows'])) throw new InvalidArgumentException('A bar list\'s rows are a list.');
        $rows = $values['rows'];
        foreach ($rows as $position => $row) {
            $problem = self::keyProblem($row, ['label', 'value'], ['words', 'href', 'note']) ?? (is_array($row) ? self::figureProblem($row) : null);
            if ($problem === null && is_array($row) && !is_string($row['note'] ?? '')) {
                $problem = 'has a note that is not words';
            }
            if ($problem !== null) {
                throw new InvalidArgumentException('Each row of a bar list has a label and a value, and optionally words, href and note, in any order: row ' . ($position + 1) . ' ' . $problem . '.');
            }
        }
        /** @var list<array{label:string,value:int|float,words?:string,href?:string,note?:string}> $rows */
        $scale = $values['max'] > 0 ? $values['max'] : max([0, ...array_map(static fn (array $row): int|float => $row['value'], $rows)]);
        $values['rows'] = array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => $row['value'],
            'words' => ($row['words'] ?? '') !== '' ? (string) $row['words'] : self::numberWords($row['value']),
            'href' => $row['href'] ?? '',
            'note' => $row['note'] ?? '',
            'share' => $scale > 0 ? $row['value'] / $scale * 100 : 0,
        ], $rows);
        $values['blank'] = $rows === [];

        return $values;
    }

    /**
     * A rating's values checked, and what its template writes worked out: the mean to one decimal, the whole stars to
     * fill (the mean rounded), the number of reviews with thousands marked, and review or reviews.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function rating(array $values): array
    {
        if (!is_int($values['count']) || $values['count'] < 0) throw new InvalidArgumentException('A rating\'s count is a whole number of nothing or more.');
        $average = $values['average'];
        if (!(is_int($average) || is_float($average)) || !is_finite((float) $average) || $average < 0 || $average > 5) {
            throw new InvalidArgumentException('A rating\'s average is a number from 0 to 5.');
        }
        if (!is_bool($values['compact']) || !is_string($values['empty'])) throw new InvalidArgumentException('A rating\'s compact is true or false and its empty is words.');
        $values['average_words'] = number_format((float) $average, 1);
        $values['filled'] = $values['count'] > 0 ? (int) round((float) $average) : 0;
        $values['count_words'] = number_format($values['count']);
        $values['noun'] = $values['count'] === 1 ? 'review' : 'reviews';

        return $values;
    }

    /**
     * A countdown's values checked, and what its template draws worked out: the time left in its units by the server's
     * clock (the script keeps them ticking from there), whether the moment has passed, and the moment in words and as a
     * machine-readable time, in PHP's time zone (the installation's, which CattoPHP sets at each request).
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function countdown(array $values): array
    {
        if (!is_int($values['ends_at']) || $values['ends_at'] < 1 || !is_int($values['now']) || $values['now'] < 0) {
            throw new InvalidArgumentException('A countdown\'s ends_at is a moment as a Unix time, and its now one too, or 0 for the server\'s clock now.');
        }
        if (!is_string($values['words']) || !is_string($values['until'])) throw new InvalidArgumentException('A countdown\'s words and until are words.');
        if (!in_array($values['largest'], ['week', 'day'], true)) throw new InvalidArgumentException('Invalid semantic UI value: feedback.countdown.largest');
        $now = $values['now'] > 0 ? $values['now'] : time();
        $ends = (new DateTimeImmutable('@' . $values['ends_at']))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $values['now'] = $now;
        $values['ended'] = $values['ends_at'] <= $now;
        $values['units'] = self::timeLeft($values['ends_at'] - $now, $values['largest']);
        $values['moment'] = $ends->format('j F Y \a\t H:i T');
        $values['datetime'] = $ends->format(DATE_ATOM);

        return $values;
    }

    /**
     * The time left in units from the largest down to the seconds, each with its number and its name in words; those
     * before the first that is not nothing are not shown, and the seconds always are. All are given, for the script to
     * show as they are needed.
     *
     * @return list<array{name:string,count:int,words:string,shown:bool}>
     */
    private static function timeLeft(int $seconds, string $largest): array
    {
        $lengths = ['week' => 604800, 'day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1];
        if ($largest === 'day') unset($lengths['week']);
        $left = max(0, $seconds);
        $units = [];
        $shown = false;
        foreach ($lengths as $name => $length) {
            $count = intdiv($left, $length);
            $left -= $count * $length;
            $shown = $shown || $count > 0 || $name === 'second';
            $units[] = ['name' => $name, 'count' => $count, 'words' => $count === 1 ? $name : $name . 's', 'shown' => $shown];
        }

        return $units;
    }

    /**
     * What is wrong with a figure's label, value, words and link, as a column or a bar row has them; null when nothing.
     *
     * @param array<mixed> $figure
     */
    private static function figureProblem(array $figure): ?string
    {
        $value = $figure['value'] ?? null;
        $href = $figure['href'] ?? '';

        return match (true) {
            !is_string($figure['label'] ?? null) || trim($figure['label']) === '' => 'has no label in words',
            !(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value < 0 => 'has a value that is not a number of nothing or more',
            !is_string($figure['words'] ?? '') => 'has words that are not words',
            // A link is a path on this site or a web address, never a script.
            !is_string($href) || ($href !== '' && preg_match('#^(/(?!/)|https?://[^\s/])[^\s]*$#i', $href) !== 1) => 'has an href that is neither a path on this site nor a web address',
            default => null,
        };
    }

    /** A number as a page writes it when it is given no words: whole numbers with thousands marked, others to two places. */
    private static function numberWords(int|float $value): string
    {
        if (is_int($value) || floor($value) === $value) {
            return number_format($value);
        }

        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    /**
     * Checks a tree select's options - {value, label, depth, path} with an optional list of children one level deeper,
     * at most three levels, and depth-0 choices (such as "none") only at the top and without children - and returns the
     * path of the selected one, if any.
     *
     * @param array<mixed> $items
     */
    private static function treeSelectOptions(array $items, ?int $parentDepth, string $selected): ?string
    {
        $found = null;
        foreach ($items as $item) {
            // Its keys as a set, in any order: an option may carry an icon and children.
            $problem = self::keyProblem($item, ['value', 'label', 'depth', 'path'], ['icon', 'children']);
            if ($problem !== null) {
                throw new InvalidArgumentException('Each tree select option has exactly a string value, label and path, a depth from 0 to 3 one deeper than its parent, an optional icon and optional children, in any order: "' . (is_array($item) && is_scalar($item['label'] ?? null) ? (string) $item['label'] : '?') . '" ' . $problem . '.');
            }
            if (!is_array($item)
                || (array_key_exists('icon', $item) && !is_string($item['icon']))
                || !is_string($item['value']) || !is_string($item['label']) || $item['label'] === '' || !is_string($item['path'])
                || !in_array($item['depth'], [0, 1, 2, 3], true)
                || ($parentDepth === null ? $item['depth'] > 1 : $item['depth'] !== $parentDepth + 1)) {
                throw new InvalidArgumentException('Each tree select option has exactly a string value, label and path, a depth from 0 to 3 one deeper than its parent, an optional icon and optional children.');
            }
            // Its icon is drawn inline, as a feature card's is: an SVG picture with nothing that runs and no outside link.
            if (($item['icon'] ?? '') !== '' && !self::pictureOnly($item['icon'])) {
                throw new InvalidArgumentException('A tree select option\'s icon is an SVG picture free of scripts and outside links: "' . $item['label'] . '" has one that is not.');
            }
            if ($item['value'] === $selected) {
                $found = $item['path'];
            }
            if (isset($item['children'])) {
                if (!is_array($item['children']) || ($item['children'] !== [] && ($item['depth'] === 0 || $item['depth'] >= 3))) {
                    throw new InvalidArgumentException('Only a tree select option at depth 1 or 2 may have children.');
                }
                $found = self::treeSelectOptions($item['children'], $item['depth'], $selected) ?? $found;
            }
        }
        return $found;
    }

    /**
     * What is wrong with an item's keys, compared as a set, so their order never matters: the keys it lacks and the keys
     * it does not take, in words; null when they are right.
     *
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function keyProblem(mixed $item, array $required, array $optional = []): ?string
    {
        if (!is_array($item)) {
            return 'is not a list of keys';
        }
        $keys = array_map('strval', array_keys($item));
        $missing = array_values(array_diff($required, $keys));
        $unknown = array_values(array_diff($keys, $required, $optional));
        $said = [];
        if ($missing !== []) {
            $said[] = 'lacks ' . implode(', ', $missing);
        }
        if ($unknown !== []) {
            $said[] = 'has ' . implode(', ', $unknown) . ', which it does not take';
        }

        return $said === [] ? null : implode(' and ', $said);
    }

    /** One of the project's own drawings, as a DrawingCheck it registered recognises. */
    private function drawingAllowed(mixed $drawing): bool
    {
        if (!is_string($drawing)) return false;
        foreach ($this->drawings as $check) {
            if ($check->allows($drawing)) return true;
        }
        return false;
    }

    /** An icon drawn inline: an SVG picture with nothing that runs and no link outside itself (UploadedSvg). */
    private static function pictureOnly(mixed $icon): bool
    {
        if (!is_string($icon) || !str_starts_with(ltrim($icon), '<svg')) return false;
        try {
            UploadedSvg::validate($icon, UploadedSvg::ICON_BYTES, 'icon');
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
