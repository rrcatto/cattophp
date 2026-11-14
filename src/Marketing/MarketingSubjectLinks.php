<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\SubjectLinks;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Sites\Site;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the Campaign Marketing Manager's records live, for the activity log's "About": a campaign (subject
 * marketing_campaign, by its code) on its page, a channel (marketing_channel) on the settings page, a landing page
 * (marketing_landing_page, by its reference) in its editor, a connected site (marketing_site, by its key) on the
 * settings page's Connected sites card, and a visitor (marketing_visitor, by its reference) on their journey's page. A
 * campaign deleted since, or renamed to another code, a landing page deleted since and a visitor made anonymous since are
 * named in words alone; so is everything when the project has not imported the pages' routes.
 */
final class MarketingSubjectLinks implements SubjectLinks
{
    /** The anchor of the Connected sites card on the settings page. */
    public const SITES_ANCHOR = 'connected-sites';

    public function __construct(private readonly UrlGeneratorInterface $urls, private readonly Campaigns $campaigns, private readonly ?LandingPages $pages = null,
        private readonly ?Visitors $visitors = null)
    {
    }

    public function href(string $type, string $id): ?string
    {
        try {
            return match ($type) {
                Campaigns::SUBJECT => $this->campaigns->get($id)?->code === strtolower($id) ? $this->urls->generate('cattophp_marketing_campaign', ['code' => strtolower($id)]) : null,
                'marketing_channel' => $this->urls->generate('cattophp_marketing_settings') . '#channels',
                LandingPages::SUBJECT => $this->pages?->get($id) !== null ? $this->urls->generate('cattophp_marketing_landing_edit', ['reference' => strtolower($id)]) : null,
                Site::SUBJECT => $this->urls->generate('cattophp_marketing_settings') . '#' . self::SITES_ANCHOR,
                Visitors::SUBJECT => $this->visitorHref($id),
                default => null,
            };
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /** A visitor's journey, while they are not anonymous. */
    private function visitorHref(string $reference): ?string
    {
        $visitor = $this->visitors?->byReference($reference);

        return $visitor === null || $visitor->isAnonymous() ? null : $this->urls->generate('cattophp_marketing_visitor', ['reference' => $visitor->reference]);
    }
}
