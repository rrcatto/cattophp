<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/** A cost of a campaign that belongs to no publication (a designer, a printing run): when, how much, a note and who added it. */
final class SpendItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly int $campaignId,
        public readonly DateTimeImmutable $spentOn,
        public readonly int $amountMinor,
        public readonly string $note,
        public readonly ?string $createdBy,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
