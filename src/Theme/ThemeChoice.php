<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use CattoPHP\Activity\Actor;

/**
 * Where a site keeps which installed theme it is drawn in: the theme's key as chosen, or '' for the project's default.
 * A project gives its own (a stored option, a setting); MemoryThemeChoice keeps it for one run.
 */
interface ThemeChoice
{
    /** The key chosen, as it was saved ('' when none was). */
    public function chosen(): string;

    public function choose(string $key, ?Actor $by): void;
}
