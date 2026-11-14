<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/**
 * Says in code which settings the installation holds fixed, beside `cattophp.settings.fixed` and `.hidden` in its
 * configuration: for a project that decides by what it finds when it starts. Each is a service tagged
 * `cattophp.fixed_settings` (done automatically for a class implementing this). Each answer is a list of setting names
 * (in capitals, as in .env: APP_URL) or groups' keys (in small letters: sign_in); a group stands for every setting in it.
 * What is fixed keeps the value its configuration gives (.env, the project's starting values or its default), is never
 * changed or reset from the Settings page or the Settings service, and ignores anything saved for it earlier.
 */
interface FixedSettingsProvider
{
    /** @return iterable<string> shown on the Settings page with their values, saying they are fixed */
    public function fixedSettings(): iterable;

    /** @return iterable<string> fixed and left off the Settings page altogether */
    public function hiddenSettings(): iterable;
}
