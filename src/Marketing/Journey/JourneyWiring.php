<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\MarketingWiring;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Network\NetworkLookup;
use Symfony\Component\HttpKernel\KernelEvents;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The Campaign Marketing Manager's journey (Marketing\Journey's readers, a visitor coming back, a person's journey),
 * wired by MarketingWiring after the module's own services. Its services go here and nowhere else.
 */
final class JourneyWiring
{
    /** After the firewall (priority 8), so the signed-in reader is known. */
    private const RETURNS_PRIORITY = -20;

    private function __construct()
    {
    }

    public static function register(MarketingWiring $wiring): void
    {
        $services = $wiring->services;
        $services->set(Readers::class)->args([service(Visitors::class), service(Touches::class), service(MarketingSettings::class), service(NetworkLookup::class),
            service('security.token_storage')->nullOnInvalid(), service('clock')])->public();
        // Marketing\Journey itself is wired with the module's own services; it is given its readers and the current request here.
        $services->get(Journey::class)->arg('$readers', service(Readers::class))->arg('$requests', service('request_stack')->nullOnInvalid());
        $services->set(PersonJourneys::class)->args([$wiring->connection(), service(Visitors::class), service(Touches::class), service(Events::class), service(Campaigns::class),
            service(Publications::class), service(Channels::class), service(MarketingProject::class), service(Sales::class)])->public();
        if ($wiring->marketing['returns']) {
            $services->set(VisitorReturns::class)->args([service(Journey::class), $wiring->marketing['link_prefix'], $wiring->marketing['returns_excluded']])
                ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => self::RETURNS_PRIORITY]);
        }
    }
}
