<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use DateTimeImmutable;

/**
 * One line of a person's journey (Journey\PersonJourney), as the Visitor page shows it: when, what it comes from (a
 * click on a tracking link, an event, or a money movement of one of their sales), its kind, what happened and a small
 * detail in words, its campaign and publication (with the channel's name), the offer it was about, the sale and amount
 * for an order's lines, which of the person's visitors (devices) it came from, and its badges: First touch and Last touch
 * (from each paid sale's fixed attribution), Registration, Failed and Sale.
 */
final class JourneyLine
{
    public const FROM_TOUCH = 'touch';
    public const FROM_EVENT = 'event';
    public const FROM_MONEY = 'money';

    public const FIRST_TOUCH = 'first_touch';
    public const LAST_TOUCH = 'last_touch';
    public const REGISTRATION = 'registration';
    public const FAILED = 'failed';
    public const SALE = 'sale';

    /** Each badge's words, in the order they are shown. */
    public const BADGES = [self::FIRST_TOUCH => 'First touch', self::LAST_TOUCH => 'Last touch', self::REGISTRATION => 'Registration', self::FAILED => 'Failed', self::SALE => 'Sale'];

    /** @param list<string> $badges */
    public function __construct(
        public readonly DateTimeImmutable $at,
        public readonly string $from,
        public readonly int $id,
        public readonly string $kind,
        public readonly string $words,
        public readonly string $detail = '',
        public readonly ?int $campaignId = null,
        public readonly ?string $campaignName = null,
        public readonly ?string $campaignCode = null,
        public readonly ?int $publicationId = null,
        public readonly ?string $publicationCode = null,
        public readonly ?string $channel = null,
        public readonly ?string $offerType = null,
        public readonly ?string $offerReference = null,
        public readonly ?string $offerLabel = null,
        public readonly ?string $saleReference = null,
        public readonly ?int $amountMinor = null,
        public readonly ?int $visitorId = null,
        public readonly array $badges = [],
        public readonly ?bool $counted = null,
    ) {
    }

    /** The line with a badge more (kept once, in the order BADGES gives). */
    public function withBadge(string $badge): self
    {
        $badges = array_values(array_intersect(array_keys(self::BADGES), [...$this->badges, $badge]));

        return new self($this->at, $this->from, $this->id, $this->kind, $this->words, $this->detail, $this->campaignId, $this->campaignName, $this->campaignCode, $this->publicationId,
            $this->publicationCode, $this->channel, $this->offerType, $this->offerReference, $this->offerLabel, $this->saleReference, $this->amountMinor, $this->visitorId, $badges, $this->counted);
    }

    /** @return list<string> the badges' words */
    public function badgeWords(): array
    {
        return array_map(static fn(string $badge): string => self::BADGES[$badge] ?? $badge, $this->badges);
    }
}
