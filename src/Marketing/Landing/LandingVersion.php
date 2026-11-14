<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use DateTimeImmutable;

/**
 * One kept version of a landing page: its number, whether it is what visitors saw (published) or the moment the page was
 * taken down (unpublished), when, by whom, what changed in words, its content (the page's settings and every section in
 * order) and, worked out from the version after it, until when it was what visitors saw and how it ended.
 */
final class LandingVersion
{
    public const PUBLISHED = 'published';
    public const UNPUBLISHED = 'unpublished';

    /** @param array<string,mixed>|null $content */
    public function __construct(
        public readonly int $id,
        public readonly int $pageId,
        public readonly int $number,
        public readonly string $kind,
        public readonly DateTimeImmutable $at,
        public readonly ?string $madeBy,
        public readonly string $whatChanged,
        public readonly ?array $content,
        public readonly ?DateTimeImmutable $until = null,
        public readonly ?string $endedBy = null,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->kind === self::PUBLISHED;
    }

    /** Whether visitors see it now: published, and nothing came after it. */
    public function isCurrent(): bool
    {
        return $this->isPublished() && $this->until === null;
    }

    /** Its state in words: Published now, Replaced 5 Nov, Taken down 7 Nov, or Taken down for the moment it was taken down. */
    public function state(): string
    {
        if (!$this->isPublished()) {
            return 'Taken down';
        }
        if ($this->until === null) {
            return 'Published now';
        }

        return ($this->endedBy === self::UNPUBLISHED ? 'Taken down ' : 'Replaced ') . $this->until->format('j M');
    }

    /** @return list<array<string,mixed>> its sections in order: kind, reference, enabled, width, shown_when, data */
    public function sections(): array
    {
        $sections = $this->content['sections'] ?? [];

        return is_array($sections) ? array_values(array_filter($sections, 'is_array')) : [];
    }

    /** @return array<string,mixed> the page's settings it was published with */
    public function settings(): array
    {
        $settings = $this->content['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }
}
