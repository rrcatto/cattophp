<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * What was recorded when a tracking link was opened: the touch's id, the visitor (a person's, kept or new; none for a
 * preview, a bot or a browser asking not to be tracked), the publication the link named, the cookie to give the browser,
 * what opened it (TouchAgent's kind), its country and whether it asked not to be tracked. A campaign's landing page drawn
 * in the same answer records its visit with these (Marketing\Journey::opened()).
 */
final class TrackedOpening
{
    public function __construct(
        public readonly int $touchId,
        public readonly ?int $visitorId,
        public readonly ?int $publicationId,
        public readonly ?Cookie $cookie,
        public readonly string $agent,
        public readonly string $country,
        public readonly bool $doNotTrack,
    ) {
    }
}
