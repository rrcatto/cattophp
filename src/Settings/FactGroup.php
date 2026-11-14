<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use InvalidArgumentException;

/**
 * A group of facts the Settings page shows and never changes, from a project's FactsProvider: its heading, a sentence
 * saying what the facts are and why they cannot be changed here, and its lines. Its key, in small letters, gives its
 * section's address on the page (#facts-<key>).
 */
final class FactGroup
{
    /** @var list<Fact> */
    public readonly array $facts;

    /** @param array<int,Fact> $facts */
    public function __construct(
        public readonly string $key,
        public readonly string $heading,
        public readonly string $explanation,
        array $facts,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A group of facts\' key is in small letters: "%s" is not.', $key));
        }
        $this->facts = array_values($facts);
    }
}
