<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use Doctrine\DBAL\Connection;

/**
 * The campaigns (cattophp_mailing_campaigns): what a project sends to its lists. A campaign has a kind (the project's word,
 * such as message or digest), a name for the administration, the subject and the preview words, what it says in the
 * project's own form (content), the lists it goes to and those whose people it leaves out, the most it sends to, its own
 * sender name, reply-to and plain-text copy when it has them, and its status: draft, awaiting_approval, scheduled (at
 * send_at, or at once), sending, paused (with why), sent or cancelled. Each campaign's figures count its copies.
 */
final class Campaigns
{
    public const TABLE = 'cattophp_mailing_campaigns';
    public const STATUSES = ['draft', 'awaiting_approval', 'scheduled', 'sending', 'paused', 'sent', 'cancelled'];
    private const CHANGEABLE = ['name', 'subject', 'preheader', 'content', 'list_ids', 'status', 'status_note', 'send_at', 'approved_by', 'approved_at', 'started_at', 'finished_at',
        'checks_overridden', 'from_name', 'reply_to', 'exclude_list_ids', 'max_recipients', 'plain_text'];
    private const JSON = ['content', 'list_ids', 'exclude_list_ids'];

    /** Each campaign's figures, counted from its copies. */
    public const FIGURES = "(SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id) AS total,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.status = 'queued') AS queued,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.handed_at IS NOT NULL) AS sent,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.status = 'delivered') AS delivered,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.status IN ('bounced', 'soft_bounced')) AS bounced,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.status = 'bounced') AS hard_bounced,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.status = 'complained') AS complained,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.status IN ('failed', 'suppressed', 'skipped')) AS not_sent,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.first_opened_at IS NOT NULL) AS opened,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.first_clicked_at IS NOT NULL) AS clicked,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.unsubscribed_at IS NOT NULL) AS unsubscribed,
        (SELECT COUNT(*) FROM cattophp_mailing_copies d WHERE d.campaign_id = c.id AND d.transport = 'cattomail') AS via_cattomail";
    private const COUNTS = ['total', 'queued', 'sent', 'delivered', 'bounced', 'hard_bounced', 'complained', 'not_sent', 'opened', 'clicked', 'unsubscribed', 'via_cattomail'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * A new campaign: a draft unless a status is given.
     *
     * @param array<string,mixed> $fields subject, preheader, content, list_ids, status
     */
    public function create(string $kind, string $name, string $actor = '', array $fields = []): int
    {
        if (isset($fields['status']) && !in_array($fields['status'], self::STATUSES, true)) throw new \InvalidArgumentException('Unknown status for a campaign.');

        return (int) $this->connection->fetchOne(
            'INSERT INTO ' . self::TABLE . ' (kind, name, subject, preheader, content, list_ids, status, created_by, created_at, updated_at)
             VALUES (:kind, :name, :subject, :preheader, CAST(:content AS JSONB), CAST(:lists AS JSONB), :status, :actor, NOW(), NOW()) RETURNING id',
            ['kind' => $kind, 'name' => $name, 'subject' => self::text($fields['subject'] ?? ''), 'preheader' => self::text($fields['preheader'] ?? ''),
                'content' => json_encode($fields['content'] ?? [], JSON_THROW_ON_ERROR), 'lists' => json_encode(array_values(array_map('intval', (array) ($fields['list_ids'] ?? []))), JSON_THROW_ON_ERROR),
                'status' => self::text($fields['status'] ?? 'draft'), 'actor' => $actor !== '' && $actor !== '0' ? $actor : null]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, bool $lock = false): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);

        return $row === false ? null : self::decode($row);
    }

