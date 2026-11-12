<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Scheduler;

use CattoPHP\Scheduler\LogArchive;
use CattoPHP\Scheduler\LogArchiveSettings;
use CattoPHP\Scheduler\LogArchiveTask;
use CattoPHP\Scheduler\Schedule;
use CattoPHP\Settings\SettingsRefused;
use CattoPHP\Tests\Support\Foundations;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** The log archive, ported from cattolms (Maintenance\LogArchive) with its tests, and its setting and task. */
final class LogArchiveTest extends TestCase
{
    private string $root;

    private string $zone;

    protected function setUp(): void
    {
        $this->root = Foundations::folder();
        mkdir($this->root . '/logs', 0775, true);
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Africa/Johannesburg');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        Foundations::remove($this->root);
    }

    public function testTheLogIsArchivedWholeUnderTheDayItHoldsAndStartedAfresh(): void
    {
        $log = $this->root . '/logs/application.log';
        $lines = str_repeat("[27-Oct-2026 21:06:27 Africa/Johannesburg] HTTP GET /pages\n", 40000);
        file_put_contents($log, $lines);
        chmod($log, 0666);
        $archive = new LogArchive($this->root . '/logs');

        $done = $archive->run(new DateTimeImmutable('2026-10-28 00:00:30'));

        self::assertSame(['application.log' => ['archive' => 'application-2026-10-27.log.gz', 'bytes' => strlen($lines)]], $done['archived'], 'Named after the day it holds.');
        self::assertSame($lines, gzdecode((string) file_get_contents($this->root . '/logs/archive/application-2026-10-27.log.gz')), 'Every line kept.');
        self::assertSame(0, filesize($log), 'The log starts afresh.');
        self::assertSame(0666, fileperms($log) & 0777, 'With the permissions it had, so every process can still write to it.');
        self::assertSame([], glob($this->root . '/logs/archive/*.log') ?: [], 'No uncompressed copy is left.');

        file_put_contents($log, "one more line\n");
        file_put_contents($this->root . '/logs/schedule.log', "Ran orders.\n");
        $again = $archive->run(new DateTimeImmutable('2026-10-27 23:00:00'))['archived'];
        self::assertSame('application-2026-10-27-2.log.gz', $again['application.log']['archive'], 'A second archive of the same day never replaces the first.');
        self::assertSame('schedule-2026-10-27.log.gz', $again['schedule.log']['archive'], 'Every log beside it is archived too, under its own name.');
        self::assertSame("Ran orders.\n", gzdecode((string) file_get_contents($this->root . '/logs/archive/schedule-2026-10-27.log.gz')));
        self::assertSame([], $archive->run(new DateTimeImmutable('2026-10-28 00:01:00'))['archived'], 'An empty log is not archived.');
        $status = $archive->status();
        self::assertSame(3, $status['archives']);
        self::assertSame([['path' => $this->root . '/logs/schedule.log', 'bytes' => 0]], $status['others']);
        self::assertSame(['application-2026-10-27.log.gz', 'schedule-2026-10-27.log.gz'], [$status['oldest'], $status['newest']]);
    }

    public function testArchivesPastTheKeepPeriodAreDeletedAndOneLeftUncompressedIsFinished(): void
    {
        mkdir($this->root . '/logs/archive');
        $now = new DateTimeImmutable('2026-10-28 00:00:30');
        foreach (['2026-10-12' => 15, '2026-10-15' => 13] as $day => $daysAgo) {
            $path = $this->root . '/logs/archive/application-' . $day . '.log.gz';
            file_put_contents($path, (string) gzencode("old\n"));
            touch($path, $now->modify('-' . $daysAgo . ' days')->getTimestamp());
        }
        file_put_contents($this->root . '/logs/archive/application-2026-10-26.log', "moved but not compressed\n");

        $done = (new LogArchive($this->root . '/logs'))->run($now);

        self::assertSame(1, $done['deleted'], 'Two weeks kept by default: the one 15 days old goes.');
        self::assertFileDoesNotExist($this->root . '/logs/archive/application-2026-10-12.log.gz');
        self::assertFileExists($this->root . '/logs/archive/application-2026-10-15.log.gz');
        self::assertSame("moved but not compressed\n", gzdecode((string) file_get_contents($this->root . '/logs/archive/application-2026-10-26.log.gz')));
        self::assertFileDoesNotExist($this->root . '/logs/archive/application-2026-10-26.log');
    }

