<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * One thing a site sells, as its project describes it to the Campaign Marketing Manager: its type (the project's word, such
 * as product or bundle) and reference (its code), its label, its page, its price and any "was" price in words, whether
 * it can be had now (available) and whether visitors may see it at all (public: a product that is published, whether or
 * not it is on sale). A landing page shows only public offers, and an offer's own page is public only while its offer is.
 * Its slug, when the project gives one (the name in its own page's address), is where its landing page's address starts;
 * without one the address is made from its label.
 */
final class Offer
{
    public function __construct(
        public readonly string $type,
        public readonly string $reference,
        public readonly string $label,
        public readonly ?string $href = null,
        public readonly string $price = '',
        public readonly string $wasPrice = '',
        public readonly bool $available = true,
        public readonly bool $public = true,
        public readonly ?string $slug = null,
    ) {
    }

    /** The offer's key in a form's list of choices: its type and reference, such as product:t-100. */
    public function key(): string
    {
        return $this->type . ':' . $this->reference;
    }
}
