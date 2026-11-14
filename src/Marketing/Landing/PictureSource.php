<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Activity\Actor;

/**
 * Where a landing page's pictures come from, found by the tag cattophp.marketing_landing_picture_source
 * (ServiceTags::LANDING_PICTURE_SOURCE; autoconfigured). A section stores a picture as {source, ref}: the source's key and
 * its own reference for the picture. CattoPHP gives two: upload (pictures uploaded here, kept by Marketing\Pictures) and
 * offer (the offer's own picture, from its card). A project adds its own, such as a library of its files.
 */
interface PictureSource
{
    /** Its key: small letters, digits and underscores. */
    public function key(): string;

    /** Its name on the picture's feature card, such as "Uploaded pictures". */
    public function label(): string;

    /** Whether a page may take pictures from it. */
    public function appliesTo(LandingContext $page): bool;

    /** @return list<array{ref:string,title:string}> the pictures to choose from, for a select */
    public function choices(LandingContext $page): array;

    /** Whether it takes an upload. Who may upload is the page's LandingAccess. */
    public function mayUpload(): bool;

    /**
     * Keeps an uploaded picture and gives its reference.
     *
     * @throws \CattoPHP\Marketing\MarketingRefused when the file is refused, in plain words
     */
    public function upload(string $path, string $fileName, string $title, LandingContext $page, ?Actor $actor = null): string;

    /** Whether a reference still names one of its pictures. */
    public function exists(string $ref, LandingContext $page): bool;

    /** A picture by its reference, or null. */
    public function resolve(string $ref, LandingContext $page): ?ResolvedPicture;
}
