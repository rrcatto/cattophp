<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * One registered placeholder: its dotted name, what it holds, and whether a document of its type must
 * always supply it. An optional placeholder may be absent and renders empty; an unregistered name is
 * never accepted.
 */
final class PlaceholderDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly PlaceholderKind $kind = PlaceholderKind::Text,
        public readonly bool $required = false,
    ) {}
}
