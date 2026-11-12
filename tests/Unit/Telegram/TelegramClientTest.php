<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Telegram;

use CattoPHP\Telegram\TelegramClient;
use CattoPHP\Telegram\TelegramFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Telegram's Bot API as the client speaks it, ported from cattolms's tests. */
final class TelegramClientTest extends TestCase
{
    private const KEY = '123456789:stand-in-key-for-checks-only';

    /** @return iterable<array{MockResponse,string}> */
    public static function refusals(): iterable
    {
        yield 'the network, naming the address' => [new MockResponse('', ['error' => 'Failed to connect to https://api.telegram.org/bot' . self::KEY . '/sendMessage']), 'could not be reached'];
        yield 'a wrong key' => [new MockResponse('{"ok":false,"description":"Unauthorized"}', ['http_code' => 401]), 'did not accept the key'];
        yield 'a bot the person stopped' => [new MockResponse('{"ok":false,"description":"Forbidden: bot was blocked by the user"}', ['http_code' => 403]), 'type hello'];
        yield 'an unknown chat' => [new MockResponse('{"ok":false,"description":"Bad Request: chat not found"}', ['http_code' => 400]), 'does not know this chat'];
        yield 'too many' => [new MockResponse('{"ok":false,"description":"Too Many Requests"}', ['http_code' => 429]), 'wait a moment'];
        yield 'Telegram down' => [new MockResponse('Bad gateway', ['http_code' => 502]), 'having trouble'];
        yield 'an answer that repeats the key' => [new MockResponse('{"ok":false,"description":"No such bot ' . self::KEY . '"}', ['http_code' => 418]), 'answered 418'];
    }

    #[DataProvider('refusals')]
    public function testARefusalIsInPlainWordsAndNeverHoldsTheKey(MockResponse $answer, string $words): void
    {
        try {
            (new TelegramClient(new MockHttpClient($answer)))->send('https://api.telegram.org', self::KEY, '4242', 'Hello');
            self::fail('It was not refused.');
        } catch (TelegramFailed $failed) {
            self::assertStringContainsString($words, $failed->getMessage());
            self::assertStringNotContainsString(self::KEY, $failed->getMessage());
            self::assertStringNotContainsString('stand-in-key', $failed->getMessage());
        }
    }

    public function testAMessageIsPlainTextToTheBotsAddress(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, json_decode((string) $options['body'], true)];

            return new MockResponse('{"ok":true,"result":{"message_id":1}}');
        });
        (new TelegramClient($http))->send('https://api.telegram.org', self::KEY, '4242', 'Sale: <b>not bold</b>');
        self::assertSame(['POST', 'https://api.telegram.org/bot' . self::KEY . '/sendMessage'], [$seen[0], $seen[1]]);
        self::assertSame(['chat_id' => '4242', 'text' => 'Sale: <b>not bold</b>', 'link_preview_options' => ['is_disabled' => true]], $seen[2], 'No parse mode, so a name cannot break it, and no link preview.');
    }

    public function testOnlyTheChatOfTheNamedAccountIsFound(): void
    {
        $update = static fn(int $id, string $username, string $type = 'private'): array => ['update_id' => $id, 'message' => ['from' => ['id' => $id, 'username' => $username], 'chat' => ['id' => $id, 'type' => $type]]];
        $answer = (string) json_encode(['ok' => true, 'result' => [$update(22, 'Your_Username'), $update(11, 'a_stranger'), $update(-33, 'your_username', 'group')]]);
        $client = static fn(): TelegramClient => new TelegramClient(new MockHttpClient(new MockResponse($answer)));
        self::assertSame('22', $client()->chatOf('https://api.telegram.org', self::KEY, 'your_username'), 'In any case, and never a group.');
        self::assertSame('11', $client()->chatOf('https://api.telegram.org', self::KEY, 'A_STRANGER'));
        self::assertNull($client()->chatOf('https://api.telegram.org', self::KEY, 'someone_else'), 'Nobody with that username has written to it.');
        $nothing = new TelegramClient(new MockHttpClient(static fn(): MockResponse => self::fail('It asks Telegram nothing without a username.')));
        self::assertNull($nothing->chatOf('https://api.telegram.org', self::KEY, ''));
        $noUsername = new TelegramClient(new MockHttpClient(new MockResponse((string) json_encode(['ok' => true, 'result' => [['update_id' => 1, 'message' => ['from' => ['id' => 5], 'chat' => ['id' => 5, 'type' => 'private']]]]]))));
        self::assertNull($noUsername->chatOf('https://api.telegram.org', self::KEY, 'your_username'), 'Someone with no username is nobody it was told about.');
    }
}
