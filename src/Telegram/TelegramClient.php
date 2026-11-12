<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Telegram's Bot API: JSON over HTTPS at <address>/bot<key>/<method>, no redirects, TLS verified. Plain text with no parse
 * mode, so a name cannot break a message, and no link previews, since a sign-in message's link is used up when opened.
 * The key is part of the address, so anything Telegram or the network says is scrubbed of it before it is thrown: a
 * TelegramFailed is safe to show and to store. A failure carries Telegram's answer as its code.
 */
class TelegramClient
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function send(string $address, string $key, string $chatId, string $text): void
    {
        $this->call($address, $key, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]]);
    }

    /**
     * What the bot has been sent since the offset, oldest first: reading from an offset tells Telegram that everything before it
     * has been handled, so it is never given again. Messages only, at once, never waiting.
     *
     * @return list<array<string,mixed>>
     */
    public function updates(string $address, string $key, int $offset): array
    {
        $answer = $this->call($address, $key, 'getUpdates', ['offset' => $offset, 'limit' => 100, 'timeout' => 0, 'allowed_updates' => ['message']]);

        return array_values(array_filter($answer, 'is_array'));
    }

    /** The bot this key belongs to: its username, which shows the key is good. */
    public function botName(string $address, string $key): string
    {
        return trim((string) ($this->call($address, $key, 'getMe', [])['username'] ?? ''));
    }

    /**
     * The private chat of this one account, from what the bot has been sent lately, or null when that account has not written
     * to it. A bot cannot write to a username or a phone number, only to a chat, and anyone can find a bot, so everyone else's
     * messages to it are passed over. Two phones on one account are one chat.
     */
    public function chatOf(string $address, string $key, string $username): ?string
    {
        if ($username === '') return null;
        foreach (array_reverse($this->call($address, $key, 'getUpdates', ['allowed_updates' => ['message']])) as $update) {
            $message = is_array($update) && is_array($update['message'] ?? null) ? $update['message'] : [];
            $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
            $from = is_array($message['from'] ?? null) ? $message['from'] : [];
            if (($chat['type'] ?? '') === 'private' && isset($chat['id']) && strcasecmp((string) ($from['username'] ?? ''), $username) === 0) return (string) $chat['id'];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int|string,mixed> what Telegram answered with
     */
    private function call(string $address, string $key, string $method, array $payload): array
    {
        try {
            $response = $this->http->request('POST', rtrim($address, '/') . '/bot' . $key . '/' . $method, ['json' => $payload,
                'headers' => ['Accept' => 'application/json'], 'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $network) {
            throw new TelegramFailed('Telegram could not be reached: ' . self::scrubbed($network->getMessage(), $key), 0);
        }
        if ($status < 300 && is_array($data) && ($data['ok'] ?? false) === true) return is_array($data['result'] ?? null) ? $data['result'] : [];
        $said = is_array($data) ? self::scrubbed(trim((string) ($data['description'] ?? '')), $key) : '';
        throw new TelegramFailed(match (true) {
            $status === 401, $status === 404 => 'Telegram did not accept the key. Check it with @BotFather, and paste it again.',
            $status === 403 => 'Telegram says the bot cannot write to you. Open the t.me link to your bot, type hello in the message box at the bottom and send it, then press Connect to Telegram again.',
            $status === 400 && str_contains(strtolower($said), 'chat not found') => 'Telegram does not know this chat. Open the t.me link to your bot, type hello in the message box at the bottom and send it, then press Connect to Telegram again.',
            $status === 429 => 'Telegram asks to wait a moment before trying again.',
            $status >= 500 => 'Telegram is having trouble (it answered ' . $status . ').',
            default => 'Telegram answered ' . $status . ($said !== '' ? ': ' . $said : '.'),
        }, $status);
    }

    /** What is left of a message with the key taken out, in the address or on its own. */
    private static function scrubbed(string $words, string $key): string
    {
        return $key === '' ? $words : str_replace([rawurlencode($key), $key], '[the key]', $words);
    }
}
