<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Marketing\Campaign;
use DateTimeImmutable;

/**
 * What a landing page does now, by its campaign's state and the page's setting "When the campaign is not running" (three
 * feature cards): keep the page (the sections shown only while the campaign runs, the countdown and the promotion's price
 * are left out; the hero shows its not-running words when it has them), keep it with a note above the offers ("This special
 * ended on 30 November 2026."), or send visitors to another page. An archived campaign counts as ended. A draft
 * campaign's page, and a page that is not published, are never public: the campaign's links forward as they always did.
 * An offer's page is always running.
 *
 * Each section is shown always, only while the campaign runs, or only while it is not running (shows()).
 */
final class NotRunning
{
    public const KEEP = 'keep';
    public const NOTE = 'note';
    public const FORWARD = 'forward';

    /** The choices, each with its feature card's words. */
    public const CHOICES = [
        self::KEEP => ['Keep the page', 'Without the countdown and the special\'s wording. Usual prices.'],
        self::NOTE => ['Show that the special is not on', 'A short note above the offers, at their usual prices.'],
        self::FORWARD => ['Send visitors to another page', 'The page is taken down and visitors go to the page you type.'],
    ];

    /** A campaign's states as a landing page sees them. */
    public const STATES = ['running', 'not_started', 'paused', 'ended', 'draft'];

    private function __construct(
        public readonly bool $public,
        public readonly bool $running,
        public readonly string $state,
        public readonly ?string $forwardTo,
        public readonly ?string $note,
    ) {
    }

    /** What a page does now, for its campaign (none for an offer's page), in the installation's time zone. */
    public static function for(LandingPage $page, ?Campaign $campaign, DateTimeImmutable $now): self
    {
        $state = self::state($campaign, $now);
        $published = $page->isPublished();
        if ($state === 'draft') {
            return new self(false, false, $state, null, null);
        }
        if ($state === 'running') {
            return new self($published, true, $state, null, null);
        }

        return match ($page->notRunning) {
            self::FORWARD => new self($published, false, $state, $page->notRunningTo ?? $campaign->destination ?? '/', null),
            self::NOTE => new self($published, false, $state, null, self::note($state, $campaign, $now)),
            default => new self($published, false, $state, null, null),
        };
    }

    /** A campaign's state: running (or none, for an offer's page), not started, paused, ended (archived too) or draft. */
    public static function state(?Campaign $campaign, DateTimeImmutable $now): string
    {
        if ($campaign === null) {
            return 'running';
        }

        return match ($campaign->shownStatus($now)) {
            'active' => 'running',
            'scheduled' => 'not_started',
            'paused' => 'paused',
            'draft' => 'draft',
            default => 'ended',
        };
    }

    /** Whether the page is drawn here (else it forwards, or is not public). */
    public function draws(): bool
    {
        return $this->public && $this->forwardTo === null;
    }

    /** Whether a section with this Shown value is on the page now. */
    public function shows(string $shownWhen): bool
    {
        return match ($shownWhen) {
            'running' => $this->running,
            'not_running' => !$this->running,
            default => true,
        };
    }

    private static function note(string $state, ?Campaign $campaign, DateTimeImmutable $now): string
    {
        $zone = $now->getTimezone();

        return match (true) {
            $state === 'not_started' && $campaign?->startsAt !== null => 'This special starts on ' . $campaign->startsAt->setTimezone($zone)->format('j F Y') . '.',
            $state === 'paused' => 'This special is not available at the moment.',
            $campaign?->endsAt !== null && $campaign->endsAt <= $now => 'This special ended on ' . $campaign->endsAt->setTimezone($zone)->format('j F Y') . '.',
            default => 'This special has ended.',
        };
    }
}
