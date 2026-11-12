<?php

declare(strict_types=1);

namespace CattoPHP\Document;

use RuntimeException;

/**
 * One renderer for every document a project draws (previews, issued documents and PDFs alike): a template in the
 * controlled language, checked against the placeholders its type allows (TemplateValidator), filled with data that fits
 * them (each value written by its kind, ValueFormatter), its stored pictures embedded (DocumentImages), inside the page
 * box its settings give. A template that is not valid, or data that does not fit, is an error, never a blank: a project
 * never renders a different template instead.
 */
final class DocumentRenderer
{
    public function __construct(
        private readonly TemplateValidator $validator = new TemplateValidator(),
        private readonly ValueFormatter $formatter = new ValueFormatter(),
        private readonly ?DocumentImages $images = null,
    ) {
    }

    /**
     * A template filled with its data, as a whole HTML document.
     *
     * @param string $type the project's name for the kind of document, such as invoice
     * @param string $label its name in what is said, such as Invoice: an error says "It is not an invoice placeholder"
     * @param array<string,mixed> $data
     * @throws TemplateValidationException when the template is not valid
     * @throws DocumentDataException when the data does not fit the placeholders
     */
    public function render(string $type, string $label, PlaceholderSet $set, string $html, string $css, PageSettings $settings, array $data, ?int $templateId = null, ?int $versionId = null, ?int $versionNumber = null): RenderedDocument
    {
        $tree = $this->validator->validate($set, $label, $html, $css);
        $this->checkData($set, $data);
        $body = $this->embedAssets($this->nodes($tree, $set, $data, null, []));
        $title = ValueFormatter::escape($label);
        $document = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title>'
            . '<style>' . $settings->css() . '</style>'
            . ($css !== '' ? '<style>' . $this->embedAssets($css) . '</style>' : '')
            . '</head><body>' . $body . '</body></html>';

        return new RenderedDocument($type, $document, $body, $settings, $templateId, $versionId, $versionNumber);
    }

    /**
     * Replaces each stored image reference (asset:<sha256>) in an src attribute or a CSS url() with
     * the image itself. A reference to an image that is not stored is an error, never a blank.
     */
    private function embedAssets(string $source): string
    {
        if (!str_contains($source, 'asset:')) {
            return $source;
        }
        $embed = function (string $sha): string {
            $uri = $this->images?->dataUri($sha);
            if ($uri === null) {
                throw new RuntimeException('The document image ' . $sha . ' is not stored.');
            }
            return $uri;
        };
        $source = (string) preg_replace_callback('/(\bsrc=")asset:([0-9a-f]{64})(")/', static fn(array $m): string => $m[1] . $embed($m[2]) . $m[3], $source);
        return (string) preg_replace_callback('/url\(\s*([\'"]?)asset:([0-9a-f]{64})\1\s*\)/', static fn(array $m): string => 'url("' . $embed($m[2]) . '")', $source);
    }

    /** @param array<string,mixed> $data */
    private function checkData(PlaceholderSet $set, array $data): void
    {
        $errors = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (isset($set->collections[$key])) {
                if (!is_array($value) || !array_is_list($value)) {
                    $errors[] = $key . ' must be a list of entries.';
                    continue;
                }
                foreach ($value as $index => $entry) {
                    $errors = [...$errors, ...$this->checkEntry($set->collections[$key], $index, $entry)];
                }
            } elseif (isset($set->scalars[$key])) {
                $problem = $this->formatter->problem($set->scalars[$key], $value);
                if ($problem !== null) $errors[] = $problem;
            } else {
                $errors[] = $key . ' is not a registered placeholder for this document type.';
            }
        }
        foreach ($set->scalars as $name => $definition) {
            if ($definition->required && !$this->formatter->present($definition, $data[$name] ?? null) && !self::zeroMoney($definition, $data[$name] ?? null)) {
                $errors[] = $name . ' is required.';
            }
        }
        if ($errors !== []) {
            throw new DocumentDataException($errors);
        }
    }

    /** @return list<string> */
    private function checkEntry(CollectionDefinition $collection, int $index, mixed $entry): array
    {
        if (!is_array($entry)) {
            return [$collection->name . '[' . $index . '] must be an entry of fields.'];
        }
        $errors = [];
        foreach ($entry as $field => $value) {
            $definition = $collection->fields[(string) $field] ?? null;
            if ($definition === null) {
                $errors[] = $collection->name . '[' . $index . '].' . $field . ' is not a field of ' . $collection->name . '.';
                continue;
            }
            $problem = $this->formatter->problem($definition, $value);
            if ($problem !== null) $errors[] = $collection->name . '[' . $index . ']: ' . $problem;
        }
        foreach ($collection->fields as $field => $definition) {
            if ($definition->required && !array_key_exists($field, $entry)) {
                $errors[] = $collection->name . '[' . $index . '].' . $field . ' is required.';
            }
        }
        return $errors;
    }

    /** A required amount may be zero: a free order still has a total. */
    private static function zeroMoney(PlaceholderDefinition $definition, mixed $value): bool
    {
        return $definition->kind === PlaceholderKind::Money && is_array($value) && ($value['minor'] ?? null) === 0;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed> $data
     * @param array<string,mixed> $entry the current repeating entry's fields
     */
    private function nodes(array $nodes, PlaceholderSet $set, array $data, ?CollectionDefinition $within, array $entry): string
    {
        $out = '';
        foreach ($nodes as $node) {
            switch ($node['type']) {
                case 'text':
                    $out .= (string) $node['text'];
                    break;
                case 'value':
                    [$definition, $value] = $this->resolve((string) $node['path'], $set, $data, $within, $entry);
                    $out .= $this->formatter->format($definition, $value);
                    break;
                case 'each':
                    $collection = $set->collections[(string) $node['name']];
                    foreach ((array) ($data[$collection->name] ?? []) as $item) {
                        $out .= $this->nodes(TemplateParser::children($node['children'] ?? []), $set, $data, $collection, (array) $item);
                    }
                    break;
                case 'if':
                    $path = (string) $node['path'];
                    $present = $within === null && isset($set->collections[$path])
                        ? ((array) ($data[$path] ?? [])) !== []
                        : $this->formatter->present(...$this->resolve($path, $set, $data, $within, $entry));
                    $out .= $this->nodes($present ? TemplateParser::children($node['children'] ?? []) : TemplateParser::children($node['else'] ?? []), $set, $data, $within, $entry);
                    break;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $entry
     * @return array{0:PlaceholderDefinition,1:mixed}
     */
    private function resolve(string $path, PlaceholderSet $set, array $data, ?CollectionDefinition $within, array $entry): array
    {
        $definition = $set->find($path, $within) ?? throw new RuntimeException('Unregistered placeholder ' . $path . '.');
        if ($within !== null && str_starts_with($path, $within->alias . '.')) {
            return [$definition, $entry[substr($path, strlen($within->alias) + 1)] ?? null];
        }
        return [$definition, $data[$path] ?? null];
    }
}
