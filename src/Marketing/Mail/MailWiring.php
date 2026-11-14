<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Mail;

use CattoPHP\Installation\Installation;
use CattoPHP\Mailing\CampaignProject;
use CattoPHP\Mailing\Campaigns as MailingMessages;
use CattoPHP\Mailing\Copies;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\MarketingWiring;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * A mailing message as a publication (MailingPublications, MailedLinks), wired by MarketingWiring after the module's own
 * services; only while mailing is on as well ($wiring->mailing), the mail project's tracking rules read when its
 * campaigns are on ($wiring->mailingCampaigns). Its services go here and nowhere else.
 */
final class MailWiring
{
    private function __construct()
    {
    }

    public static function register(MarketingWiring $wiring): void
    {
        if (!$wiring->mailing) {
            return;
        }
        $services = $wiring->services;
        $services->set(MailingPublications::class)->args([$wiring->connection(), service(Campaigns::class), service(Publications::class), service(Channels::class),
            service(MailingMessages::class), service(MailingLists::class)])->public();
        $services->set(MailedLinks::class)->args([service(Copies::class), service(MailingPublications::class), service(Campaigns::class), service(TrackingLinks::class),
            service(Visitors::class), service(Events::class), service(Installation::class), $wiring->mailingCampaigns ? service(CampaignProject::class) : null,
            service('event_dispatcher')->nullOnInvalid(), service('logger'), service('clock')])->public();
    }
}
