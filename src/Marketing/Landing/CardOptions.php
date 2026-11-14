<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * What a section asks of an offer's card: which buttons it shows (view, favourite, buy), whether the campaign's promotion
 * price is shown and which promotion it is (a reference the project understands), and whether the campaign is running.
 */
final class CardOptions
{
    /** @param list<string> $buttons */
    public function __construct(
        public readonly array $buttons = ['view', 'favourite', 'buy'],
        public readonly bool $showPromotion = false,
        public readonly ?string $promotion = null,
        public readonly bool $running = true,
    ) {
    }
}
