<?php

declare(strict_types=1);

namespace CattoPHP\Network;

/**
 * Tells what is known of an address's network (a country and network database, lists of Tor, VPN and hosting
 * networks): CattoPHP's GeoIpNetworkLookup when `network.country_database` names one, or a project's own
 * (`network.lookup`). Without either, only private addresses are known.
 */
interface NetworkLookup
{
    public function facts(string $address): NetworkFacts;
}
