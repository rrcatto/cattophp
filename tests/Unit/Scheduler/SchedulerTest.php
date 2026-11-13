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
use CattoPHP\Tests\Support\StoppingTask;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use CattoPHP\Scheduler\ScheduledTask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
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

    /**
     * A time-based task tried on a development site: --job with --now gives the task that moment for now. The trial is
     * kept apart from the task's runs, at the real time, saying the clock was moved, and its runs stay as they were. In
     * production --now is refused in a plain sentence, as is --now without a task named, with --watch, or a moment that
     * is not one.
     */
    public function testATaskRunsAsIfItWereAnotherMomentOnADevelopmentSiteOnly(): void
    {
        $orders = new ExampleTask('orders', 60);
        $daily = new ExampleTask('clean_up', 86400, at: '03:00');
        $scheduler = new Scheduler([$orders, $daily], $this->runs, $this->clock, new DateTimeZone('Africa/Johannesburg'), development: true);
        $tester = new CommandTester(new ScheduleRunCommand($scheduler, null, 'development'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--job' => ['orders'], '--now' => '2026-12-01 09:00']));
        self::assertSame("As at 1 December 2026, 09:00 (Africa/Johannesburg), on a development site: a trial, kept apart from the task's runs.\norders: As at 2026-12-01 09:00: Ran at 09:00.\n", $tester->getDisplay(), 'The task was given 09:00 on 1 December, in the site\'s time zone.');
        $record = $this->runs->all()['orders'];
        self::assertSame('2026-11-12 10:00', (new \DateTimeImmutable((string) $record['last_trial_at']))->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d H:i'), 'The trial kept at the real time.');
        self::assertSame(['As at 2026-12-01 09:00: Ran at 09:00.', true], [$record['last_trial_summary'], $record['last_trial_ok']]);
        self::assertSame([null, null, '', 0], [$record['last_started_at'], $record['last_succeeded_at'], $record['last_summary'], $record['run_count']], 'Never a run.');
        self::assertSame(0, $daily->runs, 'Only the task named.');

        self::assertSame(Command::SUCCESS, $tester->execute(['--job' => ['orders'], '--now' => '+3 days']));
        self::assertStringContainsString('orders: As at 2026-11-15 10:00: Ran at 10:00.', $tester->getDisplay(), 'A moment counted from the scheduler\'s clock.');

        $broken = new ExampleTask('mailing', 60, failWith: 'The mail server did not answer.');
        self::assertSame(Command::FAILURE, (new CommandTester(new ScheduleRunCommand(new Scheduler([$broken], $this->runs, $this->clock, development: true), null, 'development')))->execute(['--job' => ['mailing'], '--now' => '2026-12-01 09:00']));
        self::assertSame(['As at 2026-12-01 09:00: The mail server did not answer.', false, ''], [$this->runs->all()['mailing']['last_trial_summary'], $this->runs->all()['mailing']['last_trial_ok'], $this->runs->all()['mailing']['last_error']], 'A failed trial is kept as a trial too, the moment with it.');

        foreach ([
            'production' => [['--job' => ['orders'], '--now' => '2026-12-01 09:00'], 'production', Scheduler::TRIALS_REFUSED],
            'no task named' => [['--now' => '2026-12-01 09:00'], 'development', 'Name the task to run at that moment with --job, such as --job=orders --now="2026-12-01 09:00".'],
            'watching' => [['--job' => ['orders'], '--now' => '2026-12-01 09:00', '--watch' => true], 'development', '--now runs the named tasks once: leave out --watch.'],
            'not a moment' => [['--job' => ['orders'], '--now' => 'next blue moon'], 'development', '--now takes a date and time, such as "2026-12-01 09:00" or "+3 days", in the site\'s time zone (Africa/Johannesburg): "next blue moon" is not one.'],
        ] as $what => [$input, $environment, $said]) {
            $refusing = new CommandTester(new ScheduleRunCommand($scheduler, null, $environment));
            self::assertSame(Command::INVALID, $refusing->execute($input), $what);
            self::assertSame($said . "\n", $refusing->getDisplay(), $what);
        }
        self::assertSame(2, $orders->runs, 'Nothing refused ran.');
        $this->expectException(InvalidArgumentException::class);
        $scheduler->run(null, null, new \DateTimeImmutable('2026-12-01 09:00'));
    }

    /**
     * Review finding 8: a daily 03:15 task missed while the scheduler was down, tried with --now at 09:00, still runs for
     * real at its next check, and stays overdue until it has; the trial is shown apart.
     */
    public function testATrialNeverMovesTheScheduleOrHidesAnOutage(): void
    {
        $nightly = new ExampleTask('nightly', 86400, at: '03:15');
        $this->runs->started('nightly', new \DateTimeImmutable('2026-11-10 03:15:00', new DateTimeZone('Africa/Johannesburg')));
        $this->runs->succeeded('nightly', new \DateTimeImmutable('2026-11-10 03:15:05', new DateTimeZone('Africa/Johannesburg')), 'Ran.');
        $scheduler = new Scheduler([$nightly], $this->runs, $this->clock, new DateTimeZone('Africa/Johannesburg'), development: true);
        $status = new TaskStatus($scheduler, $this->runs, $this->clock);
        self::assertTrue($status->isOverdue('nightly'), 'Two days without a run: overdue.');

        $scheduler->run(['nightly'], null, new \DateTimeImmutable('2026-11-12 03:15', new DateTimeZone('Africa/Johannesburg')));

        self::assertTrue($status->isOverdue('nightly'), 'A trial hides no outage.');
        $task = $status->tasks()[0];
        self::assertSame(['10 November 2026 at 03:15', 1, '10:00', 'As at 2026-11-12 03:15: Ran at 03:15.', false], [$task['last_run'], $task['run_count'], $task['last_trial'], $task['trial_summary'], $task['trial_failed']]);
        self::assertSame(['nightly'], array_keys($scheduler->run()), 'Today\'s 03:15 run is still due, and runs.');
        self::assertFalse($status->isOverdue('nightly'));
    }

    /**
     * Review finding 10: a trial is refused unless the scheduler is told it is on a development site, and whenever the
     * process's APP_ENV says production, in the scheduler itself and not only in the command; the command refuses Symfony's
     * prod environment and --now given empty, which never runs the task for real.
     */
    public function testATrialIsRefusedAnywhereButADevelopmentSite(): void
    {
        $orders = new ExampleTask('orders', 60);
        $at = new \DateTimeImmutable('2026-12-01 09:00');
        // --now given empty is a mistake, never a run for real.
        $empty = new CommandTester(new ScheduleRunCommand(new Scheduler([$orders], $this->runs, $this->clock), null, 'development'));
        self::assertSame(Command::INVALID, $empty->execute(['--job' => ['orders'], '--now' => '']), '--now given empty is refused.');
        self::assertStringContainsString('it was given empty, so nothing ran', $empty->getDisplay());
        self::assertSame(0, $orders->runs, 'Not run for real.');
        // Symfony's prod environment is production.
        $prod = new CommandTester(new ScheduleRunCommand(new Scheduler([$orders], $this->runs, $this->clock), null, 'prod'));
        self::assertSame(Command::INVALID, $prod->execute(['--job' => ['orders'], '--now' => '2026-12-01 09:00']), 'Symfony\'s prod is production.');
        self::assertSame(0, $orders->runs);

        // A scheduler a project made itself, not told it is on a development site, or the bundle's for a kernel not in debug.
        $untold = new Scheduler([$orders], $this->runs, $this->clock);
        self::assertSame(Scheduler::TRIALS_REFUSED . "\n", $prod->getDisplay());
        self::assertSame(Scheduler::TRIALS_REFUSED, $untold->trialRefused());
        try {
            $untold->run(['orders'], null, $at);
            self::fail('A trial ran where the scheduler was not told it is a development site.');
        } catch (RuntimeException $refused) {
            self::assertSame(Scheduler::TRIALS_REFUSED, $refused->getMessage());
        }
        $refusing = new CommandTester(new ScheduleRunCommand($untold, null, 'development'));
        self::assertSame(Command::INVALID, $refusing->execute(['--job' => ['orders'], '--now' => '2026-12-01 09:00']), 'The command asks the scheduler.');
        self::assertSame(Scheduler::TRIALS_REFUSED . "\n", $refusing->getDisplay());

        // The process's APP_ENV says production, whatever the scheduler was told.
        $development = new Scheduler([$orders], $this->runs, $this->clock, development: true);
        [$server, $process] = [$_SERVER['APP_ENV'] ?? null, getenv('APP_ENV')];
        $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
        try {
            self::assertSame(Scheduler::TRIALS_REFUSED, $development->trialRefused());
            try {
                $development->run(['orders'], null, $at);
                self::fail('A trial ran in production.');
            } catch (RuntimeException $refused) {
                self::assertSame(Scheduler::TRIALS_REFUSED, $refused->getMessage());
            }
        } finally {
            if ($server === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $server;
            }
            putenv($process === false ? 'APP_ENV' : 'APP_ENV=' . $process);
        }
        self::assertNull($development->trialRefused(), 'A development site, APP_ENV not production: tried.');
        self::assertSame(0, $orders->runs, 'Nothing refused ran.');
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

    public function testToldToStopWhileWaitingTheWatchEndsAtOnce(): void
    {
        $orders = new ExampleTask('orders', 60);
        $pauses = [];
        $command = null;
        $command = new ScheduleRunCommand($this->scheduler([$orders]), function (int $seconds) use (&$pauses, &$command): void {
            $pauses[] = $seconds;
            // The container is stopped while the watch waits for the next minute.
            $command?->handleSignal(15);
        });
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--watch' => true]), 'It ends by itself, and well.');
        self::assertSame([60], $pauses, 'No second wait, and no second run.');
        self::assertSame(1, $orders->runs);
        self::assertTrue($command->isStopping());
        self::assertStringContainsString('Told to stop: stopped.', $tester->getDisplay());
    }

    public function testToldToStopDuringATaskItFinishesThatTaskAndStartsNoOther(): void
    {
        $first = new StoppingTask();
        $second = new ExampleTask('mailing', 60);
        $command = new ScheduleRunCommand($this->scheduler([$first, $second]), static function (): void {
            throw new RuntimeException('It waited after being told to stop.');
        });
        $first->command = $command;

        self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute(['--watch' => true]));
        self::assertSame(1, $first->runs, 'The task under way finished.');
        self::assertSame(0, $second->runs, 'The next was not started, and stays due.');
        self::assertSame(['orders'], array_keys($this->runs->all()), 'Only the task that ran is recorded as run.');
    }

    public function testItListensForTheStopSignalsWherePhpCanCatchThem(): void
    {
        $command = new ScheduleRunCommand($this->scheduler([]));
        self::assertInstanceOf(SignalableCommandInterface::class, $command);
        self::assertSame(function_exists('pcntl_signal') ? [15, 2] : [], $command->getSubscribedSignals(), 'SIGTERM and SIGINT.');
        self::assertFalse($command->handleSignal(2), 'It carries on until the task under way is done.');
    }

    /** As a worker container runs it: SIGTERM while a task runs, and the process finishes the task and ends well, at once. */
    public function testAStoppedProcessFinishesItsTaskAndEndsWithinMoments(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('proc_open')) {
            self::markTestSkipped('Needs pcntl and proc_open.');
        }
        $marker = $this->folder . '/finished.txt';
        $script = $this->folder . '/watch.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
use CattoPHP\Scheduler\{FileRunStore, ScheduleRunCommand, Scheduler, ScheduledTask};
use Symfony\Component\Console\Application;
$task = new class (' . var_export($marker, true) . ') implements ScheduledTask {
    public function __construct(private string $marker) {}
    public function key(): string { return "slow"; }
    public function label(): string { return "Slow"; }
    public function description(): string { return "Takes a moment."; }
    public function interval(): int { return 60; }
    public function runsAt(): ?string { return null; }
    public function overdueAfter(): int { return 300; }
    public function run(DateTimeImmutable $now): string { fwrite(STDOUT, "started\n"); usleep(1500000); file_put_contents($this->marker, "done"); return "Finished."; }
};
$application = new Application("test");
$application->setAutoExit(false);
$application->addCommand(new ScheduleRunCommand(new Scheduler([$task], new FileRunStore(' . var_export($this->folder . '/runs.json', true) . '), new Symfony\Component\Clock\NativeClock())));
exit($application->run(new Symfony\Component\Console\Input\ArrayInput(["command" => "cattophp:schedule:run", "--watch" => true])));
');
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $line = fgets($pipes[1]);
        self::assertSame("started\n", $line, 'The task is under way.');
        $sent = microtime(true);
        proc_terminate($process, 15);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, 'Ended well, not killed: ' . $output);
        self::assertFileExists($marker, 'The task under way finished.');
        self::assertStringContainsString('slow: Finished.', $output);
        self::assertStringContainsString('Told to stop: stopped.', $output);
        self::assertLessThan(10, microtime(true) - $sent, 'At once, not after the minute\'s wait.');
    }

    /** A PHP that defines the signals but may not catch them (pcntl_signal disabled) still runs its tasks: it listens for none. */
    public function testWhereSignalsCannotBeCaughtItListensForNoneAndStillRuns(): void
    {
        if (!function_exists('proc_open') || !defined('SIGTERM')) {
            self::markTestSkipped('Needs proc_open and pcntl.');
        }
        $script = $this->folder . '/once.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
use CattoPHP\Scheduler\{FileRunStore, ScheduleRunCommand, Scheduler};
use CattoPHP\Tests\Support\ExampleTask;
use Symfony\Component\Console\Application;
$command = new ScheduleRunCommand(new Scheduler([new ExampleTask("orders")], new FileRunStore(' . var_export($this->folder . '/runs.json', true) . '), new Symfony\Component\Clock\NativeClock()));
fwrite(STDOUT, "signals: " . json_encode([defined("SIGTERM"), function_exists("pcntl_signal"), $command->getSubscribedSignals()]) . "\n");
$application = new Application("test");
$application->setAutoExit(false);
$application->addCommand($command);
exit($application->run(new Symfony\Component\Console\Input\ArrayInput(["command" => "cattophp:schedule:run"])));
');
        $process = proc_open([PHP_BINARY, '-d', 'disable_functions=pcntl_signal', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $output);
        self::assertStringContainsString('signals: [true,false,[]]', $output, 'The signals defined, but not to be caught: it listens for none.');
        self::assertStringContainsString('orders: Ran at', $output, 'And runs its tasks, never "Signals are not supported".');
    }

    /** @param list<ScheduledTask> $tasks */
    private function scheduler(array $tasks): Scheduler
    {
        return new Scheduler($tasks, $this->runs, $this->clock, new DateTimeZone('Africa/Johannesburg'));
    }
}
