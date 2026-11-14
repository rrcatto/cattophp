<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use DateTimeImmutable;

/**
 * Which sales a list, its totals or its offers are of: a period (from, up to but not including until; a paid sale by when
 * it was paid, any other by when it was placed), the state (paid to start; waiting, cancelled or all), the campaign (one,
 * by its id; any campaign; or none), counted for the first or last touch (null: as MARKETING_SALE_COUNTS_FOR says), the
 * channel, publication, advert (through its publications) and place the counted touch came through, the country, an
 * offer sold (type and reference, together), where it was sold (all, here, or one connected site by its key), the buyer
 * (an account, an organisation), and a part of the order's reference. Values that cannot be are refused as it is made.
 */
final class SaleFilter
{
    /** The states a list may be narrowed to. */
    public const STATES = ['paid', 'waiting', 'cancelled', 'all'];

    /** A campaign filter: sales that count for any campaign, or for none. */
    public const ANY_CAMPAIGN = 'any';
    public const NO_CAMPAIGN = 'none';

    /** Where sold: everywhere, here only, or else one connected site's key. */
    public const EVERYWHERE = 'all';
    public const HERE = 'here';

    public function __construct(
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $until = null,
        public readonly string $state = 'paid',
        public readonly int|string|null $campaign = null,
        public readonly ?string $model = null,
        public readonly ?string $channel = null,
        public readonly ?int $publicationId = null,
        public readonly ?int $advertId = null,
        public readonly ?string $place = null,
        public readonly ?string $country = null,
        public readonly ?string $offerType = null,
        public readonly ?string $offerReference = null,
        public readonly string $where = self::EVERYWHERE,
        public readonly ?string $account = null,
        public readonly ?string $organisation = null,
        public readonly string $search = '',
    ) {
        if ($from !== null && $until !== null && $until <= $from) {
            throw new \InvalidArgumentException('A period of sales ends after it starts.');
        }
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(sprintf('Sales are narrowed to paid, waiting, cancelled or all: "%s" is none of them.', $state));
        }
        if (is_string($campaign) && !in_array($campaign, [self::ANY_CAMPAIGN, self::NO_CAMPAIGN], true)) {
            throw new \InvalidArgumentException(sprintf('Sales are narrowed to a campaign by its id, any or none: "%s" is none of them.', $campaign));
        }
        if ($model !== null && !in_array($model, ['first', 'last'], true)) {
            throw new \InvalidArgumentException(sprintf('A sale counts for its first or last touch: "%s" is neither.', $model));
        }
        if (($offerType === null) !== ($offerReference === null)) {
            throw new \InvalidArgumentException('An offer is narrowed to by its type and reference together.');
        }
        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException(sprintf('A country is two capital letters, such as ZA: "%s" is not.', $country));
        }
        if ($where !== self::EVERYWHERE && $where !== self::HERE && preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $where) !== 1) {
            throw new \InvalidArgumentException(sprintf('Where sold is all, here or a connected site\'s key: "%s" is none of them.', $where));
        }
    }

    /** The model it counts by: its own, else the setting's (first or last). */
    public function model(string $setting): string
    {
        return $this->model ?? ($setting === 'first' ? 'first' : 'last');
    }

    /** Whether it narrows to sales of one campaign, by its id. */
    public function campaignId(): ?int
    {
        return is_int($this->campaign) ? $this->campaign : null;
    }

    /** Whether it narrows by anything the counted touch holds, so a sale with no campaign is left out. */
    public function narrowsByTouch(): bool
    {
        return $this->channel !== null || $this->publicationId !== null || $this->advertId !== null || $this->place !== null;
    }

    /** The same sales of another campaign (an id, any, none, or null for every sale). */
    public function withCampaign(int|string|null $campaign): self
    {
        return new self($this->from, $this->until, $this->state, $campaign, $this->model, $this->channel, $this->publicationId, $this->advertId, $this->place, $this->country,
            $this->offerType, $this->offerReference, $this->where, $this->account, $this->organisation, $this->search);
    }

    /** The same sales counted for the first or last touch (null: as the setting says). */
    public function withModel(?string $model): self
    {
        return new self($this->from, $this->until, $this->state, $this->campaign, $model, $this->channel, $this->publicationId, $this->advertId, $this->place, $this->country,
            $this->offerType, $this->offerReference, $this->where, $this->account, $this->organisation, $this->search);
    }

    /** The same sales in another state (paid, waiting, cancelled, all). */
    public function withState(string $state): self
    {
        return new self($this->from, $this->until, $state, $this->campaign, $this->model, $this->channel, $this->publicationId, $this->advertId, $this->place, $this->country,
            $this->offerType, $this->offerReference, $this->where, $this->account, $this->organisation, $this->search);
    }

    /** The same sales sold somewhere else (all, here, or a connected site's key). */
    public function withWhere(string $where): self
    {
        return new self($this->from, $this->until, $this->state, $this->campaign, $this->model, $this->channel, $this->publicationId, $this->advertId, $this->place, $this->country,
            $this->offerType, $this->offerReference, $where, $this->account, $this->organisation, $this->search);
    }
}
