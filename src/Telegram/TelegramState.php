<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use Doctrine\DBAL\Connection;

/**
 * Where the Telegram module keeps its few values (cattophp_telegram_state): the bot's connection, how far the reader of
 * what people send the bot has got, and Telegram's address when a check stands in for it. An application's own values in
 * the same form move in as they stand.
 */
class TelegramState
{
    public const BOT = 'bot';
    public const INBOX = 'inbox';
    public const ADDRESS = 'address';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function get(string $name): ?string
    {
        $value = $this->connection->fetchOne('SELECT value FROM cattophp_telegram_state WHERE name = :name', ['name' => $name]);

        return is_string($value) ? $value : null;
    }

    /** @param string|null $by who changed it, by account id */
    public function set(string $name, string $value, ?string $by = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_telegram_state (name, value, updated_by) VALUES (:name, :value, :by)
             ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by',
            ['name' => $name, 'value' => $value, 'by' => $by]
        );
    }

    public function remove(string $name): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_telegram_state WHERE name = :name', ['name' => $name]);
    }

    /** An account being deleted: the bot's values it saved stay, no longer saying who saved them. Returns how many. */
    public function forget(string $account): int
    {
        return (int) $this->connection->executeStatement('UPDATE cattophp_telegram_state SET updated_by = NULL WHERE updated_by = :account', ['account' => $account]);
    }
}
