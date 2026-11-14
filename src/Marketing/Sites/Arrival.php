<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

/**
 * A visitor's arrival by a connected site's tracking link, its signature checked (SiteForward::read()): the site it came
 * from, the campaign's code and name there, the publication's code and its channel there (when the link was one), and
 * the click's reference there, which names the click to that site in the reports back.
 */
final class Arrival
{
    public function __construct(
        public readonly Site $site,
        public readonly string $campaignCode,
        public readonly string $campaignName,
        public readonly ?string $publicationCode,
        public readonly string $channel,
        public readonly string $click,
    ) {
    }
}
