<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Installation;

use CattoPHP\Installation\Installation;
use CattoPHP\Installation\PhpTimeZone;
use CattoPHP\Scheduler\FileRunStore;
use CattoPHP\Scheduler\ScheduleRunCommand;
use CattoPHP\Scheduler\Scheduler;
use CattoPHP\Tests\Support\ExampleTask;
use CattoPHP\Tests\Support\Foundations;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** PHP's own time zone follows the installation's (APP_TIMEZONE), so a task due at 03:15 runs at 03:15 there, not in UTC. */
final class PhpTimeZoneTest extends TestCase
{
    private string $zone;

    private string $folder;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        Foundations::remove($this->folder);
    }

    public function testPhpsTimeZoneIsSetToTheInstallationsAndLeftAloneWhenItNamesNone(): void
    {
        self::assertSame('Pacific/Auckland', (new PhpTimeZone((new Foundations(['APP_TIMEZONE' => 'Pacific/Auckland']))->installation))->apply());
        self::assertSame('Pacific/Auckland', date_default_timezone_get());
        self::assertSame('UTC', (new PhpTimeZone((new Foundations())->installation))->apply(), 'No setting: its default, UTC.');
        self::assertSame('UTC', date_default_timezone_get());
        date_default_timezone_set('Europe/London');
        $unknown = new Foundations(['APP_TIMEZONE' => 'Mars/Olympus_Mons']);
        self::assertNull((new PhpTimeZone($unknown->installation))->apply(), 'A time zone PHP does not know is no time zone.');
        self::assertSame('Europe/London', date_default_timezone_get(), 'PHP\'s is left as it was.');
        self::assertNull($unknown->installation->chosenTimeZone());
        self::assertSame('UTC', $unknown->installation->timeZone()->getName(), 'Times are still shown, in UTC.');
    }

    /**
     * Review finding 9: every time zone PHP knows by name counts, the older names kept for backward compatibility among
     * them, in any case: APP_TIMEZONE=Asia/Calcutta worked in 0.3.0 and works again, never quietly UTC. An offset is no
     * time zone's name.
     */
    public function testEveryNamePhpKnowsIsATimeZoneOlderNamesAndAnyCaseToo(): void
    {
        foreach (['Asia/Calcutta' => 'Asia/Calcutta', 'Etc/UTC' => 'Etc/UTC', 'Etc/GMT-2' => 'Etc/GMT-2', 'US/Eastern' => 'US/Eastern', 'GB' => 'GB', 'Europe/Kiev' => 'Europe/Kiev',
            'africa/johannesburg' => 'Africa/Johannesburg', ' UTC ' => 'UTC'] as $typed => $zone) {
            $installation = (new Foundations(['APP_TIMEZONE' => $typed]))->installation;
            self::assertSame($zone, $installation->timeZone()->getName(), $typed);
            self::assertSame($zone, (new PhpTimeZone($installation))->apply(), $typed);
            self::assertSame($zone, date_default_timezone_get(), $typed);
        }
        self::assertNull(Installation::timeZoneName('+02:00'));
        self::assertNull((new Foundations(['APP_TIMEZONE' => '+02:00']))->installation->chosenTimeZone());
    }

    /** A nightly task at 03:15 in Johannesburg: not due at 01:16 UTC by PHP's UTC, due once PHP's zone is the installation's. */
    public function testATasksTimeOfDayIsInTheInstallationsTimeZone(): void
    {
        $runs = new FileRunStore($this->folder . '/runs.json');
        $runs->started('nightly', new DateTimeImmutable('2026-11-12 05:00:00 UTC'));
        $runs->succeeded('nightly', new DateTimeImmutable('2026-11-12 05:00:01 UTC'), 'Ran.');
        $nightly = new ExampleTask('nightly', 86400, at: '03:15');
        $clock = new MockClock('2026-11-13 01:16:00', 'UTC');
        $scheduler = new Scheduler([$nightly], $runs, $clock);

        self::assertSame([], $scheduler->run(), 'By UTC, 03:15 has not come yet today and came after yesterday\'s run.');
        $timeZone = new PhpTimeZone((new Foundations(['APP_TIMEZONE' => 'Africa/Johannesburg']))->installation);
        $timeZone->apply();
        self::assertSame(['nightly'], array_keys($scheduler->run()), '01:16 UTC is 03:16 in Johannesburg: due.');

        // The command sets it before each run itself, so a changed setting needs no restart.
        date_default_timezone_set('UTC');
        $runs = new FileRunStore($this->folder . '/runs-two.json');
        $runs->started('nightly', new DateTimeImmutable('2026-11-12 05:00:00 UTC'));
        $tester = new CommandTester(new ScheduleRunCommand(new Scheduler([$nightly], $runs, $clock), null, 'development', $timeZone));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("nightly: Ran at 01:16.\n", $tester->getDisplay(), 'Due by Johannesburg\'s 03:15 (the task is given the clock\'s moment).');
        self::assertSame('Africa/Johannesburg', date_default_timezone_get());
    }
}
