<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/console cattophp:sign-in-link someone@example.com`: prints a sign-in link instead of emailing it, for when email
 * is not arriving. Two-step sign-in is still asked for afterwards.
 */
#[AsCommand(name: 'cattophp:sign-in-link', description: 'Prints a sign-in link for an account, good once')]
final class SignInLinkCommand extends Command
{
    public function __construct(private readonly SignInLinks $links, private readonly SignInAccounts $accounts, private readonly SignInRules $rules)
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
        if ($account === null || !$this->rules->maySignIn($account)) {
            $output->writeln('No account with that address may sign in.');

            return Command::FAILURE;
        }
        $output->writeln($this->links->address($this->links->create($account)));

        return Command::SUCCESS;
    }
}
