<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * A project's stored document pictures (a certificate's background, a logo, a signature), each named in a template as
 * asset:<sha256> and embedded by the renderer as it draws, so nothing is fetched and a picture never changes.
 */
interface DocumentImages
{
    /** The stored picture as a data: URI, or null when none has that SHA-256. */
    public function dataUri(string $sha): ?string;
}
