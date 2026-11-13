<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use Doctrine\DBAL\Connection;

/**
 * Each person's answer for each list (cattophp_mailing_subscriptions: subscribed, unsubscribed, or pending while they
 * confirm by a link) with where they gave it, and every change in its history (cattophp_mailing_subscription_events), so
 * consent can always be shown. A person is their account id in text.
 */
final class Subscriptions
{
    public const TABLE = 'cattophp_mailing_subscriptions';
    public const EVENTS = 'cattophp_mailing_subscription_events';
    public const STATUSES = ['subscribed', 'unsubscribed', 'pending'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** A person's answer for a list changes, and the change goes into the history; false when it was the answer already. */
    public function answer(int $listId, string $account, string $status, string $source): bool
    {
        if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('An answer is subscribed, unsubscribed or pending.');
        $current = $this->connection->fetchOne('SELECT status FROM ' . self::TABLE . ' WHERE list_id = :list AND account = :account FOR UPDATE', ['list' => $listId, 'account' => $account]);
        if ($current === $status) return false;
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (list_id, account, status, source, changed_at) VALUES (:list, :account, :status, :source, NOW())
             ON CONFLICT (list_id, account) DO UPDATE SET status = EXCLUDED.status, source = EXCLUDED.source, changed_at = NOW()',
            ['list' => $listId, 'account' => $account, 'status' => $status, 'source' => $source]
        );
        $this->connection->executeStatement('INSERT INTO ' . self::EVENTS . ' (list_id, account, status, source) VALUES (:list, :account, :status, :source)',
            ['list' => $listId, 'account' => $account, 'status' => $status, 'source' => $source]);

        return true;
    }

    /** Whether a person is on the list of a kind and subject. */
    public function isSubscribed(string $account, string $kind, int|string|null $subject): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM ' . self::TABLE . ' s JOIN ' . MailingLists::TABLE . " l ON l.id = s.list_id
              WHERE s.account = :account AND s.status = 'subscribed' AND l.kind = :kind AND COALESCE(l.subject, '') = :subject",
            ['account' => $account, 'kind' => $kind, 'subject' => $subject === null ? '' : (string) $subject]
        ) !== false;
    }

    /** @return list<int> the lists a person is on now */
    public function subscribedListIds(string $account): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT list_id FROM ' . self::TABLE . " WHERE account = :account AND status = 'subscribed' ORDER BY list_id", ['account' => $account]));
    }

    public function followerCount(int $listId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE list_id = :list AND status = 'subscribed'", ['list' => $listId]);
    }

    /**
     * Of these subjects of a kind, the ones a person follows.
     *
     * @param list<int|string> $subjects
     * @return list<string>
     */
    public function followedAmong(string $account, string $kind, array $subjects): array
    {
        if ($subjects === []) return [];

        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT l.subject FROM ' . self::TABLE . ' s JOIN ' . MailingLists::TABLE . " l ON l.id = s.list_id
              WHERE s.account = :account AND s.status = 'subscribed' AND l.kind = :kind AND l.subject = ANY(CAST(:subjects AS TEXT[]))",
            ['account' => $account, 'kind' => $kind, 'subjects' => self::textArray(array_map('strval', $subjects))]
        ));
    }

    /**
     * A person's history on a list, or on every list, newest first.
     *
     * @return list<array{list_id:int,status:string,source:string,created_at:string}>
     */
    public function history(string $account, ?int $listId = null): array
    {
        return array_map(static fn(array $row): array => ['list_id' => (int) $row['list_id'], 'status' => (string) $row['status'], 'source' => (string) $row['source'], 'created_at' => (string) $row['created_at']],
            $this->connection->fetchAllAssociative('SELECT list_id, status, source, created_at FROM ' . self::EVENTS . ' WHERE account = :account AND (CAST(:list AS BIGINT) IS NULL OR list_id = :list) ORDER BY created_at DESC, id DESC',
                ['account' => $account, 'list' => $listId]));
    }

    /** Forgets a person whose account is gone: their answers and their history. Returns how many answers went. */
    public function forget(string $account): int
    {
        $this->connection->executeStatement('DELETE FROM ' . self::EVENTS . ' WHERE account = :account', ['account' => $account]);

        return (int) $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE account = :account', ['account' => $account]);
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn(string $value): string => '"' . addcslashes($value, '"\\') . '"', $values)) . '}';
    }
}
