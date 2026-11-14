<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/**
 * Gives the Settings page facts to show that cannot be changed there, such as what the installation's own configuration
 * holds: each a FactGroup of labels and values. Each is a service tagged `cattophp.settings_facts` (done automatically
 * for a class implementing this), asked each time the page is drawn; higher tag priority first. The groups follow the
 * settings on the page.
 */
interface FactsProvider
{
    /** @return iterable<FactGroup> */
    public function settingsFacts(): iterable;
}
