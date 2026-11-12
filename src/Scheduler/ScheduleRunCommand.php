<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use Closure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/console cattophp:schedule:run`: runs every scheduled task that is due, once (as a cron line every minute would), or
 * once a minute until stopped with --watch (as a worker container does); --job runs the named tasks now, due or not. A
 * project may also name it as it always has (`scheduler.command_alias`).
 */
#[AsCommand(name: 'cattophp:schedule:run', description: 'Runs the scheduled tasks that are due')]
final class ScheduleRunCommand extends Command
{
    /** @var Closure(int):void */
    private readonly Closure $pause;

    /** @param (Closure(int):void)|null $pause how the watch waits between runs (tests stop it) */
    public function __construct(private readonly Scheduler $scheduler, ?Closure $pause = null)
    {
        $this->pause = $pause ?? static function (int $seconds): void { sleep($seconds); };
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Run once a minute until stopped.');
        $this->addOption('job', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run this task now, due or not (by its key; may be given more than once).');
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
        while (true) {
            $failed = false;
            foreach ($this->scheduler->run($only) as $key => $result) {
                $failed = $failed || !$result['ok'];
                $output->writeln(($result['ok'] ? '' : '<error>') . $key . ': ' . OutputFormatter::escape($result['summary']) . ($result['ok'] ? '' : '</error>'));
            }
            if (!$watch) return $failed ? Command::FAILURE : Command::SUCCESS;
            ($this->pause)(60);
        }
    }
}
