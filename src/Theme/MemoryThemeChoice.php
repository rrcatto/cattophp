<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use CattoPHP\Activity\Actor;

/** The theme chosen, kept for one run: a site with one theme, and tests. */
final class MemoryThemeChoice implements ThemeChoice
{
    public function __construct(private string $key = '')
    {
    }

    public function chosen(): string
    {
        return $this->key;
    }

    public function choose(string $key, ?Actor $by): void
    {
        $this->key = $key;
    }
}
