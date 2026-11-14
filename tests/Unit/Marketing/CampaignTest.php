<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\CampaignOffer;
use CattoPHP\Marketing\CampaignSummary;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** What a person sees as a campaign's status is worked out from its status and dates, and so is whether a click counts. */
final class CampaignTest extends TestCase
{
    public function testItsStatusIsWorkedOutFromItsDates(): void
    {
        $now = new DateTimeImmutable('2026-11-13 12:00:00');
        $soon = new DateTimeImmutable('2026-11-20');
        $past = new DateTimeImmutable('2026-11-01');
        self::assertSame(['draft', 'Draft', 'draft'], self::seen(self::campaign('draft'), $now));
        self::assertSame(['scheduled', 'Scheduled', 'not_started'], self::seen(self::campaign('active', $soon), $now));
        self::assertSame(['active', 'Active', null], self::seen(self::campaign('active', $past, $soon), $now));
        self::assertSame(['ended', 'Ended', 'ended'], self::seen(self::campaign('active', null, $past), $now));
        self::assertSame(['paused', 'Paused', 'paused'], self::seen(self::campaign('paused'), $now));
        self::assertSame(['archived', 'Archived', 'archived'], self::seen(self::campaign('archived'), $now));
        self::assertTrue(self::campaign('active')->isRunning($now));
    }

    public function testWhatCanBeDoneFollowsItsStatus(): void
    {
        $can = static fn(Campaign $campaign): array => [$campaign->canActivate(), $campaign->canPause(), $campaign->canArchive(), $campaign->canRestore()];
        self::assertSame([true, false, true, false], $can(self::campaign('draft')));
        self::assertSame([false, true, true, false], $can(self::campaign('active')));
        self::assertSame([true, false, true, false], $can(self::campaign('paused')));
        self::assertSame([false, false, false, true], $can(self::campaign('archived')));
    }

    public function testItsSummarySaysHowMuchOfTheBudgetIsSpent(): void
    {
        self::assertSame([50, false], [($s = new CampaignSummary(self::campaign('active', budget: 100000), 2, 10, 8, 50000))->budgetUsedPercent(), $s->overBudget()]);
        self::assertSame([125, true], [($s = new CampaignSummary(self::campaign('active', budget: 100000), 2, 10, 8, 125000))->budgetUsedPercent(), $s->overBudget()]);
        self::assertNull((new CampaignSummary(self::campaign('active'), 0, 0, 0, 500))->budgetUsedPercent());
    }

    public function testItsEditFormStartsWithWhatItHolds(): void
    {
        $input = CampaignInput::of(self::campaign('active', new DateTimeImmutable('2026-12-01 09:00'), budget: 150050), [new CampaignOffer('product', 'tea-100', 'Rooibos tea', 0)]);
        self::assertSame(['Spring sale', 'spring-sale', '2026-12-01T09:00', '', '1500.50', '/offers', ['product:tea-100']], [$input->name, $input->code, $input->startsAt, $input->endsAt, $input->budget, $input->destination, $input->offers]);
        $typed = CampaignInput::fromForm(['name' => '  Winter  ', 'code' => 'WINTER', 'offers' => ['product:tea-100', '', 'bundle:gift-box'], 'budget' => ['not text']]);
        self::assertSame(['Winter', 'winter', '', ['product:tea-100', 'bundle:gift-box']], [$typed->name, $typed->code, $typed->budget, $typed->offers]);
    }

    /** @return array{0:string,1:string,2:?string} */
    private static function seen(Campaign $campaign, DateTimeImmutable $now): array
    {
        return [$campaign->shownStatus($now), $campaign->statusWords($now), $campaign->notCountedBecause($now)];
    }

    private static function campaign(string $status, ?DateTimeImmutable $startsAt = null, ?DateTimeImmutable $endsAt = null, ?int $budget = null): Campaign
    {
        $made = new DateTimeImmutable('2026-10-01');

        return new Campaign(1, 'spring-sale', 'Spring sale', '', '', $status, $startsAt, $endsAt, $budget, '/offers', null, null, $made, $made);
    }
}
