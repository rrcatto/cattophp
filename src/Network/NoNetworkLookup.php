<?php

declare(strict_types=1);

namespace CattoPHP\Network;

/** For a project without a network database: an address is private, or nothing is known of it. */
final class NoNetworkLookup implements NetworkLookup
{
    public function facts(string $address): NetworkFacts
    {
        return NetworkFacts::unknown($address);
    }
}
