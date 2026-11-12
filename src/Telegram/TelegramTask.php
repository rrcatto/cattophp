<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;

/** The owner's Telegram messages, every minute, as a scheduled task (its place in the list is `telegram.task_priority`). */
final class TelegramTask implements ScheduledTask
{
    public function __construct(private readonly OwnerMessageSender $sender)
    {
    }

    public function key(): string
    {
        return 'owner_messages';
    }

    public function label(): string
    {
        return 'Telegram messages';
    }

    public function description(): string
    {
        return 'Every minute: sends the waiting messages to your Telegram, tries again any that could not go, and drops one still unsent after 7 days. While people can sign in with Telegram, it also reads what they send the bot.';
    }

    public function interval(): int
    {
        return 60;
    }

    public function runsAt(): ?string
    {
        return null;
    }

    public function overdueAfter(): int
    {
        return 300;
    }

    public function run(DateTimeImmutable $now): string
    {
        return $this->sender->run();
    }
}
