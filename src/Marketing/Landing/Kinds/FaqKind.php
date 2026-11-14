<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\JumpTarget;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;

/** Questions and answers: a heading, words and up to 20 questions (200) with their answers (2,000), closed until opened. A page may hold several. */
final class FaqKind extends BaseKind implements JumpTarget
{
    public function key(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'Questions and answers';
    }

    public function description(): string
    {
        return 'Questions that open to show the answer.';
    }

    public function icon(): string
    {
        return 'circle-help';
    }

    public function once(): bool
    {
        return false;
    }

    public function defaultHeading(): string
    {
        return 'Questions and answers';
    }

    public function jumpLabel(): string
    {
        return 'Questions and answers';
    }

    public function defaults(): array
    {
        return ['heading' => '', 'text' => '', 'items' => []];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        return ['heading' => $this->heading($input), 'text' => $this->text($input),
            'items' => LandingCopy::rows($input['items'] ?? [], ['question' => ['Question', 200, false], 'answer' => ['Answer', 2000, true]], 20, 'Question', 'items')];
    }

    public function missing(array $data, LandingContext $page): array
    {
        return self::items($data) === [] ? ['Questions and answers has no questions yet.'] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $heading = $this->typedHeading($data);

        return trim(($heading !== '' ? '"' . $heading . '"' : '') . ' · ' . self::plural(count(self::items($data)), 'question', 'questions'), ' ·');
    }

    public function form(LandingContext $page): array
    {
        return ['limits' => ['heading' => 120, 'text' => 1000, 'items' => 20, 'question' => 200, 'answer' => 2000, 'spare' => 2]];
    }

    public function view(array $data, LandingContext $page, LandingVisit $visit): array
    {
        return $this->common($data) + ['items' => array_map(static fn(mixed $item): array => ['question' => is_array($item) ? (string) ($item['question'] ?? '') : '',
            'paragraphs' => LandingCopy::paragraphs(is_array($item) ? (string) ($item['answer'] ?? '') : '')], self::items($data))];
    }
}
