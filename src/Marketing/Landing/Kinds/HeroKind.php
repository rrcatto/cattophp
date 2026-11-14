<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;

/**
 * The top of the page, always first and the full width: a small line above the heading, the headline (the offer's name or
 * the campaign's when none is written), words under it, a picture from a source (the offer's own to start), and a second
 * button to a part of the page or to an address the offer's card gives (such as a free preview), with its words. On a
 * campaign's page it may have its own headline and words for while the campaign is not running. The badges and the rating
 * come from the page's offer's card; there is no buy box in the hero.
 */
final class HeroKind extends BaseKind
{
    public function key(): string
    {
        return 'hero';
    }

    public function label(): string
    {
        return 'Hero';
    }

    public function description(): string
    {
        return 'A big heading, words, a picture and a button.';
    }

    public function icon(): string
    {
        return 'panel-top';
    }

    public function once(): bool
    {
        return true;
    }

    public function alwaysFull(): bool
    {
        return true;
    }

    public function defaultHeading(): string
    {
        return '';
    }

    public function defaults(): array
    {
        return ['kicker' => '', 'headline' => '', 'text' => '', 'picture' => ['source' => 'offer', 'ref' => ''], 'secondary' => 'none', 'secondary_label' => '',
            'not_running_headline' => '', 'not_running_text' => ''];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        $secondary = self::target($input['secondary'] ?? 'none', 'secondary');

        return [
            'kicker' => LandingCopy::line($input['kicker'] ?? '', 60, 'The small line above the heading', 'kicker'),
            'headline' => LandingCopy::line($input['headline'] ?? '', 120, 'The headline', 'headline'),
            'text' => LandingCopy::prose($input['text'] ?? '', 600, 'The supporting text', 'text'),
            'picture' => self::picture($input['picture'] ?? null, 'picture', 'the hero picture'),
            'secondary' => $secondary === '' ? 'none' : $secondary,
            'secondary_label' => LandingCopy::line($input['secondary_label'] ?? '', 40, 'The second button\'s label', 'secondary_label'),
            // Only a campaign's page is ever not running.
            'not_running_headline' => $page->isCampaignPage() ? LandingCopy::line($input['not_running_headline'] ?? '', 120, 'The headline while the campaign is not running', 'not_running_headline') : '',
            'not_running_text' => $page->isCampaignPage() ? LandingCopy::prose($input['not_running_text'] ?? '', 600, 'The words while the campaign is not running', 'not_running_text') : '',
        ];
    }

    public function missing(array $data, LandingContext $page): array
    {
        $picture = is_array($data['picture'] ?? null) ? $data['picture'] : null;

        return $picture !== null && ($picture['source'] ?? '') !== 'offer' && ($picture['ref'] ?? '') === '' ? ['The hero has no picture chosen.'] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $headline = trim(is_string($data['headline'] ?? null) ? $data['headline'] : '');
        if ($headline !== '') {
            return '"' . $headline . '"';
        }

        return $page->isCampaignPage() ? 'The campaign\'s name as the headline' : 'The ' . $page->offerWord . '\'s name as the headline';
    }

    public function pictures(array $data): array
    {
        $picture = $data['picture'] ?? null;

        return is_array($picture) && is_string($picture['source'] ?? null) ? [['source' => $picture['source'], 'ref' => is_string($picture['ref'] ?? null) ? $picture['ref'] : '']] : [];
    }

    public function form(LandingContext $page): array
    {
        return ['limits' => ['kicker' => 60, 'headline' => 120, 'text' => 600, 'secondary_label' => 40, 'not_running_headline' => 120, 'not_running_text' => 600],
            'not_running' => $page->isCampaignPage(), 'placeholder' => $page->isCampaignPage() ? ($page->campaign->name ?? '') : ($page->offer()->label ?? '')];
    }

    /**
     * The hero's copy for the page: the headline as written (or, while a campaign is not running, its not-running headline
     * when it has one), the name to use when none is written, the words as paragraphs, the picture to draw and where its
     * second button leads. The builder adds the offer's card (badges, rating, picture, its line when no words are written)
     * and turns the second button's target into an address on the page.
     */
    public function view(array $data, LandingContext $page, LandingVisit $visit): array
    {
        $headline = is_string($data['headline'] ?? null) ? $data['headline'] : '';
        $text = is_string($data['text'] ?? null) ? $data['text'] : '';
        $notRunning = $page->isCampaignPage() && !$page->running;
        if ($notRunning && is_string($data['not_running_headline'] ?? null) && $data['not_running_headline'] !== '') {
            $headline = $data['not_running_headline'];
            $text = is_string($data['not_running_text'] ?? null) ? $data['not_running_text'] : '';
        }

        return [
            'kicker' => $notRunning ? '' : (is_string($data['kicker'] ?? null) ? $data['kicker'] : ''),
            'headline' => $headline,
            'fallback_headline' => $page->isCampaignPage() ? ($page->campaign->name ?? '') : ($page->offer()->label ?? ''),
            'paragraphs' => LandingCopy::paragraphs($text),
            'picture' => $this->pictures($data)[0] ?? null,
            'secondary_target' => is_string($data['secondary'] ?? null) ? $data['secondary'] : 'none',
            'secondary_label' => is_string($data['secondary_label'] ?? null) ? $data['secondary_label'] : '',
        ];
    }
}
