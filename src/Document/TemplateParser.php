<?php

declare(strict_types=1);

namespace CattoPHP\Document;


/**
 * @phpstan-type TemplateNode array{type:string,text?:string,path?:string,name?:string,line?:int,children?:list<array<string,mixed>>,else?:list<array<string,mixed>>}
 */
/**
 * Parses the document template language into a small tree. The language is deliberately not Twig and
 * not a programming language. It has exactly four forms:
 *
 *     {{ invoice.number }}                  a registered placeholder
 *     {{#each items}} ... {{/each}}         repeat a registered collection
 *     {{#if customer.tax_number}} ...       show content when a value is present
 *         {{else}} ... {{/if}}              (the else part is optional)
 *
 * There are no expressions, filters, method calls, includes or variables. Anything else between
 * {{ and }} is an error with its line number, and so are Twig tags, so nothing an administrator types
 * is ever evaluated.
 */
final class TemplateParser
{
    public const PATH = '[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,2}';
    private const NAME = '[a-z][a-z0-9_]*';

    /**
     * @return list<array<string,mixed>> the tree: text, value, each and if nodes
     * @throws TemplateValidationException listing every syntax problem found
     */
    public function parse(string $source): array
    {
        ['tree' => $tree, 'errors' => $errors] = $this->read($source);
        if ($errors !== []) {
            throw new TemplateValidationException($errors);
        }
        return $tree;
    }

    /**
     * The tree as far as it could be read, and every syntax problem. A block left open is closed at
     * the end, so the names inside it can still be checked: an administrator sees an unknown
     * placeholder and an unclosed block together, not one after the other.
     *
     * @return array{tree:list<array<string,mixed>>,errors:list<string>}
     */
    public function read(string $source): array
    {
        $root = ['children' => []];
        /** @var list<array{kind:string,node:array<string,mixed>,line:int,in_else:bool}> $stack */
        $stack = [];
        $errors = [];
        $append = static function (array $node) use (&$root, &$stack): void {
            if ($stack === []) {
                $root['children'][] = $node;
                return;
            }
            $top = count($stack) - 1;
            $stack[$top]['node'][$stack[$top]['in_else'] ? 'else' : 'children'][] = $node;
        };

        $offset = 0;
        $length = strlen($source);
        while ($offset < $length) {
            if (preg_match('/\{\{|\{%|\{#/', $source, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                $append(['type' => 'text', 'text' => substr($source, $offset)]);
                break;
            }
            [$opener, $at] = $match[0];
            if ($at > $offset) {
                $append(['type' => 'text', 'text' => substr($source, $offset, $at - $offset)]);
            }
            $line = substr_count($source, "\n", 0, $at) + 1;
            if ($opener !== '{{') {
                $errors[] = 'Twig tags such as ' . $opener . ' … are not supported (line ' . $line . '). Use {{ placeholder }}, {{#each …}} or {{#if …}}.';
                $append(['type' => 'text', 'text' => $opener]);
                $offset = $at + 2;
                continue;
            }
            $close = strpos($source, '}}', $at + 2);
            if ($close === false) {
                $errors[] = 'A placeholder opened with {{ on line ' . $line . ' is never closed with }}.';
                break;
            }
            $raw = substr($source, $at, $close + 2 - $at);
            $inner = trim(substr($source, $at + 2, $close - $at - 2));
            $offset = $close + 2;

            if (str_contains($inner, '{{') || str_contains($inner, "\n")) {
                $errors[] = 'Malformed placeholder ' . self::shorten($raw) . ' on line ' . $line . ': it must open and close on one line.';
            } elseif (preg_match('/^#each\s+(' . self::NAME . ')$/D', $inner, $m) === 1) {
                $node = ['type' => 'each', 'name' => $m[1], 'line' => $line, 'children' => []];
                $stack[] = ['kind' => 'each', 'node' => $node, 'line' => $line, 'in_else' => false];
            } elseif (preg_match('/^#if\s+(' . self::PATH . '|' . self::NAME . ')$/D', $inner, $m) === 1) {
                $node = ['type' => 'if', 'path' => $m[1], 'line' => $line, 'children' => [], 'else' => []];
                $stack[] = ['kind' => 'if', 'node' => $node, 'line' => $line, 'in_else' => false];
            } elseif ($inner === 'else') {
                $top = count($stack) - 1;
                if ($top < 0 || $stack[$top]['kind'] !== 'if') {
                    $errors[] = '{{else}} on line ' . $line . ' is not inside an {{#if …}} block.';
                } elseif ($stack[$top]['in_else']) {
                    $errors[] = 'The {{#if …}} opened on line ' . $stack[$top]['line'] . ' has a second {{else}} on line ' . $line . '.';
                } else {
                    $stack[$top]['in_else'] = true;
                }
            } elseif (preg_match('/^\/(each|if)$/D', $inner, $m) === 1) {
                $top = count($stack) - 1;
                if ($top < 0) {
                    $errors[] = '{{/' . $m[1] . '}} on line ' . $line . ' closes a block that was never opened.';
                } elseif ($stack[$top]['kind'] !== $m[1]) {
                    $errors[] = '{{/' . $m[1] . '}} on line ' . $line . ' does not match the {{#' . $stack[$top]['kind'] . ' …}} opened on line ' . $stack[$top]['line'] . '.';
                } else {
                    $frame = array_pop($stack);
                    if ($frame !== null) {
                        $append($frame['node']);
                    }
                }
            } elseif (preg_match('/^' . self::PATH . '$/D', $inner) === 1) {
                $append(['type' => 'value', 'path' => $inner, 'line' => $line]);
            } elseif (str_contains($inner, '|')) {
                $errors[] = 'Filters such as ' . self::shorten($raw) . ' are not supported (line ' . $line . '). Values are formatted and escaped for you.';
            } elseif (str_starts_with($inner, '#') || str_starts_with($inner, '/')) {
                $errors[] = 'Unknown block ' . self::shorten($raw) . ' on line ' . $line . '. The blocks are {{#each collection}} … {{/each}} and {{#if placeholder}} … {{/if}}.';
            } elseif (preg_match('/^' . self::NAME . '$/D', $inner) === 1) {
                $errors[] = 'Unknown placeholder ' . self::shorten($raw) . ' on line ' . $line . '. Placeholders have a group and a name, for example {{ invoice.number }}.';
            } else {
                $errors[] = 'Unsupported expression ' . self::shorten($raw) . ' on line ' . $line . '. Only registered placeholders such as {{ invoice.number }} are allowed.';
            }
        }

        $unclosed = [];
        while ($stack !== []) {
            $frame = array_pop($stack);
            $unclosed[] = 'The {{#' . $frame['kind'] . ' …}} opened on line ' . $frame['line'] . ' is never closed with {{/' . $frame['kind'] . '}}.';
            $append($frame['node']);
        }
        /** @var list<array<string,mixed>> $tree */
        $tree = $root['children'];
        return ['tree' => $tree, 'errors' => [...$errors, ...$unclosed]];
    }

    /**
     * A block's children, or its else part, as the list of nodes it is.
     *
     * @return list<array<string,mixed>>
     */
    public static function children(mixed $nodes): array
    {
        /** @var list<array<string,mixed>> $list */
        $list = is_array($nodes) ? array_values($nodes) : [];

        return $list;
    }

    private static function shorten(string $raw): string
    {
        return mb_strlen($raw) > 60 ? mb_substr($raw, 0, 57) . '…' : $raw;
    }
}
