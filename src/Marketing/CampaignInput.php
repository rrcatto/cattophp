<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * A campaign as a person typed it on the new or edit page, every value as text: Campaigns checks each and says why any is
 * refused, by its field (name, code, description, objective, starts_at, ends_at, budget, destination, promotion, offers).
 * Dates are typed as 2026-12-01 (the start of that day, or the end of it for the end) or 2026-12-01T09:00; the budget as
 * money is typed (1200, 1 200.50); offers as their keys (type:reference, Offer::key()).
 */
final class CampaignInput
{
    /** @param list<string> $offers */
    public function __construct(
        public readonly string $name = '',
        public readonly string $code = '',
        public readonly string $description = '',
        public readonly string $objective = '',
        public readonly string $startsAt = '',
        public readonly string $endsAt = '',
        public readonly string $budget = '',
        public readonly string $destination = '/',
        public readonly string $promotion = '',
        public readonly array $offers = [],
    ) {
    }

    /** @param array<string,mixed> $input a form's fields by their names, offers as a list (offers[]) */
    public static function fromForm(array $input): self
    {
        $offers = is_array($input['offers'] ?? null) ? array_values(array_filter(array_map(Rows::typed(...), $input['offers']), static fn(string $key): bool => $key !== '')) : [];

        return new self(Rows::typed($input['name'] ?? ''), strtolower(Rows::typed($input['code'] ?? '')), Rows::typed($input['description'] ?? ''), Rows::typed($input['objective'] ?? ''),
            Rows::typed($input['starts_at'] ?? ''), Rows::typed($input['ends_at'] ?? ''), Rows::typed($input['budget'] ?? ''), Rows::typed($input['destination'] ?? ''),
            Rows::typed($input['promotion'] ?? ''), $offers);
    }

    /**
     * A campaign as its edit form starts: what it holds now, as it would be typed.
     *
     * @param list<CampaignOffer> $offers
     */
    public static function of(Campaign $campaign, array $offers): self
    {
        return new self($campaign->name, $campaign->code, $campaign->description, $campaign->objective, $campaign->startsAt?->format('Y-m-d\TH:i') ?? '',
            $campaign->endsAt?->format('Y-m-d\TH:i') ?? '', TypedMoney::typed($campaign->budgetMinor), $campaign->destination, $campaign->promotion ?? '',
            array_map(static fn(CampaignOffer $offer): string => $offer->key(), $offers));
    }
}
