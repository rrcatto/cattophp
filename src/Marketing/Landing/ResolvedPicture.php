<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * A landing page's picture found by its source: a file to serve (its path, type and the file name it is sent with), SVG
 * markup the project vouches for as its own authored drawing, or an address where it is already served.
 */
final class ResolvedPicture
{
    private function __construct(
        public readonly string $kind,
        public readonly ?string $path = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $fileName = null,
        public readonly ?string $markup = null,
        public readonly ?string $address = null,
        public readonly string $alt = '',
    ) {
    }

    public static function file(string $path, string $mimeType, string $fileName, string $alt = ''): self
    {
        return new self('file', path: $path, mimeType: $mimeType, fileName: $fileName, alt: $alt);
    }

    public static function svg(string $markup, string $alt = ''): self
    {
        return new self('svg', markup: $markup, alt: $alt);
    }

    public static function address(string $address, string $alt = ''): self
    {
        return new self('address', address: $address, alt: $alt);
    }

    public function isFile(): bool
    {
        return $this->kind === 'file';
    }
}
