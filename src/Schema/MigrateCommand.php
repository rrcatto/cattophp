<?php

declare(strict_types=1);

namespace CattoPHP\Schema;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/console cattophp:migrate`: applies the schema steps not applied yet, CattoPHP's and the project's own (its classes
 * implementing SchemaStep), in the order of their ids; `--dry-run` only lists what it would apply.
 */
#[AsCommand(name: 'cattophp:migrate', description: 'Applies the schema steps not yet applied: CattoPHP\'s and the project\'s own')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly SchemaRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the steps it would apply, and apply nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pending = $this->runner->pending();
        if ($pending === []) {
            $output->writeln('Nothing to apply: every step is applied.');

            return Command::SUCCESS;
        }
        if ($input->getOption('dry-run') === true) {
            $output->writeln(sprintf('%d %s would be applied:', count($pending), count($pending) === 1 ? 'step' : 'steps'));
            foreach ($pending as $step) {
                $output->writeln(sprintf('  %s  %s', $step->id(), $step->describe()));
            }

            return Command::SUCCESS;
        }
        $done = $this->runner->migrate(static function (SchemaStep $step) use ($output): void {
            $output->writeln(sprintf('Applying %s  %s', $step->id(), $step->describe()));
        });
        $output->writeln(sprintf('Done: %d %s applied.', count($done), count($done) === 1 ? 'step' : 'steps'));

        return Command::SUCCESS;
    }
}
