<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Events;

use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Offer;
use Symfony\Component\HttpFoundation\Request;

/**
 * An offer's button on a published landing page was pressed and accepted, announced after it was recorded: the page, the
 * offer (none for a button that leads elsewhere), the action (view, favourite, buy, request...; or section, address for a
 * call to action), the section's reference, the request and the reader's account.
 */
final class LandingOfferPressed
{
    public function __construct(
        public readonly LandingPage $page,
        public readonly ?Offer $offer,
        public readonly string $action,
        public readonly Request $request,
        public readonly ?string $account,
        public readonly ?string $section = null,
    ) {
    }
}
