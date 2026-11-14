<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\ActionWords;

/** The words for every action the Campaign Marketing Manager records in the activity log, for the Activity log page and a campaign's history. */
final class MarketingActionWords implements ActionWords
{
    public const WORDS = [
        'marketing.campaign_created' => 'Campaign made',
        'marketing.campaign_changed' => 'Campaign changed',
        'marketing.campaign_activated' => 'Campaign activated',
        'marketing.campaign_paused' => 'Campaign paused',
        'marketing.campaign_archived' => 'Campaign archived',
        'marketing.campaign_restored' => 'Campaign restored',
        'marketing.campaign_copied' => 'Campaign made as a copy',
        'marketing.campaign_deleted' => 'Campaign deleted',
        'marketing.advert_created' => 'Advert made',
        'marketing.advert_changed' => 'Advert changed',
        'marketing.advert_retired' => 'Advert retired',
        'marketing.advert_restored' => 'Advert restored',
        'marketing.advert_deleted' => 'Advert deleted',
        'marketing.publication_created' => 'Publication added',
        'marketing.publication_changed' => 'Publication changed',
        'marketing.publication_retired' => 'Publication retired',
        'marketing.publication_restored' => 'Publication restored',
        'marketing.publication_deleted' => 'Publication deleted',
        'marketing.spend_added' => 'Cost added',
        'marketing.spend_removed' => 'Cost removed',
        'marketing.channel_added' => 'Channel added',
        'marketing.channel_changed' => 'Channel changed',
        'marketing.channels_reordered' => 'Channels put in a new order',
        'marketing.channel_retired' => 'Channel retired',
        'marketing.channel_restored' => 'Channel restored',
        'marketing.channel_deleted' => 'Channel deleted',
        'marketing.landing_page.created' => 'Landing page made',
        'marketing.landing_page.settings_saved' => 'Landing page settings saved',
        'marketing.landing_page.section_added' => 'Landing page section added',
        'marketing.landing_page.section_saved' => 'Landing page section saved',
        'marketing.landing_page.section_shown' => 'Landing page section shown',
        'marketing.landing_page.section_hidden' => 'Landing page section hidden',
        'marketing.landing_page.section_width' => 'Landing page section width changed',
        'marketing.landing_page.section_shown_when' => 'Landing page section shown at another time',
        'marketing.landing_page.section_moved' => 'Landing page section moved',
        'marketing.landing_page.section_removed' => 'Landing page section removed',
        'marketing.landing_page.published' => 'Landing page published',
        'marketing.landing_page.unpublished' => 'Landing page unpublished',
        'marketing.landing_page.deleted' => 'Landing page deleted',
        'marketing.landing_page.version_used' => 'Earlier landing page version used again',
        'marketing.landing_page.template_saved' => 'Landing page saved as a template',
        'marketing.landing_page.template_applied' => 'Landing page started again from a template',
        'marketing.landing_template.saved' => 'Landing page template saved',
        'marketing.landing_template.deleted' => 'Landing page template deleted',
        'marketing.site.added' => 'Connected site added',
        'marketing.site.changed' => 'Connected site changed',
        'marketing.site.secret_changed' => 'Connected site\'s shared secret changed',
        'marketing.site.checked' => 'Connected site\'s connection checked',
        'marketing.site.removed' => 'Connected site removed',
        'marketing.visitor.forgotten' => 'Visitor\'s journey forgotten',
        'marketing.publication.tied' => 'Mailing message counted for a campaign',
        'marketing.publication.untied' => 'Mailing message no longer counted for a campaign',
    ];

    public function words(): array
    {
        return self::WORDS;
    }
}
