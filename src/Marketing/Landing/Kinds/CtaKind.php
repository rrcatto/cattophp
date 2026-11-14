<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;

/**
 * A call to action: a closing heading, words (600) and one button. The button buys an offer of the page (the project's
 * compact purchase box, Landing\LandingProject::purchase()), views an offer, goes to a part of the page, or goes to a path
 * on the site; its words are the project's for buying, and the author's (40) otherwise. A page may hold several.
 */
final class CtaKind extends BaseKind
{
    /** What the button does, in words. */
    public const BUTTONS = ['buy' => 'Buy an offer of the page', 'view' => 'View an offer', 'section' => 'Go to a part of the page', 'address' => 'Go to a page of the site'];

    public function key(): string
    {
        return 'cta';
    }

    public function label(): string
    {
        return 'Call to action';
    }

    public function description(): string
    {
        return 'A closing line and one button.';
    }

    public function icon(): string
    {
        return 'mouse-pointer-click';
    }

    public function once(): bool
    {
        return false;
    }

    public function defaultHeading(): string
    {
        return 'Ready to start?';
    }

    public function defaults(): array
    {
        return ['heading' => '', 'text' => '', 'button' => 'buy', 'offer' => '', 'target' => '', 'label' => ''];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        $button = is_string($input['button'] ?? null) ? $input['button'] : 'buy';
        if (!array_key_exists($button, self::BUTTONS)) {
            throw new CopyRefused('button', 'Choose what the button does.');
        }
        // The editor's form holds both, a part of the page (target_section) and a path (target_address), and the button's
        // choice says which counts; stored data has the one as target.
        $target = match ($button) {
            'section' => self::target($input['target_section'] ?? $input['target'] ?? '', 'target'),
            'address' => self::path($input['target_address'] ?? $input['target'] ?? '', 'target'),
            default => '',
        };

        return ['heading' => $this->heading($input), 'text' => $this->text($input, 600), 'button' => $button,
            'offer' => in_array($button, ['buy', 'view'], true) ? self::offerKey($input['offer'] ?? '', 'offer') : '', 'target' => $target === 'none' ? '' : $target,
            'label' => LandingCopy::line($input['label'] ?? '', 40, 'The button\'s words', 'label')];
    }

    public function missing(array $data, LandingContext $page): array
    {
        return match ($data['button'] ?? 'buy') {
            'section' => ($data['target'] ?? '') === '' ? ['Call to action has no part of the page chosen for its button.'] : [],
            'address' => ($data['target'] ?? '') === '' ? ['Call to action has no address for its button.'] : [],
            default => ($data['offer'] ?? '') === '' && $page->offers === [] ? ['Call to action has no offer for its button.'] : [],
        };
    }

    public function summary(array $data, LandingContext $page): string
    {
        $heading = $this->typedHeading($data);

        return $heading !== '' ? '"' . $heading . '"' : (($data['button'] ?? 'buy') === 'buy' ? 'The buy button' : self::BUTTONS[(string) $data['button']] ?? 'The buy button');
    }

    public function form(LandingContext $page): array
    {
        $offers = [];
        foreach ($page->offers as $offer) {
            $offers[$offer->key()] = $offer->label;
        }

        return ['limits' => ['heading' => 120, 'text' => 600, 'label' => 40], 'buttons' => self::BUTTONS, 'offers' => $offers, 'sections' => array_values(array_unique($page->kindsOnPage))];
    }

    /**
     * The heading and words, what the button does, the offer it is about (the one chosen, else the page's first) and where
     * it leads for a part of the page or a path, with its words. The builder draws a buy button with the project's
     * purchase box and turns a part of the page into its anchor.
     */
    public function view(array $data, LandingContext $page, LandingVisit $visit): array
    {
        $button = is_string($data['button'] ?? null) ? $data['button'] : 'buy';
        $offer = is_string($data['offer'] ?? null) && $data['offer'] !== '' ? $data['offer'] : ($page->offer()?->key() ?? '');
        if (in_array($button, ['buy', 'view'], true) && ($offer === '' || $page->offerByKey($offer) === null && $page->isOfferPage())) {
            $offer = $page->offer()?->key() ?? '';
        }

        return $this->common($data) + ['button' => $button, 'offer' => in_array($button, ['buy', 'view'], true) ? $offer : '', 'target' => is_string($data['target'] ?? null) ? $data['target'] : '',
            'label' => is_string($data['label'] ?? null) ? $data['label'] : ''];
    }
}
