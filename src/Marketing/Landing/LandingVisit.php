<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * Who is reading a landing page and how: their account (as the project names it in the activity log), or none for a
 * visitor; whether it is a preview (its buttons do nothing and nothing is recorded); the page's address, which the
 * buttons' forms come back to; and the page's reference, which every press carries.
 */
final class LandingVisit
{
    public function __construct(
        public readonly ?string $account,
        public readonly bool $preview,
        public readonly string $returnTo,
        public readonly string $pageReference,
    ) {
    }

    /** A preview of a page for its editor: nothing pressed counts and nothing is recorded. */
    public static function preview(LandingPage $page, string $returnTo, ?string $account = null): self
    {
        return new self($account, true, $returnTo, $page->reference);
    }
}
