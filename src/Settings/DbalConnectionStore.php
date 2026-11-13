<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use Doctrine\DBAL\Connection;

/** The connections' states in the database: `cattophp_connections`, one row a connection, its state as JSON. */
final class DbalConnectionStore implements ConnectionStore
{
    public const TABLE = 'cattophp_connections';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function get(string $name): array
    {
        $raw = $this->connection->fetchOne('SELECT state::text FROM ' . self::TABLE . ' WHERE name = ?', [$name]);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            return [];
        }
        $named = [];
        foreach ($state as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    public function put(string $name, array $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (name, state, updated_at) VALUES (:name, CAST(:state AS jsonb), NOW())'
            . ' ON CONFLICT (name) DO UPDATE SET state = EXCLUDED.state, updated_at = NOW()',
            ['name' => $name, 'state' => json_encode((object) $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );
    }

    public function remove(string $name): void
    {
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE name = ?', [$name]);
    }
}
