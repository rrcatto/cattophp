<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;

/** A picture: one picture from a source, with a heading and a caption (200). A page may hold several. */
final class PictureKind extends BaseKind
{
    public function key(): string
    {
        return 'picture';
    }

    public function label(): string
    {
        return 'Picture';
    }

    public function description(): string
    {
        return 'One picture with a caption.';
    }

    public function icon(): string
    {
        return 'image';
    }

    public function once(): bool
    {
        return false;
    }

    public function defaultHeading(): string
    {
        return '';
    }

    public function defaults(): array
    {
        return ['heading' => '', 'picture' => null, 'caption' => ''];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        return ['heading' => $this->heading($input), 'picture' => self::picture($input['picture'] ?? null, 'picture', 'the picture'),
            'caption' => LandingCopy::line($input['caption'] ?? '', 200, 'The caption', 'caption')];
    }

    public function missing(array $data, LandingContext $page): array
    {
        $picture = $data['picture'] ?? null;

        return !is_array($picture) || (($picture['source'] ?? '') !== 'offer' && ($picture['ref'] ?? '') === '') ? ['Picture has no picture chosen.'] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $caption = trim(is_string($data['caption'] ?? null) ? $data['caption'] : '');
        if ($caption !== '') {
            return '"' . $caption . '"';
        }

        return is_array($data['picture'] ?? null) ? 'A picture with no caption' : 'No picture chosen yet';
    }

    public function pictures(array $data): array
    {
        $picture = $data['picture'] ?? null;

        return is_array($picture) && is_string($picture['source'] ?? null) ? [['source' => $picture['source'], 'ref' => is_string($picture['ref'] ?? null) ? $picture['ref'] : '']] : [];
    }

    public function form(LandingContext $page): array
    {
        return ['limits' => ['heading' => 120, 'caption' => 200]];
    }

    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array
    {
        $picture = $this->pictures($data)[0] ?? null;

        return $picture === null ? null : ['heading' => $this->typedHeading($data), 'paragraphs' => [], 'picture' => $picture,
            'caption' => is_string($data['caption'] ?? null) ? $data['caption'] : ''];
    }
}
