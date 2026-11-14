<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * One kind of section a landing page can hold: CattoPHP's own (Landing\Kinds\) and a project's alike, found by the tag
 * cattophp.marketing_landing_section (ServiceTags::LANDING_SECTION; autoconfigured). Two kinds with the same key are
 * refused when the kinds are first read.
 *
 * A section stores only its copy and display settings, as one JSON object. What the project knows (an offer's name, price,
 * picture or reviews) is never stored: the page reads it live when it is drawn. Copy is plain text (LandingCopy); a
 * section holds no HTML, style or link of its own. An offer in a section's data is written as its key (type:reference),
 * in a list under "offers" or alone under "offer", so a renamed or removed offer is followed in every kind. A picture is
 * written as {source, ref} (PictureSource), and pictures() names those the section shows.
 *
 * A kind's template names are fixed strings in its class, never anything typed.
 */
interface SectionKind
{
    /** Its key: small letters, digits and underscores, such as hero or faq. */
    public function key(): string;

    /** Its name in the editor. */
    public function label(): string;

    /** What it shows, on its Add a section card. */
    public function description(): string;

    /** The icon of its card, a name from the icon library. */
    public function icon(): string;

    /** Whether a page holds at most one. */
    public function once(): bool;

    /** Whether it is always the full width of the page (the hero, the countdown). */
    public function alwaysFull(): bool;

    /** Whether it can be on this page, such as only on an offer's page. */
    public function appliesTo(LandingContext $page): bool;

    /** The heading shown when its author leaves the heading empty. */
    public function defaultHeading(): string;

    /** @return array<string,mixed> a new section's data: every field present, no copy written for the author */
    public function defaults(): array;

    /**
     * The data as it is stored, from a posted form or from stored data being checked again: anything outside the kind's
     * fields is ignored. A refusal is an InvalidArgumentException in plain words (a CopyRefused naming the field).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function normalise(array $input, LandingContext $page): array;

    /**
     * What stops the page being published while this section is shown, in the editor's words.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public function missing(array $data, LandingContext $page): array;

    /**
     * The editor's one line about the section.
     *
     * @param array<string,mixed> $data
     */
    public function summary(array $data, LandingContext $page): string;

    /**
     * The pictures the section shows, as {source, ref}.
     *
     * @param array<string,mixed> $data
     * @return list<array{source:string,ref:string}>
     */
    public function pictures(array $data): array;

    /** @return array<string,mixed> what its form needs beyond the data: limits, choices */
    public function form(LandingContext $page): array;

    /** Its fields' template, drawn inside CattoPHP's form. */
    public function formTemplate(): string;

    /**
     * What its template draws, words and lists only; null leaves the section off the page.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array;

    /** Its body's template, drawn inside CattoPHP's card. */
    public function template(): string;

    /** @return list<string> what a page holding it must load, such as a player's script */
    public function needs(): array;
}
