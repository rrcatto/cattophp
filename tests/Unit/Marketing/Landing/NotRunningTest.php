<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing\Landing;

use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\NotRunning;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * What a landing page does in every state of its campaign, under each of the three choices for while the campaign is not
 * running, and which sections it shows under each of the three Shown values.
 */
final class NotRunningTest extends TestCase
{
    private DateTimeZone $zone;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->zone = new DateTimeZone('Africa/Johannesburg');
        $this->now = new DateTimeImmutable('2026-12-05 10:00', $this->zone);
    }

    public function testEveryCampaignStateAgainstTheThreeChoicesAndTheThreeShownValues(): void
    {
        $states = [
            'running' => $this->campaign('active', '2026-11-01', '2026-12-31'),
            'not_started' => $this->campaign('active', '2026-12-10', '2026-12-31'),
            'paused' => $this->campaign('paused', '2026-11-01', '2026-12-31'),
            'ended' => $this->campaign('active', '2026-11-01', '2026-11-30'),
            'archived' => $this->campaign('archived', '2026-11-01', '2026-11-30'),
            'draft' => $this->campaign('draft', '2026-11-01', '2026-12-31'),
        ];
        // [draws, running, forwards to, note] for keep, note and forward, by state.
        $expected = [
            'running' => [[true, true, null, null], [true, true, null, null], [true, true, null, null]],
            'not_started' => [[true, false, null, null], [true, false, null, 'This special starts on 10 December 2026.'], [false, false, '/shop', null]],
            'paused' => [[true, false, null, null], [true, false, null, 'This special is not available at the moment.'], [false, false, '/shop', null]],
            'ended' => [[true, false, null, null], [true, false, null, 'This special ended on 30 November 2026.'], [false, false, '/shop', null]],
            'archived' => [[true, false, null, null], [true, false, null, 'This special ended on 30 November 2026.'], [false, false, '/shop', null]],
            'draft' => [[false, false, null, null], [false, false, null, null], [false, false, null, null]],
        ];
        foreach ($states as $state => $campaign) {
            foreach ([NotRunning::KEEP, NotRunning::NOTE, NotRunning::FORWARD] as $index => $choice) {
                $outcome = NotRunning::for($this->page($choice, $choice === NotRunning::FORWARD ? '/shop' : null), $campaign, $this->now);
                self::assertSame($expected[$state][$index], [$outcome->draws(), $outcome->running, $outcome->forwardTo, $outcome->note], $state . ' with ' . $choice);
                self::assertSame($state === 'archived' ? 'ended' : $state, $outcome->state, 'An archived campaign counts as ended.');
                self::assertSame([true, $outcome->running, !$outcome->running], [$outcome->shows('always'), $outcome->shows('running'), $outcome->shows('not_running')], $state . ' with ' . $choice);
            }
        }
    }

    public function testADraftPageIsNeverPublicAndForwardingGoesToTheCampaignsDestinationWhenNoneIsTyped(): void
    {
        $running = $this->campaign('active', '2026-11-01', '2026-12-31');
        self::assertFalse(NotRunning::for($this->page(NotRunning::KEEP, null, published: false), $running, $this->now)->draws(), 'A page not published is not public.');
        $ended = $this->campaign('active', '2026-11-01', '2026-11-30');
        self::assertSame('/offers', NotRunning::for($this->page(NotRunning::FORWARD, null), $ended, $this->now)->forwardTo);
        $archivedOpen = $this->campaign('archived', '2026-11-01', null);
        self::assertSame('This special has ended.', NotRunning::for($this->page(NotRunning::NOTE, null), $archivedOpen, $this->now)->note, 'Archived with no end date.');
        $offerPage = NotRunning::for($this->page(NotRunning::KEEP, null), null, $this->now);
        self::assertSame([true, true, 'running'], [$offerPage->draws(), $offerPage->running, $offerPage->state], 'An offer\'s page is always running.');
        self::assertSame(['keep', 'note', 'forward'], array_keys(NotRunning::CHOICES));
    }

    private function campaign(string $status, ?string $starts, ?string $ends): Campaign
    {
        return new Campaign(1, 'winter-sale', 'Winter sale', '', '', $status, $starts === null ? null : new DateTimeImmutable($starts, $this->zone),
            $ends === null ? null : (new DateTimeImmutable($ends, $this->zone))->setTime(23, 59, 59), null, '/offers', null, null, $this->now, $this->now);
    }

    private function page(string $notRunning, ?string $to, bool $published = true): LandingPage
    {
        return new LandingPage(1, 'abcdefgh', 1, null, null, null, $published ? 'published' : 'draft', $published ? $this->now : null, $published ? $this->now : null, null, null, null,
            $notRunning, $to, null, null, $this->now, $this->now);
    }
}
