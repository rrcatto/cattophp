<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `bin/console cattophp:themes:install`: installs every theme shipped in the project's code and publishes each one's
 * browser files into the web root, as an installation or an update does once the code is in place. The default theme is
 * read from the code and only published; an installed release is left as it is (published again) unless --force asks
 * for it to be replaced, during development.
 */
#[AsCommand(name: 'cattophp:themes:install', description: 'Installs the project\'s bundled themes and publishes their browser files')]
final class InstallThemesCommand extends Command
{
    public function __construct(private readonly ThemeManager $themes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Replace an installed release of the same version (during development)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slugs = $this->themes->bundledThemeSlugs();
        if ($slugs === []) {
            $output->writeln('The project ships no themes.');

            return Command::SUCCESS;
        }
        $failed = false;
        foreach ($slugs as $slug) {
            try {
                $outcome = $this->themes->installBundledTheme($slug, (bool) $input->getOption('force'));
                $output->writeln(sprintf('%s: %s', $slug, match ($outcome) {
                    'bundled' => 'the default theme, published from the code',
                    'installed' => 'installed and published',
                    'replaced' => 'replaced and published',
                    'skipped' => 'already installed, published again',
                    default => $outcome,
                }));
            } catch (Throwable $refused) {
                $failed = true;
                $output->writeln(sprintf('%s: not installed: %s', $slug, $refused->getMessage()));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
