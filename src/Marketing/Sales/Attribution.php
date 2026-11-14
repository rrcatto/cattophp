<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use DateTimeImmutable;

/**
 * Which campaign a sale counts for, as SaleAttribution works it out at a moment: for the first touch and for the last,
 * the touch (the tracking link followed, or null), the campaign and publication it counts for, and how that was found
 * (link, promotion or site; null when it counts for no campaign); the campaign whose promotion code the sale used (null
 * when none carries it) and how that code was set to count (no_link or always); the counting period as it was, such as
 * 30d; and the moment it was worked out for.
 *
 * When the promotion's campaign wins over a link (always), the side keeps the link's touch as a fact, with the
 * promotion's campaign, no publication and by = promotion: the touch is then the link followed, which did not count.
 */
final class Attribution
{
    public function __construct(
        public readonly ?int $firstTouchId,
        public readonly ?int $lastTouchId,
        public readonly ?int $firstCampaignId,
        public readonly ?int $lastCampaignId,
        public readonly ?int $firstPublicationId,
        public readonly ?int $lastPublicationId,
        public readonly ?string $firstBy,
        public readonly ?string $lastBy,
        public readonly ?int $promotionCampaignId,
        public readonly string $promotionCounts,
        public readonly string $countedWithin,
        public readonly DateTimeImmutable $at,
    ) {
        foreach ([$firstBy, $lastBy] as $by) {
            if ($by !== null && !array_key_exists($by, Sale::BY)) {
                throw new \InvalidArgumentException(sprintf('A sale\'s touch is found by link, promotion or site: "%s" is none of them.', $by));
            }
        }
        if (!in_array($promotionCounts, ['no_link', 'always'], true)) {
            throw new \InvalidArgumentException(sprintf('A promotion code counts no_link or always: "%s" is neither.', $promotionCounts));
        }
    }

    /** The campaign it counts for under a model, first or last, or null: no campaign. */
    public function campaignId(string $model): ?int
    {
        return $model === 'first' ? $this->firstCampaignId : $this->lastCampaignId;
    }

    /** Whether it counts for a campaign under either model. */
    public function hasCampaign(): bool
    {
        return $this->firstCampaignId !== null || $this->lastCampaignId !== null;
    }
}
