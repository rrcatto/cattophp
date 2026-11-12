<?php

declare(strict_types=1);

namespace CattoPHP\Module;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `cattophp:modules`: every optional module, whether the project has switched it on, what it needs and whether that is
 * there, and its tables with whether each is in the database; then what stops a module working, and what a module would
 * do with another switched on.
 */
#[AsCommand(name: 'cattophp:modules', description: 'Lists CattoPHP\'s modules: which are switched on, what each needs and its tables')]
final class ModulesCommand extends Command
{
    /** @param list<string> $on the keys of the modules switched on */
    public function __construct(private readonly array $on, private readonly bool $database, private readonly ?Connection $connection = null)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $existing = $this->existingTables();
        $rows = [];
        foreach (Modules::all() as $module) {
            $on = in_array($module->key, $this->on, true);
            $needs = [];
            if ($module->database) $needs[] = 'the database';
            foreach (array_keys($module->needs) as $needed) $needs[] = Modules::named($needed);
            foreach ($module->packages as $class => $package) $needs[] = $package . (class_exists($class) || interface_exists($class) ? '' : ' (not installed)');
            $tables = array_map(fn(string $table): string => $existing === null ? $table : $table . (in_array($table, $existing, true) ? '' : ' (not made)'), $module->tables());
            $rows[] = [$module->label, 'cattophp.' . $module->key, $on ? 'On' : 'Off', $needs === [] ? '-' : implode(', ', $needs), $tables === [] ? '-' : implode(', ', $tables)];
        }
        $io->table(['Module', 'Setting', 'Switched', 'Needs', 'Tables'], $rows);
        $problems = Modules::problems($this->on, $this->database);
        $notes = Modules::notes($this->on);
        if ($problems !== []) $io->error($problems);
        if ($notes !== []) $io->note($notes);
        if ($problems === [] && $notes === []) $io->success('Every module switched on has what it needs.');

        return $problems === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return list<string>|null the tables in the database, or null without one */
    private function existingTables(): ?array
    {
        if (!$this->database || $this->connection === null) {
            return null;
        }
        try {
            return array_map('strval', $this->connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE 'cattophp\\_%'"));
        } catch (Throwable) {
            return null;
        }
    }
}
