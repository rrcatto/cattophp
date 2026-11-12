<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Scheduler;

use CattoPHP\Scheduler\FileRunStore;
use CattoPHP\Scheduler\ScheduleRunCommand;
use CattoPHP\Scheduler\Scheduler;
use CattoPHP\Scheduler\TaskStatus;
use CattoPHP\Tests\Support\ExampleTask;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RunStoreContract;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** One scheduler: due tasks run and are recorded, a failure never stops the rest, the status in words, and the command. */
final class SchedulerTest extends TestCase
{
    private string $folder;

    private FileRunStore $runs;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
        $this->runs = new FileRunStore($this->folder . '/scheduled-jobs.json');
        $this->clock = new MockClock('2026-11-12 10:00:00', 'Africa/Johannesburg');
    }

    protected function tearDown(): void
    {
        Foundations::remove($this->folder);
    }

    public function testAFileKeepsRunsAsEveryStoreDoesAndOnlyItsOwnerCanReadIt(): void
    {
        $folder = Foundations::folder();
        try {
            RunStoreContract::holds(new FileRunStore($folder . '/runs/scheduled-jobs.json'), 'file');
            self::assertSame(0600, fileperms($folder . '/runs/scheduled-jobs.json') & 0777);
        } finally {
            Foundations::remove($folder);
        }
    }

    public function testDueTasksRunAndAFailureIsRecordedWhileTheRestStillRun(): void
    {
        $orders = new ExampleTask('orders', 60);
        $broken = new ExampleTask('mailing', 60, failWith: 'The mail server did not answer.');
        $daily = new ExampleTask('clean_up', 86400);
        $scheduler = $this->scheduler([$orders, $broken, $daily]);
        $ran = $scheduler->run();
        self::assertSame(['orders', 'mailing', 'clean_up'], array_keys($ran), 'In the order they were given.');
        self::assertSame(['ok' => false, 'summary' => 'The mail server did not answer.'], $ran['mailing']);
        self::assertSame(['ok' => true, 'summary' => 'Ran at 10:00.'], $ran['clean_up'], 'The task after a failure still ran.');
        self::assertSame('The mail server did not answer.', $this->runs->all()['mailing']['last_error']);

        $this->clock->sleep(60);
        self::assertSame(['orders', 'mailing'], array_keys($scheduler->run()), 'A minute later the daily task is not due.');
        self::assertSame([2, 2, 1], [$orders->runs, $broken->runs, $daily->runs]);
        self::assertSame([], $this->scheduler([$orders])->run(), 'Run again at once: nothing is due.');
    }

    public function testNamedTasksRunWhetherDueOrNotAndAnUnknownOneIsRefused(): void
    {
        $orders = new ExampleTask('orders', 60);
        $daily = new ExampleTask('clean_up', 86400);
        $scheduler = $this->scheduler([$orders, $daily]);
        $scheduler->run();
        self::assertSame(['clean_up'], array_keys($scheduler->run(['clean_up'])), 'Named: run now, due or not.');
        $this->expectException(InvalidArgumentException::class);
        $scheduler->run(['payroll']);
    }

    public function testEachTaskHasAKeyOfItsOwn(): void
    {
        foreach ([[new ExampleTask('orders'), new ExampleTask('orders')], [new ExampleTask('Order Emails')]] as $tasks) {
            try {
                $this->scheduler($tasks);
                self::fail('Accepted tasks it should refuse.');
            } catch (InvalidArgumentException $refused) {
                self::assertMatchesRegularExpression('/key/', $refused->getMessage());
            }
        }
    }

    public function testTheStatusSaysEachTasksLastRunsInWordsAndWhetherItHasStopped(): void
    {
        $orders = new ExampleTask('orders', 60, overdue: 300);
        $mailing = new ExampleTask('mailing', 60, overdue: 300);
        $never = new ExampleTask('video_check', 86400);
        $scheduler = new Scheduler([$orders, $mailing, $never], $this->runs, $this->clock, new DateTimeZone('Africa/Johannesburg'));
        $status = new TaskStatus($scheduler, $this->runs, $this->clock);
        $scheduler->run(['orders', 'mailing']);
        $mailing->failWith = 'The mail server did not answer.';
        $this->clock->sleep(120);
        $scheduler->run(['mailing']);
        [$first, $second, $third] = $status->tasks();
        self::assertSame(['orders', 'Orders', '10:00', '10:00', null, '', 'Ran at 10:00.', false, 1], [$first['key'], $first['label'], $first['last_run'], $first['last_success'], $first['last_failure'], $first['error'], $first['summary'], $first['overdue'], $first['run_count']]);
        self::assertSame(['10:02', '10:00', '10:02', 'The mail server did not answer.'], [$second['last_run'], $second['last_success'], $second['last_failure'], $second['error']], 'A failure after the last success is shown.');
        self::assertSame(['Never', 'Never', true], [$third['last_run'], $third['last_success'], $third['overdue']], 'Never run: overdue.');
        $this->clock->sleep(299);
        self::assertTrue($status->isOverdue('mailing'), 'No success for more than five minutes.');
        self::assertFalse($status->isOverdue('no_such_task'));
        $this->clock->modify('+1 day');
        self::assertSame('12 November 2026 at 10:00', $status->words($status->lastSucceeded('orders')), 'Another day: the date as well.');
    }

    public function testTheCommandRunsWhatIsDueAndSaysWhatEachDid(): void
    {
        $orders = new ExampleTask('orders', 60);
        $broken = new ExampleTask('mailing', 60, failWith: 'The mail server didn\'t answer <in time>.');
        $tester = new CommandTester(new ScheduleRunCommand($this->scheduler([$orders, $broken])));
        self::assertSame(Command::FAILURE, $tester->execute([]), 'A task failed.');
        self::assertStringContainsString('orders: Ran at 10:00.', $tester->getDisplay());
        self::assertStringContainsString('mailing: The mail server didn\'t answer <in time>.', $tester->getDisplay(), 'Said as written: an apostrophe is not &#039;, and <in time> is not taken for a style.');
        self::assertSame(Command::INVALID, $tester->execute(['--job' => ['payroll']]));
        self::assertStringContainsString('Choose one of: orders, mailing.', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, (new CommandTester(new ScheduleRunCommand($this->scheduler([$orders]))))->execute(['--job' => ['orders']]), 'Named and run now.');
    }

    public function testWatchingRunsOnceAMinuteUntilStopped(): void
    {
        $orders = new ExampleTask('orders', 60);
        $pauses = [];
        $command = new ScheduleRunCommand($this->scheduler([$orders]), function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
            $this->clock->sleep($seconds);
            if (count($pauses) === 3) throw new RuntimeException('stopped');
        });
        try {
            (new CommandTester($command))->execute(['--watch' => true]);
            self::fail('The watch ended by itself.');
        } catch (RuntimeException $stopped) {
            self::assertSame('stopped', $stopped->getMessage());
        }
        self::assertSame([60, 60, 60], $pauses);
        self::assertSame(3, $orders->runs, 'Due again each minute.');
    }

    /** @param list<ExampleTask> $tasks */
    private function scheduler(array $tasks): Scheduler
    {
        return new Scheduler($tasks, $this->runs, $this->clock, new DateTimeZone('Africa/Johannesburg'));
    }
}
