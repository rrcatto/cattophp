<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use CattoPHP\Activity\Actor;
use InvalidArgumentException;

/**
 * What a settings page does with the bot: saves its key once Telegram has accepted it and the Telegram username of the
 * owner's account, finds that account's chat and no one else's, sends a test so the owner sees it reach their phone, keeps
 * the project's choices of what sends a message and whether people may sign in with Telegram, and disconnects. The key is
 * never given back to the page.
 */
class TelegramSetup
{
    /** How @BotFather writes a key: the bot's number, a colon and its secret. */
    private const KEY_SHAPE = '/^\d{3,20}:[A-Za-z0-9_-]{20,100}$/';

    /** How Telegram allows a username to be written. */
    private const USERNAME_SHAPE = '/^[A-Za-z0-9_]{5,32}$/';

    /** @param list<string> $choices the project's choices of what sends the owner a message, such as on_sale */
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly TelegramLog $log,
        private readonly TelegramWords $words,
        private readonly TelegramClient $telegram,
        private readonly ?TelegramInbox $inbox = null,
        private readonly array $choices = [],
    ) {
    }

    /**
     * Where the connection stands: `connected`, `failing` (connected, and Telegram is refusing messages), `waiting` (a key
     * and a username, and Telegram has not seen that account yet) or `none`, with each of the project's choices.
     *
     * @return array<string,mixed>
     */
    public function view(): array
    {
        $saved = $this->bot->saved();
        $status = $this->log->status();
        $connected = $this->bot->connected();
        $state = $connected ? ($status['last_error'] !== null && $status['last_error'] !== '' ? 'failing' : 'connected') : ($saved['key'] !== '' && $saved['username'] !== '' ? 'waiting' : 'none');
        $link = '@' . $saved['bot_name'] . ' to @' . $saved['username'];
        $choices = [];
        foreach ($this->choices as $name) $choices[$name] = $this->bot->choice($name);

        return $choices + ['state' => $state, 'has_key' => $saved['key'] !== '', 'connected' => $connected, 'connection_dropped' => $saved['connection_dropped'], 'bot_name' => $saved['bot_name'], 'username' => $saved['username'],
            'title' => match ($state) { 'connected' => 'Connected: ' . $link, 'failing' => 'Not getting through: ' . $link, default => 'Not connected' },
            'title_tone' => match ($state) { 'connected' => 'success', 'failing' => 'danger', default => 'neutral' },
            'sign_in' => $saved['sign_in'], 'waiting' => $status['waiting'], 'last_error' => $status['last_error'], 'choices' => $choices];
    }

    /**
     * Connects: a new key Telegram accepts and the username are kept, then that account's chat is looked for. Whether it is
     * found is the answer, because the account only shows once it has written to the bot.
     *
     * @param array{key:string,username:string} $input
     * @return array{connected:bool,key_changed:bool}
     */
    public function connect(array $input, ?Actor $actor = null): array
    {
        $key = trim($input['key']);
        $username = ltrim(trim($input['username']), '@');
        if ($key === '' && $this->bot->key() === '') throw new InvalidArgumentException('Paste the bot key.');
        // A key Telegram accepts is kept whatever is wrong with the username, so it need not be pasted again.
        $changed = false;
        if ($key !== '') {
            if (preg_match(self::KEY_SHAPE, $key) !== 1) throw new InvalidArgumentException('That is not a bot key. @BotFather gives one like 123456789:AAFbcd. Paste all of it.');
            $this->bot->setKey($key, $this->telegram->botName($this->bot->address(), $key), $actor);
            $changed = true;
        }
        if ($username === '') throw new InvalidArgumentException('Type your Telegram username.');
        if (preg_match(self::USERNAME_SHAPE, $username) !== 1) throw new InvalidArgumentException('That is not a Telegram username. It has 5 to 32 letters, numbers and underscores.');
        // The bot's username and one's own are easily confused, and a bot cannot message itself.
        if (strcasecmp($username, $this->bot->saved()['bot_name']) === 0) throw new InvalidArgumentException('That is the bot. Type your own username.');
        $this->bot->setUsername($username, $actor);
        // While people sign in with Telegram, what the bot is sent is read and used up every minute: read it now, then look
        // among the chats seen lately as well as among what is still unread.
        try {
            $this->inbox?->read();
        } catch (TelegramFailed) {
        }
        $chat = $this->telegram->chatOf($this->bot->address(), $this->bot->key(), $username) ?? $this->inbox?->seenChat($username);
        if ($chat === null) return ['connected' => false, 'key_changed' => $changed];
        $this->bot->setChat($chat, $actor);

        return ['connected' => true, 'key_changed' => $changed];
    }

    /** @param array<string,bool> $choices the project's choices by name */
    public function saveChoices(array $choices, ?bool $signIn = null, ?Actor $actor = null): void
    {
        $this->bot->setChoices(array_intersect_key($choices, array_flip($this->choices)), $actor);
        if ($signIn !== null) $this->bot->setSignIn($signIn, $actor);
    }

    /** Goes at once, not by the queue, so what Telegram says is shown on the page. */
    public function sendTest(): void
    {
        if (!$this->bot->connected()) throw new InvalidArgumentException('Connect to Telegram first.');
        $body = $this->words->worded('Test message: your messages from this site will arrive here');
        $reference = 'test:' . bin2hex(random_bytes(8));
        $username = $this->bot->saved()['username'];
        try {
            $this->telegram->send($this->bot->address(), $this->bot->key(), $this->bot->saved()['chat_id'], $body);
        } catch (TelegramFailed $failed) {
            $this->log->logTest($reference, $body, $failed->getMessage(), $username);
            throw $failed;
        }
        $this->log->logTest($reference, $body, null, $username);
    }

    public function dropConnection(?Actor $actor = null): void
    {
        $this->bot->forget($actor);
    }
}
