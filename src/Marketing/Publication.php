<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/**
 * One place a campaign was published: its channel, the advert it showed (if any), its code (the last part of its
 * tracking link, unique in the campaign), the place in words, when and where it was published, what it cost, where its
 * link leads (or the campaign's destination), notes, and what it stands for in the project (such as a mailing message).
 * Its code never changes once someone has opened its link; one with clicks is retired, never deleted.
 */
final class Publication
{
    public function __construct(
        public readonly int $id,
        public readonly int $campaignId,
        public readonly string $channelKey,
        public readonly ?int $advertId,
        public readonly string $code,
        public readonly string $place,
        public readonly ?DateTimeImmutable $publishedOn,
        public readonly ?string $publishedAddress,
        public readonly int $costMinor,
        public readonly ?string $destination,
        public readonly string $notes,
        public readonly ?string $sourceType,
        public readonly ?string $sourceReference,
        public readonly ?DateTimeImmutable $retiredAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }
}
