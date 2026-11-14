<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use CattoPHP\Marketing\JourneyEvent;
use CattoPHP\Marketing\Offer;

/**
 * Announced by Marketing\Journey after it recorded one of a visitor's events (an offer viewed, a registration, a list
 * subscribed to...): the event as kept, and the offer it was about as the project described it (its label, which the
 * event does not keep), so a listener such as a connected site's reports need read nothing more.
 */
final class JourneyRecorded
{
    public function __construct(public readonly JourneyEvent $event, public readonly ?Offer $offer = null)
    {
    }
}
