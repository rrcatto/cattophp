<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cattophp:icons:search <words>`: the icon library's icons that match the words, best first, by their names and the
 * words the library gives each (Lucide's tags), as IconLibrary::search() finds them: one a line, its name as a template
 * or a sprite names it (`lucide('drill')`), then its words. It exits 1 when no icon matches, so a name is never guessed.
 */
#[AsCommand(name: 'cattophp:icons:search', description: 'Lists the icon library\'s icons that match the words, by name and by the words each is tagged with')]
final class IconSearchCommand extends Command
{
    public function __construct(private readonly IconLibrary $library)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('words', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'What the icon should show, such as drill or "hand coins": an icon matches every word')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many icons at most', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $given */
        $given = $input->getArgument('words');
        $words = trim(implode(' ', $given));
        $limit = $input->getOption('limit');
        if (!is_string($limit) || preg_match('/^[1-9]\d{0,3}$/', $limit) !== 1) {
            $output->writeln('The limit is a whole number from 1 to 9999, such as --limit=50.');

            return Command::INVALID;
        }
        $found = $this->library->search($words, (int) $limit);
        if ($found === []) {
            $output->writeln(sprintf('No icon matches "%s". Try one word for what the icon shows, such as tool or box, or fewer words: an icon must match every one.', $words));

            return Command::FAILURE;
        }
        $width = max(array_map('strlen', $found));
        foreach ($found as $name) {
            $tags = $this->library->tags($name);
            $output->writeln($tags === [] ? $name : str_pad($name, $width) . '  ' . implode(', ', $tags));
        }
        $output->writeln(sprintf('%d %s "%s"%s, best first.', count($found), count($found) === 1 ? 'icon matches' : 'icons match', $words,
            count($found) === (int) $limit ? sprintf(' (the first %d: --limit shows more)', (int) $limit) : ''));

        return Command::SUCCESS;
    }
}
