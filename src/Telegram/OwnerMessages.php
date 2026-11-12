<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

/**
 * A message to the owner's Telegram about something that happened (a project says what and when: a registration, a sale).
 * Each is kept inside the transaction of what it is about and sent within a minute (OwnerMessageSender), so Telegram being
 * down never holds anything up; it is kept once, by its reference, and keeping it can never make what it is about fail.
 * Nothing is kept while the bot is not connected to the owner.
 */
class OwnerMessages
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly TelegramLog $log,
        private readonly TelegramWords $words,
    ) {
    }

    /**
     * @param string $reference what it is about, once, such as order:1042
     * @param string $kind a word for the Telegram log, such as sale
     * @param string $words the message, without the site's name, which is added
     */
    public function queue(string $reference, string $kind, string $words): void
    {
        $this->log->guarded(function () use ($reference, $kind, $words): void {
            if (!$this->bot->connected()) return;
            $this->log->record($reference, $kind, $this->words->worded($words));
        });
    }

    /** Whether a message would be kept now: the bot is connected to the owner. */
    public function connected(): bool
    {
        return $this->bot->connected();
    }
}
