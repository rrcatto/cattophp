<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\JourneyRecorded;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\MarketingWiring;
use CattoPHP\Marketing\Pages\SiteReportsController;
use CattoPHP\Marketing\Pages\TrackingLinkController;
use CattoPHP\Marketing\Sales\SaleChanged;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Security\SecretCipher;
use CattoPHP\ServiceTags;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Connected sites (the signed forward, the arrival, the reports out and in, their task), wired by MarketingWiring after
 * the module's own services; a scheduled task only while $wiring->scheduler. Its services go here and nowhere else. The
 * reports go by Symfony's HTTP client (service http_client, symfony/http-client); without it nothing is sent and the
 * Connected sites card says so.
 */
final class SitesWiring
{
    private function __construct()
    {
    }

    public static function register(MarketingWiring $wiring): void
    {
        $services = $wiring->services;
        $services->set(Sites::class)->args([$wiring->connection(), service(SecretCipher::class), service(MarketingSettings::class), service('clock'), service(ActivityLog::class),
            service(Installation::class)])->public();
        $services->set(SiteForward::class)->args([service(Sites::class), service(Touches::class), service('clock'), service(Installation::class), service('logger')])->public();
        $services->set(ArrivalListener::class)->args([service(SiteForward::class), service(Sites::class), service(Campaigns::class), service(TrackingLinks::class), service(Touches::class),
            service('clock'), service('logger')])->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => ArrivalListener::PRIORITY]);
        $services->set(SiteReports::class)->args([$wiring->connection(), service(Sites::class), service(Touches::class), service(Visitors::class), service(MarketingSettings::class),
            service('clock'), service(Installation::class), service('http_client')->nullOnInvalid(), service('logger')])->public()
            ->tag('kernel.event_listener', ['event' => JourneyRecorded::class, 'method' => 'onJourneyRecorded'])
            ->tag('kernel.event_listener', ['event' => SaleChanged::class, 'method' => 'onSaleChanged']);
        $services->set(ReportReceiver::class)->args([$wiring->connection(), service(Sites::class), service(Touches::class), service(Events::class), service('clock'),
            service(Sales::class), service(Installation::class), service('logger')])->public();
        $services->set(SiteReportsController::class)->args([service(ReportReceiver::class), service('clock'), service('logger')])->public()->tag('controller.service_arguments');
        // The tracking links sign a forward to a connected site: the controller's last argument.
        $services->get(TrackingLinkController::class)->arg(6, service(SiteForward::class));
        if ($wiring->scheduler) {
            $services->set(MarketingSiteReportsTask::class)->args([service(SiteReports::class)])->tag(ServiceTags::SCHEDULED_TASK, ['priority' => $wiring->marketing['task_priority']]);
        }
    }
}
