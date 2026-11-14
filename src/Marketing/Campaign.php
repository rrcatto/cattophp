<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/**
 * A campaign: its code (in its tracking links), name, description and objective, the status a person set (draft, active,
 * paused, archived), its dates, budget, where its links lead while it has no landing page, the promotion it carries, and
 * who made it and when, and the connected site it was made for when an arrival from that site made it (originSite, the
 * site's key; null for a campaign made here). What a person sees as its status is worked out from the status and the
 * dates (shownStatus()).
 */
final class Campaign
{
    public const STATUSES = ['draft', 'active', 'paused', 'archived'];

    /** The status a person sees, by its key, in words. */
    public const SHOWN = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended', 'archived' => 'Archived'];

    /** @param list<string> $formerCodes the codes it had before, newest last, so its history keeps them */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $description,
        public readonly string $objective,
        public readonly string $status,
        public readonly ?DateTimeImmutable $startsAt,
        public readonly ?DateTimeImmutable $endsAt,
        public readonly ?int $budgetMinor,
        public readonly string $destination,
        public readonly ?string $promotion,
        public readonly ?string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly array $formerCodes = [],
        public readonly ?string $statusBeforeArchive = null,
        public readonly ?string $originSite = null,
    ) {
    }

    /** Draft, scheduled (active, starting later), active, paused, ended (active, its end passed) or archived. */
    public function shownStatus(DateTimeImmutable $now): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }
        if ($this->startsAt !== null && $this->startsAt > $now) {
            return 'scheduled';
        }
        if ($this->endsAt !== null && $this->endsAt <= $now) {
            return 'ended';
        }

        return 'active';
    }

    /** The status a person sees, in words. */
    public function statusWords(DateTimeImmutable $now): string
    {
        return self::SHOWN[$this->shownStatus($now)];
    }

    /** Whether a click on its links now counts: active and within its dates. */
    public function isRunning(DateTimeImmutable $now): bool
    {
        return $this->shownStatus($now) === 'active';
    }

    /** Why a person's click on its links now is not counted (draft, paused, archived, not_started, ended), or null. */
    public function notCountedBecause(DateTimeImmutable $now): ?string
    {
        return match ($this->shownStatus($now)) {
            'active' => null,
            'scheduled' => 'not_started',
            default => $this->shownStatus($now),
        };
    }

    public function canActivate(): bool
    {
        return in_array($this->status, ['draft', 'paused'], true);
    }

    public function canPause(): bool
    {
        return $this->status === 'active';
    }

    public function canArchive(): bool
    {
        return $this->status !== 'archived';
    }

    public function canRestore(): bool
    {
        return $this->status === 'archived';
    }
}
