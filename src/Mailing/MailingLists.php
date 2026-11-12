<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The lists (cattophp_mailing_lists). A list about a subject (kind and subject, such as a course a person follows; a kind
 * may have no subject, such as a site's news) is one per kind and subject, made when its first person says yes. A list an
 * administrator makes is of the kind 'own': a name, a few words saying what it is for, whether the project shows it for
 * people to join, a join link's token, and closed or open. Any list may have its own sender name and reply-to address.
 * What a subject is called is the project's to say.
 */
final class MailingLists
{
    public const TABLE = 'cattophp_mailing_lists';
    public const OWN = 'own';
    private const CHANGEABLE = ['name', 'description', 'shown', 'from_name', 'reply_to', 'closed_at', 'join_token'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** The list for a kind and subject, made when it is first needed; null when there is none and none is to be made. */
    public function listId(string $kind, int|string|null $subject, bool $create): ?int
    {
        if ($kind === self::OWN) return null;
        $subject = $subject === null ? null : (string) $subject;
        $id = $this->connection->fetchOne('SELECT id FROM ' . self::TABLE . " WHERE kind = :kind AND COALESCE(subject, '') = :subject", ['kind' => $kind, 'subject' => $subject ?? '']);
        if ($id !== false) return (int) $id;
        if (!$create) return null;

        return (int) $this->connection->fetchOne(
            'INSERT INTO ' . self::TABLE . " (kind, subject) VALUES (:kind, :subject) ON CONFLICT (kind, COALESCE(subject, '')) WHERE kind <> 'own' DO UPDATE SET kind = EXCLUDED.kind RETURNING id",
            ['kind' => $kind, 'subject' => $subject]
        );
    }

    /** @return array<string,mixed>|null any list: its kind, subject, name, sender and whether it is closed */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, kind, subject, name, description, shown, join_token, from_name, reply_to, closed_at, created_at FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : self::row($row);
    }

    /**
     * The lists an administrator made, with how many are on each: all of them, or only those people may join now; open
     * lists first, then by name.
     *
     * @return list<array<string,mixed>>
     */
    public function ownLists(bool $openOnly = false): array
    {
        return array_map(self::row(...), $this->connection->fetchAllAssociative(
            'SELECT l.*, (SELECT COUNT(*) FROM ' . Subscriptions::TABLE . " s WHERE s.list_id = l.id AND s.status = 'subscribed') AS followers
               FROM " . self::TABLE . " l WHERE l.kind = 'own'" . ($openOnly ? ' AND l.closed_at IS NULL' : '') . ' ORDER BY l.closed_at IS NOT NULL, lower(l.name)'
        ));
    }

    /** @return array<string,mixed>|null a list an administrator made, with how many are on it */
    public function ownList(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT l.*, (SELECT COUNT(*) FROM ' . Subscriptions::TABLE . " s WHERE s.list_id = l.id AND s.status = 'subscribed') AS followers
            FROM " . self::TABLE . " l WHERE l.id = :id AND l.kind = 'own'", ['id' => $id]);

        return $row === false ? null : self::row($row);
    }

    /** @return array<string,mixed>|null an open list an administrator made, by its join link's token */
    public function ownListByToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) return null;
        $row = $this->connection->fetchAssociative('SELECT l.*, 0 AS followers FROM ' . self::TABLE . " l WHERE l.join_token = :token AND l.kind = 'own' AND l.closed_at IS NULL", ['token' => $token]);

        return $row === false ? null : self::row($row);
    }

    /** Makes a list of an administrator's own, with a join link of its own. */
    public function createOwn(string $name, string $description, bool $shown, string $actor = ''): int
    {
        return (int) $this->connection->fetchOne(
            'INSERT INTO ' . self::TABLE . " (kind, name, description, shown, join_token, created_by) VALUES ('own', :name, :description, :shown, :token, :actor) RETURNING id",
            ['name' => $name, 'description' => $description, 'shown' => $shown ? 'true' : 'false', 'token' => bin2hex(random_bytes(16)), 'actor' => $actor !== '' ? $actor : null]
        );
    }

    /**
     * Changes what is given of a list: name, description, shown, from_name, reply_to, closed_at (a moment or null),
     * join_token.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::CHANGEABLE, true)) continue;
            $sets[] = $column . ' = :' . $column;
            $params[$column] = is_bool($value) ? ($value ? 'true' : 'false') : ($value instanceof DateTimeImmutable ? $value->format(DATE_ATOM) : $value);
        }
        if ($sets === []) return;
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /** A new join link: the old one stops working. */
    public function newJoinToken(int $id): string
    {
        $token = bin2hex(random_bytes(16));
        $this->update($id, ['join_token' => $token]);

        return $token;
    }

    /** Forgets who made the lists a person made (their account is gone). */
    public function forget(string $account): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET created_by = NULL WHERE created_by = :account', ['account' => $account]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function row(array $row): array
    {
        return ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'subject' => $row['subject'] === null ? null : (string) $row['subject'], 'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''), 'shown' => in_array($row['shown'], [true, 't', 'true', 1, '1'], true), 'join_token' => (string) ($row['join_token'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''), 'reply_to' => (string) ($row['reply_to'] ?? ''), 'closed_at' => $row['closed_at'], 'created_at' => (string) $row['created_at']]
            + (array_key_exists('followers', $row) ? ['followers' => (int) $row['followers']] : []);
    }
}
