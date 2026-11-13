<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use CattoPHP\Installation\PhpTimeZone;
use CattoPHP\Runtime\RunMode;
use Closure;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;

/**
 * `bin/console cattophp:schedule:run`: runs every scheduled task that is due, once (as a cron line every minute would), or
 * once a minute until stopped with --watch (as a worker container does); --job runs the named tasks now, due or not, and
 * with --now as if it were another moment, on a development site only (`--job=reminders --now="2026-12-01 09:00"`, in the
 * site's time zone), for trying a time-based task without waiting or changing records by hand: a trial, kept apart from
 * the task's runs, so it never moves the schedule. It is refused where the kernel's environment is production (or
 * Symfony's prod), where APP_ENV says production, and wherever the scheduler is not told it is a development site (the
 * bundle tells it so only with the kernel in debug); --now given empty is refused too. A project may also name the
 * command as it always has (`scheduler.command_alias`).
 *
 * Told to stop (SIGTERM, as a container is stopped, or SIGINT, Ctrl+C), it finishes the task under way, starts no other
 * and ends at once, waiting or not: a container whose first process it is stops in a moment instead of being killed
 * after its grace period. It needs PHP's pcntl extension, as Symfony's console does for every signal.
 */
#[AsCommand(name: 'cattophp:schedule:run', description: 'Runs the scheduled tasks that are due')]
final class ScheduleRunCommand extends Command implements SignalableCommandInterface
{
    /** @var Closure(int):void */
    private readonly Closure $pause;

    /** Whether it has been told to stop. */
    private bool $stopping = false;

    /**
     * @param (Closure(int):void)|null $pause how the watch waits between runs (tests stop it)
     * @param string $environment the kernel's environment: --now is refused in production
     * @param PhpTimeZone|null $timeZone PHP's time zone set to the installation's before each run, so a time zone changed
     *     on the Settings page applies under --watch without a restart
     */
    public function __construct(private readonly Scheduler $scheduler, ?Closure $pause = null, private readonly string $environment = RunMode::DEVELOPMENT, private readonly ?PhpTimeZone $timeZone = null)
    {
        // A second at a time, so a stop is seen at once even where a signal does not cut sleep() short.
        $this->pause = $pause ?? function (int $seconds): void {
            for ($waited = 0; $waited < $seconds && !$this->stopping; ++$waited) {
                sleep(1);
            }
        };
        parent::__construct();
    }

    /**
     * SIGTERM and SIGINT, where PHP can catch them: pcntl loaded with its signal functions allowed, as Symfony's console
     * asks (a PHP that defines the signals but has pcntl_signal in disable_functions cannot, and would be refused).
     *
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return self::signalsSupported() ? [(int) constant('SIGTERM'), (int) constant('SIGINT')] : [];
    }

    /** Whether this PHP can catch a signal: what Symfony's console checks before it listens for any. */
    public static function signalsSupported(): bool
    {
        return defined('SIGTERM') && defined('SIGINT') && SignalRegistry::isSupported();
    }

    /** Told to stop: the task under way finishes, and then the command ends. False: carry on until then. */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopping = true;

        return false;
    }

    /** Whether it has been told to stop. */
    public function isStopping(): bool
    {
        return $this->stopping;
    }

    protected function configure(): void
    {
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Run once a minute until stopped.');
        $this->addOption('job', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run this task now, due or not (by its key; may be given more than once).');
        $this->addOption('now', null, InputOption::VALUE_REQUIRED, 'With --job, on a development site: run the task as if it were this moment, such as "2026-12-01 09:00" or "+3 days", in the site\'s time zone. A trial, kept apart from the task\'s runs: its schedule is not moved.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = array_values(array_filter(array_map('strval', (array) $input->getOption('job')), static fn(string $key): bool => $key !== ''));
        $only = $keys === [] ? null : $keys;
        foreach ($keys as $key) {
            if ($this->scheduler->task($key) === null) {
                $output->writeln('<error>There is no scheduled task ' . OutputFormatter::escape($key) . '. Choose one of: ' . implode(', ', array_map(static fn(ScheduledTask $task): string => $task->key(), $this->scheduler->tasks())) . '.</error>');
                return Command::INVALID;
            }
        }
        $watch = (bool) $input->getOption('watch');
        $at = null;
        $typed = $input->getOption('now');
        if (is_string($typed) && trim($typed) === '') {
            // Given but empty is a mistake, never a run for real.
            $output->writeln('<error>--now takes a date and time, such as "2026-12-01 09:00" or "+3 days", in the site\'s time zone (' . $this->scheduler->zone()->getName() . '): it was given empty, so nothing ran.</error>');
            return Command::INVALID;
        }
        if (is_string($typed)) {
            $refused = match (true) {
                RunMode::environment($this->environment) === RunMode::PRODUCTION || strtolower(trim($this->environment)) === 'prod' => Scheduler::TRIALS_REFUSED,
                $this->scheduler->trialRefused() !== null => $this->scheduler->trialRefused(),
                $only === null => 'Name the task to run at that moment with --job, such as --job=' . (($this->scheduler->tasks()[0] ?? null)?->key() ?? '<task>') . ' --now="' . OutputFormatter::escape(trim($typed)) . '".',
                $watch => '--now runs the named tasks once: leave out --watch.',
                default => null,
            };
            if ($refused === null) {
                try {
                    $at = $this->scheduler->moment($typed);
                } catch (InvalidArgumentException) {
                    $refused = '--now takes a date and time, such as "2026-12-01 09:00" or "+3 days", in the site\'s time zone (' . $this->scheduler->zone()->getName() . '): "' . OutputFormatter::escape(trim($typed)) . '" is not one.';
                }
            }
            if ($refused !== null) {
                $output->writeln('<error>' . $refused . '</error>');
                return Command::INVALID;
            }
            $output->writeln('As at ' . $at->format('j F Y, H:i') . ' (' . $at->getTimezone()->getName() . '), on a development site: a trial, kept apart from the task\'s runs.');
        }
        while (true) {
            $failed = false;
            $this->timeZone?->apply();
            foreach ($this->scheduler->run($only, fn (): bool => $this->stopping, $at) as $key => $result) {
                $failed = $failed || !$result['ok'];
                $output->writeln(($result['ok'] ? '' : '<error>') . $key . ': ' . OutputFormatter::escape($result['summary']) . ($result['ok'] ? '' : '</error>'));
            }
            if (!$watch || $this->stopping) return $this->stopped($output, $failed);
            ($this->pause)(60);
            if ($this->stopping) return $this->stopped($output, false);
        }
    }

    private function stopped(OutputInterface $output, bool $failed): int
    {
        if ($this->stopping) {
            $output->writeln('Told to stop: stopped.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
