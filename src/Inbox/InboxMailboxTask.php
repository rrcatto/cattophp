<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;

/** Every minute: the answers in the mailbox filed into their conversations (InboxMailbox). */
final class InboxMailboxTask implements ScheduledTask
{
    public function __construct(private readonly InboxMailbox $mailbox)
    {
    }

    public function key(): string
    {
        return 'inbox_mailbox';
    }

    public function label(): string
    {
        return 'Inbox answers';
    }

    public function description(): string
    {
        return 'Every minute: with a mailbox connected in Inbox settings (by Check the connection), reads what is new there and puts each answer into its conversation, leaving everything else in the mailbox as it is.';
    }

    public function interval(): int
    {
        return 60;
    }

    public function runsAt(): null
    {
        return null;
    }

    public function overdueAfter(): int
    {
        return 300;
    }

    public function run(DateTimeImmutable $now): string
    {
        return $this->mailbox->run();
    }
}
