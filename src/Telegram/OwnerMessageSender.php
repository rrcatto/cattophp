<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Every minute (TelegramTask): reads what people send the bot (while they can sign in with Telegram, whether or not the
 * owner is connected), then sends the owner's waiting messages, oldest first, and stops at the first failure, because the
 * rest would fail too. A message that fails stays, with what went wrong beside it, and is tried next minute; one still
 * unsent after 7 days is dropped, because by then it tells nobody anything.
 */
class OwnerMessageSender
{
    public const BATCH = 30;

    public function __construct(
        private readonly TelegramLog $log,
        private readonly TelegramBot $bot,
        private readonly TelegramClient $telegram,
        private readonly ClockInterface $clock,
        private readonly ?TelegramInbox $inbox = null,
    ) {
    }

    public function run(): string
    {
        $dropped = $this->log->deleteWaitingBefore(DateTimeImmutable::createFromInterface($this->clock->now())->modify('-7 days'));
        $more = $dropped === 0 ? '' : ' Dropped ' . $dropped . ' older than 7 days.';
        try {
            $read = $this->inbox?->read() ?? 0;
            if ($read > 0) $more .= ' Read ' . $read . ' sent to the bot.';
        } catch (TelegramFailed $failed) {
            $more .= ' What people sent the bot could not be read: ' . $failed->getMessage();
        }
        if (!$this->bot->connected()) return 'Not connected to Telegram.' . $more;
        $key = $this->bot->key();
        $saved = $this->bot->saved();
        $address = $this->bot->address();
        $sent = 0;
        foreach ($this->log->waiting(self::BATCH) as $message) {
            try {
                $this->telegram->send($address, $key, $saved['chat_id'], $message['body']);
            } catch (TelegramFailed $failed) {
                $this->log->markFailed($message['id'], $failed->getMessage(), $saved['username']);

                return 'Sent ' . $sent . ', and the next could not go: ' . $failed->getMessage() . $more;
            }
            $this->log->markSent($message['id'], $saved['username']);
            $sent++;
        }

        return ($sent === 0 ? 'Nothing was waiting.' : 'Sent ' . $sent . ' message' . ($sent === 1 ? '' : 's') . '.') . $more;
    }
}
