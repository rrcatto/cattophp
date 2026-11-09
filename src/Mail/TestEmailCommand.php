<?php

declare(strict_types=1);

namespace CattoPHP\Mail;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `bin/console cattophp:test-email someone@example.com`: one test email through the configured mail server. */
#[AsCommand(name: 'cattophp:test-email', description: 'Sends a test email through the mail server this installation is set up with')]
final class TestEmailCommand extends Command
{
    public function __construct(private readonly Mailer $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('address', InputArgument::REQUIRED, 'Where to send it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = (string) $input->getArgument('address');
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('That is not an email address.');

            return Command::INVALID;
        }
        try {
            $this->mailer->sendTest($address);
        } catch (MailNotSent $failure) {
            $output->writeln($failure->getMessage());

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Sent to %s. If it does not arrive, look in the mail server\'s log.', $address));

        return Command::SUCCESS;
    }
}
