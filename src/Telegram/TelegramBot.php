<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use CattoPHP\Activity\Actor;
use CattoPHP\Security\SecretCipher;
use JsonException;
use Throwable;

/**
 * The site's Telegram bot: its key, encrypted and never shown back, its own username, the Telegram username of the owner's
 * account and that account's chat, whether people may sign in with Telegram, and the project's own choices of what sends
 * the owner a message (each on until a person turns it off). No phone number is kept, and there is no .env value: nothing
 * starts it but connecting it. Telegram's address is a value of its own that no page sets, so a check can stand in for
 * Telegram without ever sending a key to the real service.
 */
class TelegramBot
{
    public const TELEGRAM = 'https://api.telegram.org';

    /** The connection's own values; anything else saved beside them is a project's choice. */
    private const CORE = ['key', 'bot_name', 'username', 'chat_id', 'sign_in', 'disconnected'];

    public function __construct(private readonly TelegramState $state, private readonly SecretCipher $cipher)
    {
    }

    /** @return array{key:string,bot_name:string,username:string,chat_id:string,sign_in:bool,disconnected:bool} what is saved, the key still encrypted */
    public function saved(): array
    {
        $saved = $this->raw();

        return ['key' => (string) ($saved['key'] ?? ''), 'bot_name' => (string) ($saved['bot_name'] ?? ''), 'username' => (string) ($saved['username'] ?? ''), 'chat_id' => (string) ($saved['chat_id'] ?? ''),
            'sign_in' => (bool) ($saved['sign_in'] ?? false), 'disconnected' => (bool) ($saved['disconnected'] ?? false)];
    }

    /** A project's choice, such as whether a sale sends the owner a message: on until it is turned off. */
    public function choice(string $name, bool $default = true): bool
    {
        $saved = $this->raw();

        return array_key_exists($name, $saved) ? (bool) $saved[$name] : $default;
    }

    /** The bot's key to use, or nothing when none is saved or it cannot be read. */
    public function key(): string
    {
        try {
            return $this->cipher->decrypt($this->saved()['key']);
        } catch (Throwable) {
            return '';
        }
    }

    /** Whether people may sign in with Telegram: turned on, and a bot key saved to send with. */
    public function signInOffered(): bool
    {
        return $this->saved()['sign_in'] && $this->key() !== '';
    }

    /** The bot's own username, for a person's t.me link. */
    public function botName(): string
    {
        return $this->saved()['bot_name'];
    }

    /** Whether messages to the owner can go: a key and the owner's chat. */
    public function connected(): bool
    {
        return $this->saved()['chat_id'] !== '' && $this->key() !== '';
    }

    public function address(): string
    {
        return $this->state->get(TelegramState::ADDRESS) ?? self::TELEGRAM;
    }

    public function setKey(string $key, string $botName, ?Actor $actor = null): void
    {
        // Another bot has another chat with the owner, so it is found again.
        $this->write(['key' => $this->cipher->encrypt($key), 'bot_name' => $botName, 'chat_id' => '', 'disconnected' => false] + $this->raw(), $actor);
    }

    /** @param array<string,bool> $choices a project's choices by name */
    public function setChoices(array $choices, ?Actor $actor = null): void
    {
        $this->write(array_diff_key($choices, array_flip(self::CORE)) + $this->raw(), $actor);
    }

    public function setSignIn(bool $signIn, ?Actor $actor = null): void
    {
        $this->write(['sign_in' => $signIn] + $this->raw(), $actor);
    }

    /** Another account has another chat, so a changed username is found again and nothing goes to the old one. */
    public function setUsername(string $username, ?Actor $actor = null): void
    {
        $saved = $this->saved();
        $this->write(['username' => $username, 'chat_id' => strcasecmp($saved['username'], $username) === 0 ? $saved['chat_id'] : ''] + $this->raw(), $actor);
    }

    public function setChat(string $id, ?Actor $actor = null): void
    {
        $this->write(['chat_id' => $id] + $this->raw(), $actor);
    }

    /** The key and the account go; the choices and sign-in stay. The page then says Disconnected. */
    public function forget(?Actor $actor = null): void
    {
        $kept = array_diff_key($this->raw(), array_flip(self::CORE));
        $this->write(['sign_in' => $this->saved()['sign_in']] + $kept + ['disconnected' => true], $actor);
    }

    /** @return array<string,mixed> */
    private function raw(): array
    {
        try {
            $saved = json_decode((string) $this->state->get(TelegramState::BOT), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($saved) ? $saved : [];
    }

    /** @param array<string,mixed> $values */
    private function write(array $values, ?Actor $actor): void
    {
        $this->state->set(TelegramState::BOT, json_encode($values, JSON_THROW_ON_ERROR), $actor?->id);
    }
}
