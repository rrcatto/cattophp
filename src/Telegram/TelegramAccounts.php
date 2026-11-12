<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use Doctrine\DBAL\Connection;

/**
 * The Telegram accounts people connected (cattophp_telegram_accounts): the private chat the bot writes to, its username
 * when it has one, and when it was connected. One Telegram account belongs to one person and a person has one. A username
 * belongs to whoever holds it now: when the bot hears it from a chat, any other row that had it loses it, so a username
 * someone gave up never reaches the account of whoever took it next. A person is their account id in text.
 */
class TelegramAccounts
{
    /** How Telegram writes a username, without its @. */
    public const USERNAME = '/^[A-Za-z0-9_]{4,32}$/';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{account:string,chat_id:string,username:?string,connected_at:string}|null */
    public function forAccount(string $account): ?array
    {
        return self::shaped($this->connection->fetchAssociative('SELECT account_id, chat_id, username, connected_at FROM cattophp_telegram_accounts WHERE account_id = :account', ['account' => $account]));
    }

    /** @return array{account:string,chat_id:string,username:?string,connected_at:string}|null */
    public function forChat(string $chatId, bool $forUpdate = false): ?array
    {
        return self::shaped($this->connection->fetchAssociative('SELECT account_id, chat_id, username, connected_at FROM cattophp_telegram_accounts WHERE chat_id = :chat' . ($forUpdate ? ' FOR UPDATE' : ''), ['chat' => $chatId]));
    }

    /**
     * The connected account a username names (with or without its @, in any case), or null.
     *
     * @return array{account:string,chat_id:string,username:?string,connected_at:string}|null
     */
    public function forUsername(string $typed): ?array
    {
        $username = self::username($typed);
        if ($username === null) return null;

        return self::shaped($this->connection->fetchAssociative('SELECT account_id, chat_id, username, connected_at FROM cattophp_telegram_accounts WHERE LOWER(username) = LOWER(:username)', ['username' => $username]));
    }

    /** Connects a person's Telegram account. The caller has made sure neither is connected to anyone else. */
    public function connect(string $account, string $chatId, ?string $username): void
    {
        $username = self::username($username);
        if ($username !== null) $this->connection->executeStatement('UPDATE cattophp_telegram_accounts SET username = NULL, updated_at = NOW() WHERE LOWER(username) = LOWER(:username) AND chat_id <> :chat', ['username' => $username, 'chat' => $chatId]);
        $this->connection->executeStatement(
            'INSERT INTO cattophp_telegram_accounts (account_id, chat_id, username) VALUES (:account, :chat, :username)
             ON CONFLICT (account_id) DO UPDATE SET chat_id = EXCLUDED.chat_id, username = EXCLUDED.username, connected_at = NOW(), updated_at = NOW()',
            ['account' => $account, 'chat' => $chatId, 'username' => $username]
        );
    }

    /** What the bot just heard a chat called: its username now, or none. Whether anything changed. */
    public function heard(string $chatId, ?string $username): bool
    {
        $username = self::username($username);
        $row = $this->forChat($chatId);
        if ($row === null || $row['username'] === $username) return false;
        if ($username !== null) $this->connection->executeStatement('UPDATE cattophp_telegram_accounts SET username = NULL, updated_at = NOW() WHERE LOWER(username) = LOWER(:username) AND chat_id <> :chat', ['username' => $username, 'chat' => $chatId]);
        $this->connection->executeStatement('UPDATE cattophp_telegram_accounts SET username = :username, updated_at = NOW() WHERE chat_id = :chat', ['username' => $username, 'chat' => $chatId]);

        return true;
    }

    /** Forgets a person's Telegram account. Whether there was one. */
    public function disconnect(string $account): bool
    {
        return $this->connection->executeStatement('DELETE FROM cattophp_telegram_accounts WHERE account_id = :account', ['account' => $account]) > 0;
    }

    /** A username as it is kept: without its @, or null when there is none or it is not one. */
    public static function username(?string $username): ?string
    {
        $username = ltrim(trim((string) $username), '@');

        return preg_match(self::USERNAME, $username) === 1 ? $username : null;
    }

    /**
     * @param array<string,mixed>|false $row
     * @return array{account:string,chat_id:string,username:?string,connected_at:string}|null
     */
    private static function shaped(array|false $row): ?array
    {
        if ($row === false) return null;

        return ['account' => (string) $row['account_id'], 'chat_id' => (string) $row['chat_id'], 'username' => is_string($row['username'] ?? null) ? $row['username'] : null, 'connected_at' => (string) $row['connected_at']];
    }
}
