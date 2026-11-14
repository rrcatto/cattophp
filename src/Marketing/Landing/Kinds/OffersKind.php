<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\JumpTarget;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\NoProject;

/**
 * The offers, under the project's own heading ("Courses and bundles"): a heading, words, the offers in order (the
 * campaign's to start), the first of which may be marked "First", which buttons each card has (View, Add to favourites,
 * Buy now: feature cards, several) and, on a campaign's page with a promotion, whether the promotion's price is shown.
 * Each card is drawn live from the project (Landing\LandingProject::card()): an offer the project no longer has, or one
 * visitors may not see, is left out.
 */
final class OffersKind extends BaseKind implements JumpTarget
{
    public const MOST = 24;

    /** The buttons a card may have, each with what unticking it does. */
    public const BUTTONS = [
        'view' => ['View', 'Unticked, the name is not a link to its own page.'],
        'favourite' => ['Add to favourites', 'Unticked, visitors cannot favourite from this page.'],
        'buy' => ['Buy now', 'Unticked, they must open its own page to buy.'],
    ];

    public function __construct(private readonly MarketingProject $project = new NoProject())
    {
    }

    public function key(): string
    {
        return 'offers';
    }

    public function label(): string
    {
        return $this->project->offersHeading();
    }

    public function description(): string
    {
        return 'Cards with live prices and buttons.';
    }

    public function icon(): string
    {
        return 'layout-grid';
    }

    public function once(): bool
    {
        return false;
    }

    public function defaultHeading(): string
    {
        return $this->project->offersHeading();
    }

    public function jumpLabel(): string
    {
        return 'See the ' . mb_strtolower($this->project->offersHeading());
    }

    public function defaults(): array
    {
        return ['heading' => '', 'text' => '', 'offers' => [], 'first_marked' => false, 'buttons' => array_keys(self::BUTTONS), 'show_promotion' => false];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        $buttons = [];
        foreach (is_array($input['buttons'] ?? null) ? $input['buttons'] : [] as $button) {
            if (!is_string($button) || !array_key_exists($button, self::BUTTONS)) {
                throw new CopyRefused('buttons', 'Choose the buttons from View, Add to favourites and Buy now.');
            }
            $buttons[$button] = $button;
        }

        return ['heading' => $this->heading($input), 'text' => $this->text($input), 'offers' => self::offers($input['offers'] ?? [], self::MOST, 'offers'),
            'first_marked' => self::flag($input['first_marked'] ?? false),
            // Kept in the buttons' own order whatever order they were ticked in.
            'buttons' => array_values(array_intersect(array_keys(self::BUTTONS), $buttons)),
            'show_promotion' => $page->isCampaignPage() && self::flag($input['show_promotion'] ?? false)];
    }

    public function missing(array $data, LandingContext $page): array
    {
        return self::items($data, 'offers') === [] ? [sprintf('%s has no offers chosen yet.', $this->label())] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $count = count(self::items($data, 'offers'));

        return trim(($this->typedHeading($data) !== '' ? '"' . $this->typedHeading($data) . '"' : '') . ' · ' . self::plural($count, 'chosen', 'chosen') . ($count > 0 && self::flag($data['first_marked'] ?? false) ? ', the first marked First' : ''), ' ·');
    }

    public function form(LandingContext $page): array
    {
        $choices = [];
        foreach ($this->project->offers() as $offer) {
            $choices[$offer->key()] = $offer->label;
        }

        return ['limits' => ['heading' => 120, 'text' => 1000, 'offers' => self::MOST], 'offers' => $choices, 'kinds' => $this->project->offerKinds(),
            'buttons' => array_map(static fn(array $button): array => ['label' => $button[0], 'unticked' => $button[1]], self::BUTTONS),
            'promotion' => $page->isCampaignPage() && $page->promotion !== null];
    }

    /**
     * The heading and words, the keys of the offers to draw (only those the project has now and visitors may see), whether
     * the first is marked First, the buttons and whether the promotion's price is shown (only while the campaign runs).
     * Without an offer to draw the section is left out.
     */
    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array
    {
        $offers = [];
        foreach (self::items($data, 'offers') as $key) {
            $parts = is_string($key) ? explode(':', $key, 2) : [];
            $offer = count($parts) === 2 ? $this->project->offer($parts[0], $parts[1]) : null;
            if ($offer !== null && $offer->public) {
                $offers[] = $offer->key();
            }
        }
        if ($offers === []) {
            return null;
        }
        $original = self::items($data, 'offers');

        return $this->common($data) + ['offers' => $offers, 'first_marked' => self::flag($data['first_marked'] ?? false) && ($original[0] ?? null) === $offers[0],
            'buttons' => self::items($data, 'buttons'), 'show_promotion' => self::flag($data['show_promotion'] ?? false) && $page->running && $page->promotion !== null];
    }
}
