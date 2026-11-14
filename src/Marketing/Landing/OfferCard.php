<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * One offer's card on a landing page, for one reader, as the project draws it: words and lists only (the page renderer
 * takes no objects). Its picture is {kind: image, src, alt}, or {kind: svg, markup, alt} the project vouches for as its
 * own authored drawing; its badges ("Bundle of 3 courses"), one line, short facts ("12 months of access"), its price, a
 * "was" price and the saving in words, the promotion's price (shown only while the campaign runs and the section asks), a
 * note (why it cannot be bought now), its rating for data.rating ({filled, average, count}), what the reader can do now,
 * places the hero's second button may lead to by key (such as a free preview: {label, href}), and the words the hero shows
 * under its headline when none are written there ($about, such as a product's whole description; its line when empty),
 * which the hero's form also shows as its placeholder.
 */
final class OfferCard
{
    /**
     * @param array{kind:string,src?:string,markup?:string,alt?:string}|null $picture
     * @param list<string> $badges
     * @param list<string> $facts
     * @param array{filled:int,average:string,count:int}|null $rating
     * @param list<OfferAction> $actions
     * @param array<string,array{label:string,href:string}> $links
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $href = '',
        public readonly ?array $picture = null,
        public readonly array $badges = [],
        public readonly string $line = '',
        public readonly array $facts = [],
        public readonly string $price = '',
        public readonly string $was = '',
        public readonly string $saving = '',
        public readonly string $promotionPrice = '',
        public readonly string $note = '',
        public readonly ?array $rating = null,
        public readonly array $actions = [],
        public readonly array $links = [],
        public readonly string $about = '',
    ) {
    }

    /** The words the hero shows under its headline when none are written there: $about, else the card's line. */
    public function heroWords(): string
    {
        return trim($this->about) !== '' ? $this->about : $this->line;
    }

    /** The action of a kind, or null. */
    public function action(string $kind): ?OfferAction
    {
        foreach ($this->actions as $action) {
            if ($action->kind === $kind) {
                return $action;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'href' => $this->href, 'picture' => $this->picture, 'badges' => $this->badges, 'line' => $this->line, 'facts' => $this->facts,
            'price' => $this->price, 'was' => $this->was, 'saving' => $this->saving, 'promotion_price' => $this->promotionPrice, 'note' => $this->note, 'rating' => $this->rating,
            'actions' => array_map(static fn(OfferAction $action): array => $action->toArray(), $this->actions), 'links' => $this->links,
            'about' => $this->about];
    }
}
