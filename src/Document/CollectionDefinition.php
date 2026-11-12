<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * A registered repeating structure, such as an invoice's lines. A template repeats it with
 * {{#each items}} ... {{/each}}; inside, each entry's fields are named through the collection's alias,
 * {{ item.description }}, and only the fields registered here exist.
 */
final class CollectionDefinition
{
    /** @var array<string,PlaceholderDefinition> keyed by the field's own name, without the alias */
    public readonly array $fields;

    /** @param list<PlaceholderDefinition> $fields named alias.field */
    public function __construct(
        public readonly string $name,
        public readonly string $alias,
        public readonly string $description,
        array $fields,
    ) {
        $byName = [];
        foreach ($fields as $field) {
            $byName[substr($field->name, strlen($alias) + 1)] = $field;
        }
        $this->fields = $byName;
    }
}
