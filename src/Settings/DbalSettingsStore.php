<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** The overrides in the database (`cattophp_settings`). */
final class DbalSettingsStore implements SettingsStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        $stored = [];
        foreach ($this->connection->fetchAllAssociative('SELECT name, value, secret, updated_at, updated_by FROM cattophp_settings') as $row) {
            $stored[(string) $row['name']] = new StoredSetting(
                (string) $row['value'],
                (bool) $row['secret'],
                (string) $row['updated_at'],
                $row['updated_by'] === null ? null : (string) $row['updated_by'],
            );
        }

        return $stored;
    }

    public function save(string $name, string $value, bool $secret, DateTimeImmutable $at, ?string $by): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_settings (name, value, secret, updated_at, updated_by) VALUES (:name, :value, :secret, :at, :by)
             ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value, secret = EXCLUDED.secret, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
            ['name' => $name, 'value' => $value, 'secret' => $secret, 'at' => $at->format('Y-m-d H:i:sP'), 'by' => $by],
            ['secret' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
    }

    public function remove(string $name): bool
    {
        return $this->connection->executeStatement('DELETE FROM cattophp_settings WHERE name = ?', [$name]) > 0;
    }

    public function isWritable(): bool
    {
        return true;
    }
}
