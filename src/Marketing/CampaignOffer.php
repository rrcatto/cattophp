<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/** One offer a campaign promotes: its type and reference in the project, its label when it was chosen, and its place. */
final class CampaignOffer
{
    public function __construct(
        public readonly string $type,
        public readonly string $reference,
        public readonly string $label,
        public readonly int $position,
    ) {
    }

    /** Its key in a form's list of choices, as Offer::key() gives it. */
    public function key(): string
    {
        return $this->type . ':' . $this->reference;
    }
}
