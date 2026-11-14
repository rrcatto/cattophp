<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * A channel a campaign is published on (Facebook, Email, Print...): its key, its label, the prefix of a publication's
 * suggested code (fb for fb1, fb2), its icon from the library, its place in lists, and whether it is offered for new
 * publications (a retired one keeps its history).
 */
final class Channel
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $linkPrefix,
        public readonly string $icon,
        public readonly int $position,
        public readonly bool $active,
        public readonly int $publications = 0,
    ) {
    }

    /** Whether any publication was made on it, so it is retired, never deleted. */
    public function inUse(): bool
    {
        return $this->publications > 0;
    }
}
