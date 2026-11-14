<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;

/** Benefits: a heading, words and up to 12 cards, each a title (80) and its explanation (400). A page may hold several. */
final class BenefitsKind extends BaseKind
{
    public function key(): string
    {
        return 'benefits';
    }

    public function label(): string
    {
        return 'Benefits';
    }

    public function description(): string
    {
        return 'Cards, each a benefit with a short explanation.';
    }

    public function icon(): string
    {
        return 'sparkles';
    }

    public function once(): bool
    {
        return false;
    }

    public function defaultHeading(): string
    {
        return 'Why it is worth it';
    }

    public function defaults(): array
    {
        return ['heading' => '', 'text' => '', 'items' => []];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        return ['heading' => $this->heading($input), 'text' => $this->text($input),
            'items' => LandingCopy::rows($input['items'] ?? [], ['title' => ['Title', 80, false], 'text' => ['Explanation', 400, true]], 12, 'Benefit', 'items')];
    }

    public function missing(array $data, LandingContext $page): array
    {
        return self::items($data) === [] ? ['Benefits has no benefits yet.'] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $heading = $this->typedHeading($data);

        return trim(($heading !== '' ? '"' . $heading . '"' : '') . ' · ' . self::plural(count(self::items($data)), 'benefit', 'benefits'), ' ·');
    }

    public function form(LandingContext $page): array
    {
        return ['limits' => ['heading' => 120, 'text' => 1000, 'items' => 12, 'title' => 80, 'explanation' => 400, 'spare' => 2]];
    }

    public function view(array $data, LandingContext $page, LandingVisit $visit): array
    {
        return $this->common($data) + ['items' => array_map(static fn(mixed $item): array => ['title' => is_array($item) ? (string) ($item['title'] ?? '') : '',
            'paragraphs' => LandingCopy::paragraphs(is_array($item) ? (string) ($item['text'] ?? '') : '')], self::items($data))];
    }
}
