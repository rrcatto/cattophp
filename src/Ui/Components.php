<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

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
        'layout.surface' => ['template' => 'layout/surface', 'defaults' => ['variant' => 'standard', 'section_spacing' => false, 'id' => '', 'sticky' => false, 'tone' => 'neutral']],
        // columns 'main-side': a main column and a narrower side column.
        'layout.section-grid' => ['template' => 'layout/section-grid', 'defaults' => ['columns' => 'auto', 'section_spacing' => true]],
        'layout.section-head' => ['template' => 'layout/section-head', 'defaults' => ['eyebrow' => '', 'heading' => null, 'summary' => '', 'heading_level' => 2]],
        'layout.toolbar' => ['template' => 'layout/toolbar', 'defaults' => []],
        'layout.breadcrumb' => ['template' => 'layout/breadcrumb', 'defaults' => ['items' => null, 'aria_label' => 'Breadcrumb']],
        'layout.tabs' => ['template' => 'layout/tabs', 'defaults' => ['items' => null, 'current' => '', 'aria_label' => null]],
        'action.link' => ['template' => 'actions/link', 'defaults' => ['lookup_clear' => '', 'close_disclosure' => false, 'new_window' => false, 'rel' => '', 'aria_label' => '', 'navigation_key' => '', 'get' => '', 'target' => '', 'select' => '', 'swap' => 'innerHTML', 'push_url' => false, 'indicator' => '', 'label' => null, 'variant' => 'secondary', 'size' => 'normal', 'icon' => '', 'icon_only' => false, 'title' => '', 'id' => '', 'confirm' => '', 'modal' => '', 'full_width' => false, 'href' => null]],
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
        // it all, and columns 1 stacks the cards in a narrow place.
        'form.picture-choice' => ['template' => 'forms/picture-choice', 'defaults' => ['name' => '', 'legend' => null, 'items' => null, 'selected' => '', 'help' => '', 'id' => '', 'size' => 'normal', 'columns' => 'auto', 'form' => '', 'multiple' => false, 'list' => '', 'tick_value' => '1', 'required' => false, 'legend_hidden' => false], 'props' => ['size' => ['normal', 'small'], 'columns' => ['auto', 1, 4]]],
        // form (field mode): the choice belongs to a form elsewhere on the page (a row edited in place).
        'form.tree-select' => ['template' => 'forms/tree-select', 'defaults' => ['id' => null, 'name' => null, 'items' => null, 'selected' => '', 'placeholder' => 'Choose', 'list_label' => null, 'mode' => 'submit', 'form' => '']],
        'form.compact-action' => ['template' => 'forms/compact-action', 'defaults' => []],
        'data.table' => ['template' => 'data/data-table', 'defaults' => ['section_spacing' => false, 'density' => 'normal', 'caption' => '']],
        'data.dataset' => ['template' => 'data/dataset-layout', 'defaults' => ['name' => null, 'heading' => '', 'summary' => '', 'search' => [], 'pagination' => null]],
        'data.stat-grid' => ['template' => 'data/stat-grid', 'defaults' => ['columns' => 'auto', 'density' => 'normal'], 'props' => ['columns' => ['auto', 1, 2, 3, 4, 5]]],
        // A list shown as cards: the grid holds whole rows of cards, 4 or 5 to a row at full width, fewer on a narrow
        // screen; each card a picture, its title, details and actions.
        'data.card-grid' => ['template' => 'data/card-grid', 'defaults' => ['columns' => 4, 'label' => ''], 'props' => ['columns' => [4, 5]]],
        'data.card' => ['template' => 'data/card', 'defaults' => ['title' => '', 'href' => '', 'picture' => 'square'], 'props' => ['picture' => ['round', 'square', 'wide']]],
        'data.stat-card' => ['template' => 'data/stat-card', 'defaults' => ['value' => null, 'label' => null, 'note' => '', 'href' => '', 'size' => 'normal', 'icon' => ''], 'props' => ['size' => ['normal', 'large', 'small']]],
        'data.list' => ['template' => 'data/item-list', 'defaults' => []],
        'data.list-item' => ['template' => 'data/list-item', 'defaults' => []],
        'data.key-value-list' => ['template' => 'data/key-value-list', 'defaults' => ['items' => null]],
        'data.person' => ['template' => 'data/person', 'defaults' => ['name' => null, 'reference' => '', 'href' => '', 'email' => '', 'full' => false]],
        'feedback.badge' => ['template' => 'feedback/badge', 'defaults' => ['label' => null, 'tone' => 'neutral', 'size' => 'normal']],
        'feedback.notice' => ['template' => 'feedback/notice', 'defaults' => ['tone' => 'info', 'heading' => '', 'section_spacing' => false, 'flash' => false, 'solid' => false, 'icon' => '']],
        'feedback.empty' => ['template' => 'feedback/empty-state', 'defaults' => ['heading' => '', 'summary' => '']],
        'feedback.progress' => ['template' => 'feedback/progress', 'defaults' => ['value' => null, 'min' => 0, 'max' => 100, 'label' => null, 'show_value' => false, 'size' => 'normal']],
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
        if ($component === 'action.button' && (!is_string($values['hook']) || preg_match(self::WORD, $values['hook']) !== 1)) {
            throw new InvalidArgumentException('Invalid semantic UI value: ' . $component . '.hook');
        }
        if ($component === 'data.card') {
            $enums['picture'] = ['round', 'square', 'wide'];
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
            foreach ($values['items'] as $item) {
                if (!is_array($item) || array_keys($item) !== ['key', 'label', 'href'] || array_filter($item, static fn(mixed $value): bool => !is_string($value) || $value === '') !== []) {
                    throw new InvalidArgumentException('Each tab has exactly a key, label and href.');
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
            || !is_string($values['form']) || ($values['form'] !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $values['form']) !== 1)) {
            throw new InvalidArgumentException('A picture choice needs a field name, a legend and a selected value.');
        }
        if (!is_array($values['items']) || $values['items'] === []) throw new InvalidArgumentException('A picture choice needs at least one option.');
        foreach ($values['items'] as $item) {
            $keys = is_array($item) ? array_values(array_diff(array_keys($item), ['disabled'])) : [];
            $picture = in_array($keys, [['value', 'label', 'image'], ['value', 'label', 'image', 'note']], true);
            $icon = in_array($keys, [['value', 'label', 'icon'], ['value', 'label', 'icon', 'note']], true);
            $drawing = in_array($keys, [['value', 'label', 'drawing'], ['value', 'label', 'drawing', 'note']], true);
            if ((!$picture && !$icon && !$drawing) || !(is_string($item['value']) || is_int($item['value'])) || !is_string($item['label']) || $item['label'] === '' || !is_string($item['note'] ?? '') || !is_bool($item['disabled'] ?? false)
                // A checkbox is named by its value: a field name.
                || ($multiple && $list === '' && (!is_string($item['value']) || preg_match(self::FIELD_NAME, $item['value']) !== 1))
                || ($picture && (!is_string($item['image']) || preg_match('#^/(?!/)#', $item['image']) !== 1))
                || ($icon && !self::pictureOnly($item['icon']))
                || ($drawing && !$this->drawingAllowed($item['drawing']))) {
                throw new InvalidArgumentException('Each picture choice option has a value, a label, a picture on this site, an SVG icon or a drawn slide, and an optional note.');
            }
        }
        return $values;
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
            $keys = is_array($item) ? array_keys($item) : [];
            // An option may carry an icon, always after its path.
            $keys = array_values(array_filter($keys, static fn(int|string $key): bool => $key !== 'icon'));
            if (($keys !== ['value', 'label', 'depth', 'path'] && $keys !== ['value', 'label', 'depth', 'path', 'children'])
                || (array_key_exists('icon', (array) $item) && !is_string($item['icon']))
                || !is_string($item['value']) || !is_string($item['label']) || $item['label'] === '' || !is_string($item['path'])
                || !in_array($item['depth'], [0, 1, 2, 3], true)
                || ($parentDepth === null ? $item['depth'] > 1 : $item['depth'] !== $parentDepth + 1)) {
                throw new InvalidArgumentException('Each tree select option has exactly a string value, label and path, a depth from 0 to 3 one deeper than its parent, an optional icon and optional children.');
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
