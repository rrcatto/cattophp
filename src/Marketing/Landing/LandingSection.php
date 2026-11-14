<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use DateTimeImmutable;

/**
 * One section of a landing page, read back with its kind: its reference (its address in the editor and its anchor's
 * ending), its place (the hero is 0, the others 1 to n), whether it is shown, its width, when it is shown (always, while
 * the campaign runs, while it is not running), its copy and settings checked again against its kind, and why its saved
 * copy no longer passes when it does not (such a section is left off the page and named in the publish checks). A section
 * of a kind the project no longer has keeps its record and has no kind; the editor offers to remove it.
 */
final class LandingSection
{
    public const WIDTHS = ['half', 'full'];
    public const SHOWN_WHEN = ['always' => 'Always', 'running' => 'While the campaign runs', 'not_running' => 'While it is not running'];

    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly int $pageId,
        public readonly string $kindKey,
        public readonly int $position,
        public readonly bool $enabled,
        public readonly string $width,
        public readonly string $shownWhen,
        public readonly array $data,
        public readonly ?SectionKind $kind,
        public readonly string $invalid,
        public readonly bool $unsaved,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isHero(): bool
    {
        return $this->kindKey === 'hero';
    }

    /** Whether the project still has its kind. */
    public function isKnown(): bool
    {
        return $this->kind !== null;
    }

    /** Whether it can be drawn: its kind is known and its saved copy still passes. */
    public function isValid(): bool
    {
        return $this->kind !== null && $this->invalid === '';
    }

    /** Its kind's label in the editor, or what it was when the kind is gone. */
    public function label(): string
    {
        return $this->kind?->label() ?? 'A section of a kind this site no longer has (' . $this->kindKey . ')';
    }
}
