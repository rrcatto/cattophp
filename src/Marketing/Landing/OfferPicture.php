<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\MarketingRefused;

/**
 * The offer picture source: an offer's own picture, as its card gives it (a product's photograph, a course's cover
 * drawing). Its reference is the offer's key (type:reference), or empty for the page's own offer (an offer page's, or a
 * campaign's first). It takes no upload.
 */
final class OfferPicture implements PictureSource
{
    public const KEY = 'offer';

    public function __construct(private readonly LandingProject $project)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'The offer\'s own picture';
    }

    public function appliesTo(LandingContext $page): bool
    {
        return $page->offers !== [];
    }

    public function choices(LandingContext $page): array
    {
        return array_map(static fn($offer): array => ['ref' => $offer->key(), 'title' => $offer->label], $page->offers);
    }

    public function mayUpload(): bool
    {
        return false;
    }

    public function upload(string $path, string $fileName, string $title, LandingContext $page, ?Actor $actor = null): string
    {
        throw MarketingRefused::because('The offer\'s own picture is chosen, not uploaded.');
    }

    public function exists(string $ref, LandingContext $page): bool
    {
        return ($ref === '' ? $page->offer() : $page->offerByKey($ref)) !== null;
    }

    public function resolve(string $ref, LandingContext $page): ?ResolvedPicture
    {
        $offer = $ref === '' ? $page->offer() : $page->offerByKey($ref);
        if ($offer === null) {
            return null;
        }
        $card = $this->project->card($offer, new LandingVisit(null, true, '', $page->page->reference ?? ''), new CardOptions());
        $picture = $card->picture;
        if ($picture === null) {
            return null;
        }

        return match ($picture['kind']) {
            'svg' => ResolvedPicture::svg((string) ($picture['markup'] ?? ''), (string) ($picture['alt'] ?? '')),
            default => ResolvedPicture::address((string) ($picture['src'] ?? ''), (string) ($picture['alt'] ?? '')),
        };
    }
}
