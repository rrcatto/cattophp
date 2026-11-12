<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

/*
 * A stand-in for Telegram's Bot API, for the tests: it answers getMe, getUpdates (forgetting what is before an offset, as
 * Telegram does) and sendMessage (refusing a chat that blocked the bot), and keeps what was sent.
 */
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramStandIn
{
    public const KEY = '123456789:stand-in-key-for-checks-only';
    public const BOT = 'example_site_bot';

    /** @var list<array{chat_id:string,text:string,payload:array<string,mixed>}> */
    public array $sent = [];
    /** @var list<array<string,mixed>> what the bot has been sent, unconfirmed */
    public array $updates = [];
    /** @var list<string> chats that blocked the bot */
    public array $blocked = [];
    public int $getUpdatesCalls = 0;
    private int $nextUpdate = 1000;

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (preg_match('#/bot(.+)/(getMe|getUpdates|sendMessage)$#', $url, $match) !== 1 || $match[1] !== self::KEY) {
                return new MockResponse('{"ok":false,"description":"Unauthorized"}', ['http_code' => 401]);
            }
            $body = json_decode((string) ($options['body'] ?? '{}'), true) ?: [];
            if ($match[2] === 'getMe') return new MockResponse((string) json_encode(['ok' => true, 'result' => ['username' => self::BOT]]));
            if ($match[2] === 'getUpdates') {
                $this->getUpdatesCalls++;
                $offset = (int) ($body['offset'] ?? 0);
                // Telegram forgets everything before the offset once asked from it.
                if ($offset > 0) $this->updates = array_values(array_filter($this->updates, static fn(array $one): bool => $one['update_id'] >= $offset));

                return new MockResponse((string) json_encode(['ok' => true, 'result' => $this->updates]));
            }
            $chat = (string) ($body['chat_id'] ?? '');
            if (in_array($chat, $this->blocked, true)) return new MockResponse('{"ok":false,"description":"Forbidden: bot was blocked by the user"}', ['http_code' => 403]);
            $this->sent[] = ['chat_id' => $chat, 'text' => (string) ($body['text'] ?? ''), 'payload' => $body];

            return new MockResponse('{"ok":true,"result":{}}');
        });
    }

    /** Someone writes to the bot from their private chat, now or at a moment given (seconds since 1970). */
    public function writes(int|string $chat, ?string $username, string $text, ?int $at = null): void
    {
        $from = ['id' => (int) $chat] + ($username !== null ? ['username' => $username] : []);
        $this->updates[] = ['update_id' => $this->nextUpdate++, 'message' => ['message_id' => $this->nextUpdate, 'from' => $from, 'chat' => ['id' => (int) $chat, 'type' => 'private'],
            'date' => $at ?? time(), 'text' => $text]];
    }

    /** @return array{chat_id:string,text:string,payload:array<string,mixed>} one message the site sent, by its place */
    public function message(int $place): array
    {
        return $this->sent[$place] ?? throw new \RuntimeException('No message ' . $place . ' was sent.');
    }

    /** @return list<string> what went to one chat */
    public function textsTo(int|string $chat): array
    {
        return array_values(array_map(static fn(array $one): string => $one['text'], array_filter($this->sent, static fn(array $one): bool => $one['chat_id'] === (string) $chat)));
    }
}
