<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Security\TwoStep\TwoStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/console cattophp:two-step-reset someone@example.com`: for a person who has lost their phone and their recovery
 * codes, when nobody else can reset it for them. They set up a new key at their next sign-in.
 */
#[AsCommand(name: 'cattophp:two-step-reset', description: 'Starts an account\'s two-step sign-in again')]
final class TwoStepResetCommand extends Command
{
    public function __construct(private readonly TwoStep $twoStep, private readonly SignInAccounts $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The account\'s email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $account = $this->accounts->forEmail((string) $input->getArgument('email'));
        if ($account === null) {
            $output->writeln('There is no account with that address.');

            return Command::FAILURE;
        }
        $this->twoStep->reset($account->id);
        $output->writeln('Done: they set up two-step sign-in again at their next sign-in.');

        return Command::SUCCESS;
    }
}
