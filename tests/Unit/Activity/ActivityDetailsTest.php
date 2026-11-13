<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Activity;

use CattoPHP\Activity\ActivityDetails;
use CattoPHP\Activity\DetailWords;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;

/** An entry's details as lines a person reads: the log's own wording, and a project's own words where it has them. */
final class ActivityDetailsTest extends TestCase
{
    public function testKeysDatesTimesAndListsAreInWordsInTheInstallationsTimeZone(): void
    {
        $details = new ActivityDetails((new Foundations(['APP_TIMEZONE' => 'Africa/Johannesburg']))->installation);

        self::assertSame([
            'Due at: 7 November 2026 at 07:01',
            'Lent at: 6 November 2026 at 21:30',
            'Started: 7 November 2026 at 09:15',
            'Due on: 1 December 2026',
            'Condition: Needs repair',
            'Code: SD4JR2UH',
            'Setting: APP_NAME',
            'Action: sign_in.link_sent',
            'Kept: yes',
            'Count: 3',
            'Tags: Garden tool, heavy',
            'Changes: {"from":"worn","to":"new"}',
        ], $details->lines('example.lent', [
            'due_at' => '2026-11-07T05:01:56+00:00',
            'lent_at' => '2026-11-06T19:30:00Z',
            'started' => '2026-11-07 09:15:00',
            'due_on' => '2026-12-01',
            'condition' => 'needs_repair',
            'code' => 'SD4JR2UH',
            'setting' => 'APP_NAME',
            'action' => 'sign_in.link_sent',
            'kept' => true,
            'count' => 3,
            'tags' => ['garden_tool', 'heavy'],
            'changes' => ['from' => 'worn', 'to' => 'new'],
            'nothing' => '',
            'none' => null,
            'empty' => [],
        ]), 'A time without an offset is the installation\'s own; a value with nothing in it is left out.');
        $long = $details->lines('example.lent', ['note' => str_repeat('word ', 60)]);
        self::assertSame(166, mb_strlen($long[0]), 'A long value is cut short at 160 characters.');
        self::assertStringEndsWith('...', $long[0]);
        self::assertSame(['Note: 2026-13-45 is not a date'], $details->lines('example.lent', ['note' => '2026-13-45 is not a date']));
        self::assertSame(['Due on: 2026-13-45'], $details->lines('example.lent', ['due_on' => '2026-13-45']), 'Not a real date: as it is.');
        self::assertSame('Needs repair', ActivityDetails::sentence('needs_repair'));
    }

    public function testAProjectWordsItsOwnDetailsAndLeavesTheRestToTheLog(): void
    {
        $words = new class implements DetailWords {
            public function label(string $action, string $key): ?string
            {
                return $action === 'loan.reminded' && $key === 'due_at' ? 'Due back' : null;
            }

            public function value(string $action, string $key, mixed $value): ?string
            {
                return $key === 'condition' ? match ($value) { 'needs_repair' => 'Needs a repair first', default => null } : null;
            }
        };
        $details = new ActivityDetails((new Foundations(['APP_TIMEZONE' => 'Africa/Johannesburg']))->installation, [$words]);

        self::assertSame(['Due back: 7 November 2026 at 07:01', 'Condition: Needs a repair first'], $details->lines('loan.reminded', ['due_at' => '2026-11-07T05:01:56+00:00', 'condition' => 'needs_repair']));
        self::assertSame(['Due at: 7 November 2026 at 07:01', 'Condition: Worn'], $details->lines('loan.made', ['due_at' => '2026-11-07T05:01:56+00:00', 'condition' => 'Worn']), 'What the project has no words for, the log words.');
    }
}
