<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * Something on the overview's "Needs your attention": a campaign ending soon, over its budget, active with no
 * publication, or a publication with no click after a while; the campaign (and publication) it is about, and its words.
 */
final class Attention
{
    public const ENDING = 'ending';
    public const OVER_BUDGET = 'over_budget';
    public const NO_PUBLICATION = 'no_publication';
    public const NO_CLICKS = 'no_clicks';

    public function __construct(
        public readonly string $kind,
        public readonly string $campaignCode,
        public readonly string $campaignName,
        public readonly string $words,
        public readonly ?string $publicationCode = null,
    ) {
    }
}
