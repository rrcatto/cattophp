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
 * is not arriving. Two-step sign-in is still asked for afterwards. Where people join by signing in (JoiningAccounts), an
 * address with no account that may join gets a link too, and its account is made when the link is used.
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
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $account = $this->accounts->forEmail($email);
        if ($account === null && $this->links->joinable() && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && $this->rules->mayJoin($email)) {
            // An address with no account that may join: its account is made when the link is used.
            $output->writeln($this->links->address($this->links->createJoining($email)));

            return Command::SUCCESS;
        }
        if ($account === null || !$this->rules->maySignIn($account)) {
            $output->writeln('No account with that address may sign in.');

            return Command::FAILURE;
        }
        $output->writeln($this->links->address($this->links->create($account)));

        return Command::SUCCESS;
    }
}
