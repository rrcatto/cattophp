<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * The icon library: Lucide's line icons (ISC licence, resources/icons/LICENSE-lucide.txt) with their keywords, each drawn
 * as an SVG in the theme's colour. It finds icons by words, suggests the ones that fit a name (a category's, a tag's) and
 * checks that a chosen icon is one of its own; a project adds the subjects its names are about. In templates,
 * lucide('name') draws one (a feature card's icon).
 */
final class IconLibrary
{
    /** Words that say nothing about a picture. */
    private const STOP_WORDS = ['a', 'an', 'and', 'the', 'of', 'for', 'in', 'on', 'to', 'with', 'by', 'at', 'from', 'or', 'your', 'how', 'what'];
    /** Words in a name that say how much or at what level, not what about. */
    private const NAME_STOP_WORDS = ['small', 'large', 'basic', 'basics', 'advanced', 'introduction', 'intro', 'introductory',
        'general', 'applied', 'practical', 'essential', 'essentials', 'fundamentals', 'foundations', 'foundation', 'part',
        'information', 'skills', 'level', 'beginner', 'beginners', 'intermediate', 'guide', 'theory', 'principles', 'other', 'misc'];

    /** @var array<string,array<string,array{body:string,tags:list<string>}>> Each library read once, by its file. */
    private static array $libraries = [];

    private readonly string $path;

    /**
     * @param array<string,list<string>> $subjects A project's subjects, each with the icons that picture it best, tried
     *                                            before the keywords (an icon the library lacks is skipped).
     * @param list<string> $nameStopWords More words a project's names use that say nothing about the picture.
     * @param string|null $path Another library in the same form, else Lucide's.
     */
    public function __construct(private readonly array $subjects = [], private readonly array $nameStopWords = [], ?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 2) . '/resources/icons/lucide.json';
    }

    /** Whether the library has an icon of this name. */
    public function has(string $name): bool
    {
        return isset($this->icons()[$name]);
    }

    /**
     * The words the library gives an icon besides its name (Lucide's tags, such as power, hole and diy for drill), empty
     * when the library has no icon of this name.
     *
     * @return list<string>
     */
    public function tags(string $name): array
    {
        return $this->icons()[$name]['tags'] ?? [];
    }

    /** The icon as an SVG drawn in the theme's colour, or null when the library has no icon of this name. */
    public function svg(string $name): ?string
    {
        $icon = $this->icons()[$name] ?? null;
        if ($icon === null) return null;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"'
            . ' stroke-linecap="round" stroke-linejoin="round">' . $icon['body'] . '</svg>';
    }

    /**
     * The icons that match the words, best first: a word that is an icon's name, then the start of its name, a
     * keyword, the start of a keyword, then a part of its name. An icon must match every word of the query.
     *
     * @return list<string>
     */
    public function search(string $query, int $limit = 48): array
    {
        $words = self::words($query);
        if ($words === []) return [];
        $scores = [];
        foreach ($this->icons() as $name => $icon) {
            $total = 0;
            foreach ($words as $word) {
                $score = self::score($word, $name, $icon['tags']);
                if ($score === 0) { $total = 0; break; }
                $total += $score;
            }
            if ($total > 0) $scores[$name] = $total;
        }
        uksort($scores, static fn(string $a, string $b): int => [$scores[$b], strlen($a), $a] <=> [$scores[$a], strlen($b), $b]);
        return array_slice(array_keys($scores), 0, max(1, $limit));
    }

    /**
     * The icons that fit a name best, for its suggestions: each word of the name scored on its own, so "Property Law"
     * offers buildings and scales, best overall first; the project's subjects first.
     *
     * @return list<string>
     */
    public function suggestionsFor(string $name, int $limit = 24): array
    {
        $scores = [];
        $stop = [...self::NAME_STOP_WORDS, ...$this->nameStopWords];
        $words = array_values(array_filter(self::words($name), static fn(string $word): bool => !in_array($word, $stop, true))) ?: self::words($name);
        foreach ($words as $position => $word) {
            // The first words of a name usually say most about it.
            $weight = max(1, 3 - $position);
            foreach ($this->subjects[$word] ?? [] as $rank => $icon) {
                if ($this->has($icon)) $scores[$icon] = ($scores[$icon] ?? 0) + (150 - $rank * 10) * $weight;
            }
            foreach ($this->icons() as $icon => $entry) {
                $score = self::score($word, $icon, $entry['tags']);
                if ($score > 0) $scores[$icon] = ($scores[$icon] ?? 0) + $score * $weight;
            }
        }
        uksort($scores, static fn(string $a, string $b): int => [$scores[$b], strlen($a), $a] <=> [$scores[$a], strlen($b), $b]);
        return array_slice(array_keys($scores), 0, max(1, $limit));
    }

    /**
     * The icon chosen from the library on a form, or null when none was chosen.
     *
     * @throws \InvalidArgumentException when the name is not one of the library's
     */
    public function chosen(mixed $name): ?string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') return null;
        return $this->svg($name) ?? throw new \InvalidArgumentException('That icon is not in the icon library. Choose one of those shown.');
    }

    /**
     * The library's icons for a form's choice: those that match the words searched for, or else those that fit the
     * name best.
     *
     * @return list<array{value:string,label:string,icon:string}>
     */
    public function choices(string $name, string $query, int $limit = 24): array
    {
        $names = trim($query) !== '' ? $this->search($query, $limit) : $this->suggestionsFor($name, $limit);
        return array_map(fn(string $icon): array => ['value' => $icon, 'label' => str_replace('-', ' ', $icon), 'icon' => (string) $this->svg($icon)], $names);
    }

    /** The name of the icon that fits a name best, or the fallback when nothing does. */
    public function bestFor(string $name, string $fallback): string
    {
        return $this->suggestionsFor($name, 1)[0] ?? $fallback;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [] as $word) {
            if ($word === '' || in_array($word, self::STOP_WORDS, true) || (strlen($word) < 2 && !ctype_digit($word))) continue;
            $words[] = $word;
        }
        return array_values(array_unique($words));
    }

    /** @param list<string> $tags */
    private static function score(string $word, string $name, array $tags): int
    {
        $singular = strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? substr($word, 0, -1) : $word;
        $parts = explode('-', $name);
        $score = 0;
        foreach (array_unique([$word, $singular]) as $form) {
            $score = max($score, match (true) {
                $name === $form => 100,
                in_array($form, $parts, true) => 60,
                in_array($form, $tags, true) => 50,
                str_starts_with($name, $form) => 40,
                array_filter($tags, static fn(string $tag): bool => str_starts_with($tag, $form) || in_array($form, explode(' ', $tag), true)) !== [] => 25,
                strlen($form) >= 4 && str_contains($name, $form) => 15,
                default => 0,
            });
        }
        return $score;
    }

    /** @return array<string,array{body:string,tags:list<string>}> */
    private function icons(): array
    {
        if (!isset(self::$libraries[$this->path])) {
            $library = json_decode((string) file_get_contents($this->path), true, 16, JSON_THROW_ON_ERROR);
            /** @var array<string,array{body:string,tags:list<string>}> $icons */
            $icons = is_array($library) && is_array($library['icons'] ?? null) ? $library['icons'] : [];
            self::$libraries[$this->path] = $icons;
        }
        return self::$libraries[$this->path];
    }
}
