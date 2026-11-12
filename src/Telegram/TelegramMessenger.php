<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use Throwable;

/**
 * Messages to a person's Telegram (a sign-in message, the answer when they connect, the bot's answer to someone who writes
 * to it, a test from their account page). Each goes at once, never through the owner's queue, and is kept in the Telegram
 * log with the chat and the account it went to at that moment, whether it went or not. Nothing secret is kept: a sign-in
 * message is logged by what it was, never by what it said, and its secrets are taken out of anything Telegram says.
 *
 * A sign-in message is held until the answer to the form has gone (TelegramDeliveryListener), so the page answers in the
 * same time whether or not the account exists. A check or a command delivers what is held itself.
 */
class TelegramMessenger
{
    public const BLOCKED = 'Telegram says this person has blocked the bot.';

    /** @var list<array{kind:string,chat:string,account:?string,username:?string,text:string,logged:string,secrets:list<string>}> */
    private array $held = [];

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly TelegramLog $log,
        private readonly TelegramClient $telegram,
    ) {
    }

    /**
     * Sends at once and keeps it in the Telegram log. Whether it went.
     *
     * @param string|null $account the person's account id, when the chat is someone's here
     * @param string $logged what the log keeps instead of the text, when the text holds a secret
     * @param list<string> $secrets kept out of anything stored, whatever Telegram says
     */
    public function send(string $kind, string $chatId, ?string $account, ?string $username, string $text, string $logged = '', array $secrets = [], string $reference = ''): bool
    {
        $error = null;
        $key = $this->bot->key();
        if ($key === '') {
            $error = 'No bot key is saved.';
        } else {
            try {
                $this->telegram->send($this->bot->address(), $key, $chatId, $text);
            } catch (TelegramFailed $failed) {
                $error = match ($failed->getCode()) {
                    403 => self::BLOCKED,
                    default => str_contains(strtolower($failed->getMessage()), 'does not know this chat') ? 'Telegram does not know this chat.' : $failed->getMessage(),
                };
            } catch (Throwable $fault) {
                $error = 'The message could not be sent (' . $fault::class . ').';
            }
        }
        if ($error !== null) $error = str_replace(array_values(array_filter($secrets, static fn(string $one): bool => $one !== '')), '[kept out]', $error);
        $reference = $reference !== '' ? $reference : ($kind === 'connected' ? 'reply' : $kind) . ':' . bin2hex(random_bytes(8));
        $this->log->guarded(fn() => $this->log->logSent($reference, $kind, $logged !== '' ? $logged : $text, $chatId, $account, $username, $error));

        return $error === null;
    }

    /** @param list<string> $secrets */
    public function hold(string $kind, string $chatId, ?string $account, ?string $username, string $text, string $logged, array $secrets): void
    {
        $this->held[] = ['kind' => $kind, 'chat' => $chatId, 'account' => $account, 'username' => $username, 'text' => $text, 'logged' => $logged, 'secrets' => $secrets];
    }

    /** Sends what is held. How many went. */
    public function deliverHeld(): int
    {
        $sent = 0;
        while (($one = array_shift($this->held)) !== null) {
            if ($this->send($one['kind'], $one['chat'], $one['account'], $one['username'], $one['text'], $one['logged'], $one['secrets'])) $sent++;
        }

        return $sent;
    }
}
