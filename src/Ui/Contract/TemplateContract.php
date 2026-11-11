<?php

declare(strict_types=1);

namespace CattoPHP\Ui\Contract;

use LogicException;

/**
 * Checks of a project's templates, run by its tests, read from their source (a rule about every place a component is
 * used, which a render reaches only on the pages a test happens to draw):
 * - each component's markup has one owner: its classes are written only in its own template, and a table, a window or a
 *   progress bar only by its component, so no page draws its own copy (`duplicates()`);
 * - every button and button-link names the icon that fits its action (`withoutIcon()`), and every icon named is in the
 *   project's sprite (`namedIcons()`, `missingSymbols()`).
 * Templates are given by name with their source; `cattophpTemplates()` gives CattoPHP's own, which draw on every page
 * too. Each check returns the problems it found; none means the templates keep the rule.
 */
final class TemplateContract
{
    /** Each class of CattoPHP's components, with the one template (under @CattoPHP/ui/) that may write it. */
    public const OWNERS = [
        'cl-ui-surface' => 'layout/surface',
        'cl-ui-section-grid' => 'layout/section-grid',
        'cl-ui-section-head' => 'layout/section-head',
        'cl-ui-toolbar' => 'layout/toolbar',
        'cl-ui-breadcrumb' => 'layout/breadcrumb',
        'cl-group-nav' => 'layout/tabs',
        'cl-group-tab' => 'layout/tabs',
        'cl-ui-row-actions' => 'actions/row-actions',
        'cl-ui-row-menu' => 'actions/row-actions',
        'cl-ui-action-group' => 'actions/group',
        'cl-ui-form-grid' => 'forms/form-grid',
        'cl-ui-field' => 'forms/field',
        'cl-ui-form-actions' => 'forms/form-actions',
        'cl-ui-choice' => 'forms/choice-field',
        'cl-ui-compact-action' => 'forms/compact-action',
        'cl-ui-tree-select' => 'forms/tree-select',
        'cl-ui-table' => 'data/data-table',
        'cl-ui-stat-card' => 'data/stat-card',
        'cl-ui-stat-grid' => 'data/stat-grid',
        'cl-ui-card-grid' => 'data/card-grid',
        'cl-ui-card' => 'data/card',
        'cl-ui-list' => 'data/item-list',
        'cl-ui-list-item' => 'data/list-item',
        'cl-ui-key-value-list' => 'data/key-value-list',
        'cl-ui-badge' => 'feedback/badge',
        'cl-ui-notice' => 'feedback/notice',
        'cl-ui-empty-state' => 'feedback/empty-state',
        'cl-ui-progress' => 'feedback/progress',
        'cl-ui-modal' => 'overlay/modal',
        'cl-ui-accordion-section' => 'overlay/accordion-section',
        'cl-ui-icon' => 'helpers/icon',
    ];

    /** Markup only one component draws, by a pattern that finds it, with the template (under @CattoPHP/ui/) that does. */
    public const SIGNATURES = [
        '/<table\b/' => 'data/data-table',
        '/role="dialog"/' => 'overlay/modal',
        '/role="progressbar"/' => 'feedback/progress',
    ];

    private function __construct()
    {
    }