    public function testHowLongArchivesAreKeptIsASettingOfAtLeastADay(): void
    {
        $site = new Foundations(['LOG_ARCHIVES_KEPT_FOR' => '3w'], settingsProviders: [new LogArchiveSettings()]);
        $archive = new LogArchive($this->root . '/logs', $site->settings);
        self::assertSame('3 weeks', $archive->keep()->words(), '.env gives the starting value.');
        $site->settings->set('LOG_ARCHIVES_KEPT_FOR', '10d');
        self::assertSame('10 days', $archive->keep()->words(), 'Settings overrides it.');
        try {
            $site->settings->set('LOG_ARCHIVES_KEPT_FOR', '12h');
            self::fail('Kept for less than a day.');
        } catch (SettingsRefused $refused) {
            self::assertArrayHasKey('LOG_ARCHIVES_KEPT_FOR', $refused->errors());
        }
        $site->settings->reset('LOG_ARCHIVES_KEPT_FOR');
        self::assertSame('3 weeks', $archive->keep()->words(), 'Reset returns to .env.');
        self::assertSame('2 weeks', (new LogArchive($this->root . '/logs', (new Foundations([], settingsProviders: [new LogArchiveSettings()]))->settings))->keep()->words(), 'Two weeks when .env says nothing.');
    }

    public function testTheTaskRunsJustAfterMidnightAndSaysWhatItDid(): void
    {
        $task = new LogArchiveTask(new LogArchive($this->root . '/logs'));
        $zone = new DateTimeZone('Africa/Johannesburg');
        self::assertSame(['log_archive', 'Log archive', '00:00', 86400, 2 * 86400], [$task->key(), $task->label(), $task->runsAt(), $task->interval(), $task->overdueAfter()]);
        self::assertTrue(Schedule::isDue($task, new DateTimeImmutable('2026-10-28 00:00:20'), new DateTimeImmutable('2026-10-27 00:00:10'), $zone));
        self::assertFalse(Schedule::isDue($task, new DateTimeImmutable('2026-10-28 23:59:00'), new DateTimeImmutable('2026-10-28 00:00:20'), $zone), 'Once a day.');
        self::assertTrue(Schedule::isDue($task, new DateTimeImmutable('2026-10-28 00:00:05'), new DateTimeImmutable('2026-10-27 23:59:05'), $zone), 'Midnight, however soon after the last run.');
        self::assertTrue(Schedule::isDue($task, new DateTimeImmutable('2026-10-27 22:00:30', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-27 08:00:00'), $zone), 'Midnight in the site\'s time zone, not UTC.');

        self::assertSame('The logs were empty, so nothing was archived. Deleted 0 archives older than 2 weeks.', $task->run(new DateTimeImmutable('2026-10-28 00:00:30')));
        file_put_contents($this->root . '/logs/application.log', str_repeat('x', 2048));
        self::assertSame('Archived 2 KB of application.log as application-2026-10-28.log.gz, each started afresh. Deleted 0 archives older than 2 weeks.', $task->run(new DateTimeImmutable('2026-10-29 00:00:30')));
    }

    public function testSizesAreInWords(): void
    {
        self::assertSame('4.9 GB', LogArchive::size(5261334937));
        self::assertSame('812 KB', LogArchive::size(831488));
        self::assertSame('1 byte', LogArchive::size(1));
        self::assertSame('0 bytes', LogArchive::size(0));
    }
}
