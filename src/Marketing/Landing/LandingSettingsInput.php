<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * A landing page's settings as typed on its editor: an offer page's address (its slug), the title and description for
 * search engines and shares, the share picture ({source, ref}, or null for the hero's), and, for a campaign's page, what
 * it does while the campaign is not running and where it sends visitors then.
 */
final class LandingSettingsInput
{
    /** @param array<string,mixed>|null $sharePicture */
    public function __construct(
        public readonly string $slug = '',
        public readonly string $metaTitle = '',
        public readonly string $metaDescription = '',
        public readonly ?array $sharePicture = null,
        public readonly string $notRunning = NotRunning::KEEP,
        public readonly string $notRunningTo = '',
    ) {
    }
}
