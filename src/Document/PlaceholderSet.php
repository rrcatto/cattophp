<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * The placeholders and repeating collections one document type allows. A name resolves only if it is
 * here; inside a repeating block the collection's own fields resolve through its alias as well.
 */
final class PlaceholderSet
{
    /** @var array<string,PlaceholderDefinition> */
    public readonly array $scalars;
    /** @var array<string,CollectionDefinition> */
    public readonly array $collections;

    /**
     * @param list<PlaceholderDefinition> $scalars
     * @param list<CollectionDefinition> $collections
     */
    public function __construct(array $scalars, array $collections = [])
    {
        $byName = [];
        foreach ($scalars as $definition) {
            $byName[$definition->name] = $definition;
        }
        $this->scalars = $byName;
        $collectionsByName = [];
        foreach ($collections as $collection) {
            $collectionsByName[$collection->name] = $collection;
        }
        $this->collections = $collectionsByName;
    }

    /** The definition a dotted name refers to here, or inside the given repeating block. */
    public function find(string $path, ?CollectionDefinition $within = null): ?PlaceholderDefinition
    {
        if ($within !== null && str_starts_with($path, $within->alias . '.')) {
            return $within->fields[substr($path, strlen($within->alias) + 1)] ?? null;
        }
        return $this->scalars[$path] ?? null;
    }
}
