<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;

/**
 * A heading, words and a list of short statements, one a line (who it is for, the outcomes). A statement may also be a
 * bold title and its line, drawn as a card, where the kind allows titles.
 */
abstract class ListKind extends BaseKind
{
    /** The most statements a page shows. */
    abstract protected function most(): int;

    /** Whether a statement may have a bold title (80 characters). */
    abstract protected function titles(): bool;

    /** "statement", for "3 statements" and "has no statements yet". */
    abstract protected function item(): string;

    public function once(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return ['heading' => '', 'text' => '', 'items' => []];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        $value = $input['items'] ?? [];
        $items = [];
        if (!is_array($value)) {
            $items = LandingCopy::lines($value, $this->most(), 200, 'The list', 'items');
        } else {
            foreach (array_values($value) as $number => $row) {
                if (is_array($row) && $this->titles()) {
                    $title = LandingCopy::line($row['title'] ?? '', 80, sprintf('The list line %d: Title', $number + 1), 'items.' . $number . '.title');
                    $text = LandingCopy::line($row['text'] ?? '', 200, sprintf('The list line %d', $number + 1), 'items.' . $number . '.text');
                    if ($title !== '' && $text === '') {
                        throw new CopyRefused('items.' . $number . '.text', sprintf('The list line %d needs its words as well as its title.', $number + 1));
                    }
                    if ($text !== '') {
                        $items[] = $title === '' ? $text : ['title' => $title, 'text' => $text];
                    }
                    continue;
                }
                $line = LandingCopy::line(is_array($row) ? ($row['text'] ?? '') : $row, 200, sprintf('The list line %d', $number + 1), 'items.' . $number);
                if ($line !== '') {
                    $items[] = $line;
                }
            }
            if (count($items) > $this->most()) {
                throw new CopyRefused('items', sprintf('The list has %d lines; at most %d fit on the page.', count($items), $this->most()));
            }
        }

        return ['heading' => $this->heading($input), 'text' => $this->text($input), 'items' => $items];
    }

    public function missing(array $data, LandingContext $page): array
    {
        return self::items($data) === [] ? [sprintf('%s has no %ss yet.', $this->label(), $this->item())] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        return self::plural(count(self::items($data)), $this->item(), $this->item() . 's');
    }

    public function form(LandingContext $page): array
    {
        return ['limits' => ['heading' => 120, 'text' => 1000, 'items' => $this->most(), 'item' => 200, 'title' => 80], 'titles' => $this->titles()];
    }

    /** The heading, words and statements, each a line (`text`) with its title when it has one. */
    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array
    {
        $points = [];
        foreach (self::items($data) as $item) {
            $points[] = is_array($item) ? ['title' => (string) ($item['title'] ?? ''), 'text' => (string) ($item['text'] ?? '')] : ['title' => '', 'text' => (string) $item];
        }

        return $this->common($data) + ['items' => array_map(static fn(array $point): string => $point['text'], $points), 'points' => $points];
    }
}
