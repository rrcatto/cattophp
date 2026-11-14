<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\MarketingWiring;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Network\NetworkLookup;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The Campaign Marketing Manager's sales and which campaign gets them (Marketing\Sales), wired by MarketingWiring after
 * the module's own services. Its services go here and nowhere else: SaleAttribution and Sales, both public, so a project
 * may call Sales from anywhere and the pages read it.
 */
final class SalesWiring
{
    private function __construct()
    {
    }

    public static function register(MarketingWiring $wiring): void
    {
        $services = $wiring->services;
        $services->set(SaleAttribution::class)->args([$wiring->connection()])->public();
        $services->set(Sales::class)->args([$wiring->connection(), service(Events::class), service(Visitors::class), service(Touches::class), service(SaleAttribution::class),
            service(MarketingSettings::class), service(NetworkLookup::class), service('request_stack')->nullOnInvalid(), service('event_dispatcher')->nullOnInvalid(), service('logger'),
            service('clock')])->public();
    }
}
