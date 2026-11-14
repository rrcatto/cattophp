<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * A landing page as it is drawn, for the public page, a preview or a kept version (LandingViewBuilder makes it): the
 * page, its context and reader, its address, the hero and the sections in order (each what its kind's view gives, with
 * its reference, kind, anchor and width, and what the builder resolved: offers' cards, pictures, buttons) and in rows (two
 * halves side by side), the head (title, description, canonical address, robots, Open Graph, share picture), what the page
 * must load, the note shown while a campaign is not running, and for a preview its notice and the address back to the
 * editor. Its presses are where each of its GET buttons leads, by the press's name in its /go/{press} address. A view of
 * one section alone (sectionOnly: the editor's preview of the open section) is drawn inside the editor's own page, so it
 * has no preview notice, no page head of its own unless the section is the hero, and no h1. Words and lists only, apart
 * from the page, context and reader.
 */
final class LandingView
{
    /**
     * @param array<string,mixed>|null $hero
     * @param list<array<string,mixed>> $sections
     * @param list<list<array<string,mixed>>> $rows
     * @param array<string,mixed> $meta
     * @param list<string> $needs
     * @param array<string,array{href:string,offer:?string,action:string,section:string}> $presses
     */
    public function __construct(
        public readonly LandingPage $page,
        public readonly LandingContext $context,
        public readonly LandingVisit $visit,
        public readonly string $path,
        public readonly ?array $hero,
        public readonly array $sections,
        public readonly array $rows = [],
        public readonly array $meta = [],
        public readonly array $needs = [],
        public readonly ?string $note = null,
        public readonly string $notice = '',
        public readonly string $back = '',
        public readonly array $presses = [],
        public readonly bool $sectionOnly = false,
    ) {
    }

    public function isPreview(): bool
    {
        return $this->visit->preview;
    }

    /** @return array<string,mixed> what the page's template reads */
    public function toArray(): array
    {
        return ['reference' => $this->page->reference, 'kind' => $this->context->kind, 'preview' => $this->visit->preview, 'path' => $this->path, 'hero' => $this->hero,
            'sections' => $this->sections, 'rows' => $this->rows, 'meta' => $this->meta, 'needs' => $this->needs, 'note' => $this->note, 'notice' => $this->notice, 'back' => $this->back,
            'page_field' => LandingPresses::PAGE_FIELD, 'return_field' => LandingPresses::RETURN_FIELD, 'section_only' => $this->sectionOnly];
    }
}
