<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;

/** Words: a heading and paragraphs, up to 4,000 characters. A page may hold several. */
final class WordsKind extends BaseKind
{
    public function key(): string
    {
        return 'words';
    }

    public function label(): string
    {
        return 'Words';
    }

    public function description(): string
    {
        return 'A heading and paragraphs.';
    }

    public function icon(): string
    {
        return 'text';
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
        return ['heading' => '', 'text' => ''];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        return ['heading' => $this->heading($input), 'text' => LandingCopy::prose($input['text'] ?? '', 4000, 'The words', 'text')];
    }

    public function missing(array $data, LandingContext $page): array
    {
        return trim(is_string($data['text'] ?? null) ? $data['text'] : '') === '' ? ['Words has no words yet.'] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $heading = $this->typedHeading($data);
        $paragraphs = count(LandingCopy::paragraphs(is_string($data['text'] ?? null) ? $data['text'] : ''));

        return trim(($heading !== '' ? '"' . $heading . '"' : '') . ' · ' . self::plural($paragraphs, 'paragraph', 'paragraphs'), ' ·');
    }

    public function form(LandingContext $page): array
    {
        return ['limits' => ['heading' => 120, 'text' => 4000]];
    }
}
