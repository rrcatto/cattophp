<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Pictures;

/**
 * The upload picture source: JPEG, PNG and WebP pictures uploaded on a landing page, kept by Marketing\Pictures by their
 * content (the reference is the stored name), up to the size the settings allow. A campaign's page may also choose any of
 * its campaign's picture adverts, which are kept the same way.
 */
final class UploadedPictures implements PictureSource
{
    public const KEY = 'upload';

    public function __construct(private readonly Pictures $pictures, private readonly MarketingSettings $settings = new MarketingSettings(), private readonly ?Adverts $adverts = null)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'A picture uploaded here';
    }

    public function appliesTo(LandingContext $page): bool
    {
        return true;
    }

    public function choices(LandingContext $page): array
    {
        $choices = [];
        if ($page->campaign !== null && $this->adverts !== null) {
            foreach ($this->adverts->list($page->campaign->id, false) as $advert) {
                if ($advert->picture !== null && $this->pictures->read($advert->picture) !== null) {
                    $choices[$advert->picture] = ['ref' => $advert->picture, 'title' => 'The advert ' . $advert->name];
                }
            }
        }

        return array_values($choices);
    }

    public function mayUpload(): bool
    {
        return true;
    }

    public function upload(string $path, string $fileName, string $title, LandingContext $page, ?Actor $actor = null): string
    {
        return $this->pictures->store($path, $this->settings->pictureLargest());
    }

    public function exists(string $ref, LandingContext $page): bool
    {
        return $this->pictures->read($ref) !== null;
    }

    public function resolve(string $ref, LandingContext $page): ?ResolvedPicture
    {
        $picture = $this->pictures->read($ref);

        return $picture === null ? null : ResolvedPicture::file($picture->path, $picture->mimeType, 'picture.' . pathinfo($picture->name, PATHINFO_EXTENSION));
    }
}
