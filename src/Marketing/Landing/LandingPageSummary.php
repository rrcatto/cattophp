<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * A landing page as the list shows it: the page, its title (the hero's headline, else the campaign's name or the offer's
 * label), what it is for (the campaign's name or the offer's label, and the campaign's code), its address, how many
 * sections it has and its visits over the recent setting.
 */
final class LandingPageSummary
{
    public function __construct(
        public readonly LandingPage $page,
        public readonly string $title,
        public readonly string $about,
        public readonly ?string $campaignCode,
        public readonly string $address,
        public readonly int $sections,
        public readonly int $visits,
    ) {
    }
}
