<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/**
 * An advert of a campaign: its reference (in its page's address), name, kind (words, a picture or a video), headline and
 * words, an outside address (a video's), its stored picture's name, notes, and when it was retired. One used by a
 * publication is retired, never deleted, so history stays.
 */
final class Advert
{
    public const KINDS = ['words' => 'Words', 'picture' => 'Picture', 'video' => 'Video'];

    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly int $campaignId,
        public readonly string $name,
        public readonly string $kind,
        public readonly string $headline,
        public readonly string $words,
        public readonly ?string $mediaAddress,
        public readonly ?string $picture,
        public readonly string $notes,
        public readonly ?DateTimeImmutable $retiredAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly int $publications = 0,
    ) {
    }

    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }

    /** Whether a publication uses it, so it is retired, never deleted. */
    public function inUse(): bool
    {
        return $this->publications > 0;
    }
}
