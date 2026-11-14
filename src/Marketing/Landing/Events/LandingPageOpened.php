<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Events;

use CattoPHP\Marketing\Landing\LandingView;
use Symfony\Component\HttpFoundation\Request;

/**
 * A landing page was drawn for a reader (never a preview), announced after its visit was recorded: the view, the request,
 * the reader's account (or none) and whether the visit was recorded (only a person's is; a link preview's or a bot's is
 * not). A project records its own figures from it.
 */
final class LandingPageOpened
{
    public function __construct(
        public readonly LandingView $view,
        public readonly Request $request,
        public readonly ?string $account,
        public readonly bool $recorded,
    ) {
    }
}
