<?php

declare(strict_types=1);

namespace CattoPHP\Document;


/**
 * Checks a whole document template - its syntax, every placeholder against the placeholders its document type allows
 * (a PlaceholderSet, from the project's registry), the HTML and CSS policies - and reports every problem at once.
 * Saving a draft, previewing, publishing and every render go through it, so an unknown placeholder
 * such as {{ invoice.nubmer }} is always an error and never an empty space in a document.
 */
final class TemplateValidator
{
    public const HTML_LIMIT = 200000;
    public const CSS_LIMIT = 100000;

    public function __construct(
        private readonly TemplateParser $parser = new TemplateParser(),
        private readonly HtmlPolicy $html = new HtmlPolicy(),
        private readonly CssPolicy $css = new CssPolicy(),
    ) {}

    /**
     * The template's tree when it is valid. $label names the document type in what is said, such as Invoice.
     *
     * @return list<array<string,mixed>>
     * @throws TemplateValidationException with every problem found
     */
    public function validate(PlaceholderSet $set, string $label, string $html, string $css): array
    {
        $errors = [];
        if (trim($html) === '') {
            $errors[] = 'The template HTML is empty.';
        }
        if (mb_strlen($html) > self::HTML_LIMIT) {
            $errors[] = 'The template HTML is longer than ' . number_format(self::HTML_LIMIT) . ' characters.';
        }
        if (mb_strlen($css) > self::CSS_LIMIT) {
            $errors[] = 'The template CSS is longer than ' . number_format(self::CSS_LIMIT) . ' characters.';
        }
        ['tree' => $tree, 'errors' => $syntax] = $this->parser->read($html);
        $errors = [...$errors, ...$syntax];
        $errors = [...$errors, ...$this->checkTree($tree, $set, null, $label)];

        $policy = $this->html->check($html);
        $errors = [...$errors, ...$policy['errors']];
        foreach ($policy['attribute_placeholders'] as $use) {
            $definition = $set->find($use['path']);
            if ($definition === null) {
                $errors[] = 'Unknown placeholder {{ ' . $use['path'] . ' }} in <' . $use['element'] . ' ' . $use['attribute'] . '>' . self::suggestion($use['path'], $set) . '. Placeholders in attributes cannot use repeating fields.';
            } elseif ($use['attribute'] === 'href' && $definition->kind !== PlaceholderKind::Url) {
                $errors[] = '{{ ' . $use['path'] . ' }} is not a web address, so it cannot be a link’s href.';
            }
        }
        $errors = [...$errors, ...$this->css->check($css)];
        if ($errors !== []) {
            throw new TemplateValidationException(array_values(array_unique($errors)));
        }
        return $tree;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<string>
     */
    private function checkTree(array $nodes, PlaceholderSet $set, ?CollectionDefinition $within, string $label): array
    {
        $errors = [];
        foreach ($nodes as $node) {
            $line = (int) ($node['line'] ?? 0);
            switch ($node['type']) {
                case 'value':
                    $path = (string) $node['path'];
                    if ($set->find($path, $within) === null) {
                        $errors[] = $this->unknown($path, $line, $set, $within, $label);
                    }
                    break;
                case 'each':
                    $name = (string) $node['name'];
                    $collection = $set->collections[$name] ?? null;
                    if ($within !== null) {
                        $errors[] = 'Repeating blocks cannot be nested: {{#each ' . $name . '}} on line ' . $line . ' is inside {{#each ' . $within->name . '}}.';
                    } elseif ($collection === null) {
                        $errors[] = 'Unknown repeating block {{#each ' . $name . '}} on line ' . $line . '. ' . ($set->collections === []
                            ? 'A ' . strtolower($label) . ' has no repeating data.'
                            : 'A ' . strtolower($label) . ' can repeat ' . implode(', ', array_keys($set->collections)) . '.');
                    }
                    $errors = [...$errors, ...$this->checkTree(TemplateParser::children($node['children'] ?? []), $set, $collection ?? $within, $label)];
                    break;
                case 'if':
                    $path = (string) $node['path'];
                    $known = $set->find($path, $within) !== null || ($within === null && isset($set->collections[$path]));
                    if (!$known) {
                        $errors[] = 'Unknown condition {{#if ' . $path . '}} on line ' . $line . self::suggestion($path, $set, $within) . '.';
                    }
                    $errors = [...$errors, ...$this->checkTree(TemplateParser::children($node['children'] ?? []), $set, $within, $label), ...$this->checkTree(TemplateParser::children($node['else'] ?? []), $set, $within, $label)];
                    break;
            }
        }
        return $errors;
    }

    private function unknown(string $path, int $line, PlaceholderSet $set, ?CollectionDefinition $within, string $label): string
    {
        foreach ($set->collections as $collection) {
            if (str_starts_with($path, $collection->alias . '.') && $within === null && isset($collection->fields[substr($path, strlen($collection->alias) + 1)])) {
                return '{{ ' . $path . ' }} on line ' . $line . ' is only available inside {{#each ' . $collection->name . '}} … {{/each}}.';
            }
        }
        return 'Unknown placeholder {{ ' . $path . ' }} on line ' . $line . self::suggestion($path, $set, $within)
            . '. It is not a ' . strtolower($label) . ' placeholder.';
    }

    /** A close registered name, when there is one: "did you mean {{ invoice.number }}?" */
    private static function suggestion(string $path, PlaceholderSet $set, ?CollectionDefinition $within = null): string
    {
        $names = array_keys($set->scalars);
        if ($within !== null) {
            foreach (array_keys($within->fields) as $field) {
                $names[] = $within->alias . '.' . $field;
            }
        }
        $best = null;
        $bestDistance = 4;
        foreach ($names as $name) {
            $distance = levenshtein($path, $name);
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$name, $distance];
            }
        }
        return $best === null ? '' : ' (did you mean {{ ' . $best . ' }}?)';
    }
}