    /** @param array<string,mixed> $fields any of name, subject, preheader, content, list_ids, status, status_note, send_at, approved_by, approved_at, started_at, finished_at, checks_overridden, from_name, reply_to, exclude_list_ids, max_recipients, plain_text */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::CHANGEABLE, true)) continue;
            if (in_array($column, self::JSON, true)) {
                $sets[] = $column . ' = CAST(:' . $column . ' AS JSONB)';
                $params[$column] = json_encode($column === 'content' ? $value : array_values(array_map('intval', (array) $value)), JSON_THROW_ON_ERROR);
            } else {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }
        if ($sets === []) return;
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id', $params);
    }

    /** Deletes a draft or a cancelled campaign that never had a copy; anything else stays. */
    public function delete(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . " c WHERE c.id = :id AND c.status IN ('draft', 'cancelled') AND NOT EXISTS (SELECT 1 FROM " . Copies::TABLE . ' d WHERE d.campaign_id = c.id)', ['id' => $id]);
    }

    /**
     * Campaigns, newest first, with their figures, found by a part of their name or subject.
     *
     * @return list<array<string,mixed>>
     */
    public function page(string $search, int $limit, int $offset): array
    {
        return array_map(self::decode(...), $this->connection->fetchAllAssociative(
            'SELECT c.*, ' . self::FIGURES . ' FROM ' . self::TABLE . " c WHERE (:search = '' OR c.name ILIKE :like OR c.subject ILIKE :like)
             ORDER BY c.created_at DESC, c.id DESC LIMIT :limit OFFSET :offset",
            ['search' => $search, 'like' => '%' . addcslashes($search, '%_\\') . '%', 'limit' => $limit, 'offset' => $offset]
        ));
    }

    public function count(string $search): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " c WHERE (:search = '' OR c.name ILIKE :like OR c.subject ILIKE :like)", ['search' => $search, 'like' => '%' . addcslashes($search, '%_\\') . '%']);
    }

    /** @return array<string,int> a campaign's figures */
    public function figures(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::FIGURES . ' FROM ' . self::TABLE . ' c WHERE c.id = :id', ['id' => $id]);

        return $row === false ? [] : array_map('intval', $row);
    }

    /** @return list<int> campaigns that should start now: scheduled and due, the soonest first */
    public function dueToStart(string $now): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM ' . self::TABLE . " WHERE status = 'scheduled' AND (send_at IS NULL OR send_at <= :now) ORDER BY COALESCE(send_at, created_at), id", ['now' => $now]));
    }

    /** @return list<int> the campaigns sending, the oldest first */
    public function sending(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM ' . self::TABLE . " WHERE status = 'sending' ORDER BY started_at, id"));
    }

    /** @return list<array<string,mixed>> campaigns waiting for a person: an approval, or a pause to look into */
    public function waiting(): array
    {
        return array_map(self::decode(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . " WHERE status IN ('awaiting_approval', 'paused') ORDER BY updated_at, id"));
    }

    /**
     * When a copy of each kind of campaign was last handed on.
     *
     * @param list<string> $kinds
     * @return array<string,?string>
     */
    public function lastHandedByKind(array $kinds): array
    {
        $last = [];
        foreach ($this->connection->fetchAllAssociative('SELECT c.kind, MAX(d.handed_at) AS last FROM ' . Copies::TABLE . ' d JOIN ' . self::TABLE . ' c ON c.id = d.campaign_id
            WHERE c.kind = ANY(CAST(:kinds AS TEXT[])) GROUP BY c.kind', ['kinds' => '{' . implode(',', $kinds) . '}']) as $row) $last[(string) $row['kind']] = $row['last'] === null ? null : (string) $row['last'];

        return $last;
    }

    /** Forgets who made or approved the campaigns a person made or approved (their account is gone). */
    public function forget(string $account): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET created_by = NULL WHERE created_by = :account', ['account' => $account]);
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET approved_by = NULL WHERE approved_by = :account', ['account' => $account]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decode(array $row): array
    {
        foreach (self::JSON as $json) if (isset($row[$json]) && is_string($row[$json])) $row[$json] = json_decode($row[$json], true) ?? [];
        foreach (['list_ids', 'exclude_list_ids'] as $ids) if (isset($row[$ids])) $row[$ids] = array_values(array_map('intval', (array) $row[$ids]));
        foreach (['id', ...self::COUNTS] as $int) if (isset($row[$int])) $row[$int] = (int) $row[$int];
        if (array_key_exists('checks_overridden', $row)) $row['checks_overridden'] = in_array($row['checks_overridden'], [true, 't', 'true', 1, '1'], true);
        if (array_key_exists('max_recipients', $row) && $row['max_recipients'] !== null) $row['max_recipients'] = (int) $row['max_recipients'];

        return $row;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