    /**
     * CattoPHP's own templates by name, as a project's Twig knows them (`@CattoPHP/ui/data/card.html.twig`).
     *
     * @return array<string,string>
     */
    public static function cattophpTemplates(): array
    {
        $root = dirname(__DIR__, 3) . '/templates';
        $templates = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig')) {
                $templates['@CattoPHP/' . substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($templates);

        return $templates;
    }

    /** A component template's name, as cattophpTemplates() names it. */
    public static function owner(string $template): string
    {
        return '@CattoPHP/ui/' . $template . '.html.twig';
    }

    /**
     * Templates that write a component's class outside its own template, or draw a table, a window or a progress bar
     * themselves. A project gives its own components' classes in $owners, each with the name of the template that owns it.
     *
     * @param array<string,string> $templates Every template to check, by name, with CattoPHP's if they are to be checked too.
     * @param array<string,string> $owners The project's own classes, each with the name of the template that may write it.
     * @return list<string>
     */
    public static function duplicates(array $templates, array $owners = []): array
    {
        $owners = array_map(self::owner(...), self::OWNERS) + $owners;
        $problems = [];
        foreach ($templates as $name => $source) {
            $source = (string) preg_replace('/\{#.*?#\}/s', '', $source);
            preg_match_all('/class\s*=\s*["\']([^"\']*)["\']/', $source, $attributes);
            foreach ($owners as $class => $owner) {
                if ($name === $owner) continue;
                foreach ($attributes[1] as $attribute) {
                    if (preg_match('/(?<![a-z0-9_-])' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $attribute) === 1) {
                        $problems[] = $name . ' writes ' . $class . ', which ' . $owner . ' owns: draw it with its component instead.';
                        break;
                    }
                }
            }
            foreach (self::SIGNATURES as $pattern => $template) {
                if ($name !== self::owner($template) && preg_match($pattern, $source) === 1) {
                    $problems[] = $name . ' draws what ' . self::owner($template) . ' draws: use its component instead.';
                }
            }
        }

        return $problems;
    }

    /**
     * Each `ui('action.button', {…})` and `ui('action.link', {…})` in the templates: the template's name and the hash of
     * properties as written.
     *
     * @param array<string,string> $templates
     * @return list<array{0:string,1:string}>
     */
    public static function actions(array $templates): array
    {
        $calls = [];
        foreach ($templates as $name => $source) {
            $offset = 0;
            while (preg_match("/ui\\('action\\.(?:button|link)'\\s*,\\s*/", $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $match[0][1] + strlen($match[0][0]);
                if (($source[$start] ?? '') !== '{') throw new LogicException($name . ': an action takes a literal property hash.');
                $end = self::closing($source, $start);
                $calls[] = [$name, substr($source, $start, $end - $start + 1)];
                $offset = $end;
            }
        }

        return $calls;
    }

    /**
     * The actions that name no icon, each as its template's name and its label as written ("page.html.twig 'Save'"),
     * leaving out those in $exceptions, each a reason why that one has none.
     *
     * @param array<string,string> $templates
     * @param array<string,string> $exceptions
     * @return list<string>
     */
    public static function withoutIcon(array $templates, array $exceptions = []): array
    {
        $missing = [];
        foreach (self::actions($templates) as [$name, $hash]) {
            $keys = self::properties($hash);
            if (isset($keys['icon']) || isset($keys['icon_only'])) continue;
            $key = $name . ' ' . ($keys['label'] ?? '');
            if (!array_key_exists($key, $exceptions)) $missing[] = $key;
        }

        return $missing;
    }

    /**
     * Exceptions in the list that no action needs any more: an exception kept after its reason has gone.
     *
     * @param array<string,string> $templates
     * @param array<string,string> $exceptions
     * @return list<string>
     */
    public static function unusedExceptions(array $templates, array $exceptions): array
    {
        $seen = [];
        foreach (self::actions($templates) as [$name, $hash]) {
            $keys = self::properties($hash);
            if (!isset($keys['icon']) && !isset($keys['icon_only'])) $seen[$name . ' ' . ($keys['label'] ?? '')] = true;
        }

        return array_values(array_diff(array_keys($exceptions), array_keys($seen)));
    }

    /**
     * Every icon an action or an Actions menu names as written, with the templates naming it.
     *
     * @param array<string,string> $templates
     * @return array<string,list<string>>
     */
    public static function namedIcons(array $templates): array
    {
        $named = [];
        foreach (self::actions($templates) as [$name, $hash]) {
            $keys = self::properties($hash);
            if (!isset($keys['icon'])) continue;
            // Icons are named by what they mean: an action, a place to go, a page control or a figure.
            preg_match_all("/'((?:action|nav|page|figure)-[a-z0-9-]+)'/", $keys['icon'], $literals);
            foreach ($literals[1] as $icon) $named[$icon][] = $name;
        }
        foreach ($templates as $name => $source) {
            preg_match_all("/ui_props\\('action\\.row', (\\{[^{}]*\\})\\)/", $source, $rows);
            foreach ($rows[1] as $hash) {
                if (preg_match("/icon: '([a-z][a-z0-9-]*)'/", $hash, $icon) === 1) $named[$icon[1]][] = $name;
            }
        }
        ksort($named);

        return $named;
    }

    /**
     * Actions menus that name no icon for their trigger, which is a button too.
     *
     * @param array<string,string> $templates
     * @return list<string>
     */
    public static function menusWithoutIcon(array $templates): array
    {
        $missing = [];
        foreach ($templates as $name => $source) {
            preg_match_all("/ui_props\\('action\\.row', (\\{[^{}]*\\})\\)/", $source, $rows);
            foreach ($rows[1] as $hash) {
                if (str_contains($hash, 'menu: true') && preg_match("/icon: '([a-z][a-z0-9-]*)'/", $hash) !== 1) $missing[] = $name . ' ' . $hash;
            }
        }

        return $missing;
    }

    /**
     * The icons named that the sprite has no symbol for, each with the templates naming it.
     *
     * @param array<string,list<string>> $named As namedIcons() gives them.
     * @return list<string>
     */
    public static function missingSymbols(array $named, string $sprite): array
    {
        $missing = [];
        foreach ($named as $icon => $names) {
            if (!str_contains($sprite, '<symbol id="' . $icon . '"')) $missing[] = $icon . ' (' . implode(', ', array_unique($names)) . ')';
        }

        return $missing;
    }

    /**
     * The hash's own keys and their value expressions as written, not those of hashes nested inside it.
     *
     * @return array<string,string>
     */
    public static function properties(string $hash): array
    {
        $values = [];
        $depth = 0;
        $quote = '';
        $key = '';
        $from = 0;
        for ($i = 0, $length = strlen($hash); $i < $length; $i++) {
            $character = $hash[$i];
            if ($quote !== '') {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($depth === 1 && ($character === ',' || $character === '}') && $key !== '') {
                $values[$key] = trim(substr($hash, $from, $i - $from));
                $key = '';
            }
            if (str_contains('([{', $character)) {
                $depth++;
            } elseif (str_contains(')]}', $character)) {
                $depth--;
            }
            if ($depth === 1 && ($character === '{' || $character === ',') && preg_match('/\G\s*([a-z_]+)\s*:/', $hash, $name, 0, $i + 1) === 1) {
                $key = $name[1];
                $from = $i + 1 + strlen($name[0]);
            }
        }

        return $values;
    }

    /** The index of the bracket closing the one at $start, skipping quoted strings. */
    private static function closing(string $source, int $start): int
    {
        $depth = 0;
        $quote = '';
        for ($i = $start, $length = strlen($source); $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== '') {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = '';
                }
            } elseif ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (str_contains('([{', $character)) {
                $depth++;
            } elseif (str_contains(')]}', $character) && --$depth === 0) {
                return $i;
            }
        }
        throw new LogicException('Unbalanced brackets from offset ' . $start . '.');
    }
}
