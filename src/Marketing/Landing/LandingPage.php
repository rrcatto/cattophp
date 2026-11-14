<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use DateTimeImmutable;

/**
 * A landing page: a campaign's (shown at the campaign's own address, /c/{campaign}) or an offer's (at /landing/{slug}),
 * never both and never neither. Its reference names it in addresses; its status is draft or published, with when it was
 * first published (a page that has been public is unpublished, never deleted); its title, description and share picture
 * for search engines and shares; and, for a campaign's page, what it does while the campaign is not running.
 */
final class LandingPage
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    /**
     * @param array{source:string,ref:string}|null $sharePicture
     */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly ?int $campaignId,
        public readonly ?string $offerType,
        public readonly ?string $offerReference,
        public readonly ?string $slug,
        public readonly string $status,
        public readonly ?DateTimeImmutable $firstPublishedAt,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly ?array $sharePicture,
        public readonly string $notRunning,
        public readonly ?string $notRunningTo,
        public readonly ?string $createdBy,
        public readonly ?string $updatedBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isCampaignPage(): bool
    {
        return $this->campaignId !== null;
    }

    public function isOfferPage(): bool
    {
        return $this->offerType !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /** Whether visitors have ever been able to see it: such a page is unpublished, never deleted. */
    public function hasBeenPublic(): bool
    {
        return $this->firstPublishedAt !== null;
    }

    /** An offer page's offer as a form's key gives it (type:reference), or null for a campaign's page. */
    public function offerKey(): ?string
    {
        return $this->offerType === null ? null : $this->offerType . ':' . $this->offerReference;
    }
}
