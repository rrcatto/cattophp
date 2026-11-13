<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use Doctrine\DBAL\Connection;

/**
 * The addresses never emailed news (cattophp_mailing_suppressions): an address that bounced for good, one whose person
 * marked an email as spam, and one an administrator or the person stopped. Every sending leaves them out; an
 * administrator may take one off again. Each keeps why, the copy that caused it when there was one (the project's id for
 * it) and who added it.
 */
final class Suppressions
{
    public const TABLE = 'cattophp_mailing_suppressions';
    public const REASONS = ['hard_bounce' => 'Bounced for good', 'complaint' => 'Marked an email as spam', 'manual' => 'Stopped by hand'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function isSuppressed(string $email): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE email = :email', ['email' => self::address($email)]) !== false;
    }

    /** Never emails an address news again. False when it was never emailed already. */
    public function suppress(string $email, string $reason, string $detail = '', int|string|null $copyId = null, string $actor = ''): bool
    {
        if (!isset(self::REASONS[$reason])) throw new \InvalidArgumentException('An address is never emailed for a bounce, a complaint or by hand.');

        return $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (email, reason, detail, copy_id, created_by, created_at) VALUES (:email, :reason, :detail, :copy, :actor, NOW()) ON CONFLICT (email) DO NOTHING',
            ['email' => self::address($email), 'reason' => $reason, 'detail' => $detail !== '' ? mb_substr($detail, 0, 500) : null, 'copy' => $copyId === null ? null : (string) $copyId, 'actor' => $actor !== '' && $actor !== '0' ? $actor : null]
        ) > 0;
    }

    /** Takes an address off: it may be emailed news again. False when it was not on. */
    public function lift(string $email): bool
    {
        return $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE email = :email', ['email' => self::address($email)]) > 0;
    }

    /** How many there are, or how many match a part of an address. */
    public function count(string $search = ''): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE (:search = '' OR email ILIKE :like)", ['search' => $search, 'like' => '%' . addcslashes($search, '%_\\') . '%']);
    }

    /** Forgets who added the addresses a person added (their account is gone). */
    public function forget(string $account): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET created_by = NULL WHERE created_by = :account', ['account' => $account]);
    }

    /** Forgets the copy that caused it, when the project deletes that copy. */
    public function forgetCopy(int|string $copyId): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET copy_id = NULL WHERE copy_id = :copy', ['copy' => (string) $copyId]);
    }

    private static function address(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
