<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/**
 * A SettingsStore that can say when what was saved could not be read (a damaged or unreadable file), so the Settings
 * page says so while the starting values apply.
 */
interface SettingsStoreProblem
{
    /** Why the saved settings could not be read at the last reading, in plain words, or null. */
    public function readProblem(): ?string;
}
