<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Events;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\Landing\LandingPage;

/**
 * A landing page was changed, announced after the change and its activity entry: the action (as the activity log names
 * it, such as marketing.landing_page.section_saved), the page as it is now, the section's kind when it was about one, a
 * summary in words, and the details (identifiers and field names, never copy), so a project can keep its own history.
 */
final class LandingPageChanged
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $action,
        public readonly LandingPage $page,
        public readonly ?string $sectionKind,
        public readonly string $summary,
        public readonly array $details,
        public readonly ?Actor $actor,
    ) {
    }
}
