<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

/**
 * What the actions in the activity log mean, in plain words, for a page that lists the log (Administration > Activity
 * log). CattoPHP gives its own (FoundationActionWords); a project adds words for its own actions with a service that
 * implements this, tagged by autoconfiguration. An action without words is shown as it is named.
 */
interface ActionWords
{
    /** @return array<string,string> each action, such as settings.changed, and its words, such as "Setting changed" */
    public function words(): array;
}
