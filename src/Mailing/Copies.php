<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Each person's copy of a campaign (cattophp_mailing_copies): to whom (their account, in text, and the address it went
 * to), the token its links carry, the list it came from and why they get it (the reasons, in the project's words), what
 * it holds of its own (content: a digest's items, an answer), and what became of it: queued, handed on (with the
 * transport, its job and message ids), then delivered, deferred, bounced, complained, failed, suppressed or skipped;
 * its opens and clicks and whether it unsubscribed someone. A person has at most one copy of a campaign.
 */
final class Copies
{
    public const TABLE = 'cattophp_mailing_copies';
    /** The statuses a copy ends in; a copy in any other is still on its way. */
    public const RESOLVED = ['delivered', 'soft_bounced', 'bounced', 'complained', 'failed', 'suppressed', 'skipped'];
    private const CHANGEABLE = ['status', 'status_detail', 'attempts', 'transport', 'transport_job_id', 'transport_message_id', 'handed_at', 'checked_at', 'resolved_at', 'unsubscribed_at'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * A copy for each person, unless they have one in this campaign already. Returns how many were added.
     *
     * @param list<array{account:string,email:string,list_id?:?int,reasons?:list<string>,content?:array<string,mixed>}> $people
     */
    public function add(int $campaignId, array $people): int
    {
        $added = 0;
        foreach ($people as $person) {
            $added += (int) $this->connection->executeStatement(
                'INSERT INTO ' . self::TABLE . ' (campaign_id, account, email, token, list_id, reasons, content, created_at)
                 VALUES (:campaign, :account, :email, :token, :list, CAST(:reasons AS JSONB), CAST(:content AS JSONB), NOW()) ON CONFLICT (campaign_id, account) DO NOTHING',
                ['campaign' => $campaignId, 'account' => $person['account'], 'email' => $person['email'], 'token' => bin2hex(random_bytes(16)),
                    'list' => ($person['list_id'] ?? 0) > 0 ? $person['list_id'] : null, 'reasons' => json_encode($person['reasons'] ?? [], JSON_THROW_ON_ERROR),
                    'content' => json_encode(($person['content'] ?? []) === [] ? new \stdClass() : $person['content'], JSON_THROW_ON_ERROR)]
            );
        }

        return $added;
    }

    /**
     * The next copies of a campaign to send, oldest first, locked so that no other run takes them.
     *
     * @return list<array<string,mixed>>
     */
    public function nextQueued(int $campaignId, int $limit): array
    {
        if ($limit < 1) return [];

        return array_map(self::decode(...), $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . " WHERE campaign_id = :campaign AND status = 'queued' ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED",
            ['campaign' => $campaignId, 'limit' => $limit]
        ));
    }

    public function queuedCount(int $campaignId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE campaign_id = :campaign AND status = 'queued'", ['campaign' => $campaignId]);
    }

    /** @param array<string,mixed> $fields any of status, status_detail, attempts, transport, transport_job_id, transport_message_id, handed_at, checked_at, resolved_at, unsubscribed_at */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::CHANGEABLE, true)) continue;
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $column === 'status_detail' && is_string($value) ? mb_substr($value, 0, 500) : $value;
        }
        if ($sets !== []) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /** @return array<string,mixed>|null a copy by the token its links carry, with its campaign's kind and name */
    public function byToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) return null;
        $row = $this->connection->fetchAssociative('SELECT d.*, c.kind AS campaign_kind, c.name AS campaign_name FROM ' . self::TABLE . ' d JOIN ' . Campaigns::TABLE . ' c ON c.id = d.campaign_id WHERE d.token = :token', ['token' => $token]);

        return $row === false ? null : self::decode($row);
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : self::decode($row);
    }

    /** @return array<string,mixed>|null a copy by the id its transport gave its message */
    public function byTransportMessage(string $transport, string $messageId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE transport = :transport AND transport_message_id = :message', ['transport' => $transport, 'message' => $messageId]);

        return $row === false ? null : self::decode($row);
    }

    /** @return array<string,int> person (their account) => their copy, in one campaign */
    public function ids(int $campaignId): array
    {
        $ids = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, account FROM ' . self::TABLE . ' WHERE campaign_id = :campaign AND account IS NOT NULL ORDER BY id', ['campaign' => $campaignId]) as $row) $ids[(string) $row['account']] = (int) $row['id'];

        return $ids;
    }

    /** @return array<string,mixed> what a campaign's first copy holds of its own, for its preview */
    public function firstContent(int $campaignId): array
    {
        $content = $this->connection->fetchOne('SELECT content FROM ' . self::TABLE . ' WHERE campaign_id = :campaign ORDER BY id LIMIT 1', ['campaign' => $campaignId]);
        $decoded = is_string($content) ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function count(int $campaignId, string $status = ''): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE campaign_id = :campaign AND (:status = '' OR status = :status)", ['campaign' => $campaignId, 'status' => $status]);
    }

    /** Marks every copy in a transport's job as looked at now, so the next look waits its turn. */
    public function jobChecked(string $jobId): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET checked_at = NOW() WHERE transport_job_id = :job', ['job' => $jobId]);
    }

    /** The transport says a job finished: its copies are read back on the next run. */
    public function jobNeedsReading(string $jobId): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . " SET checked_at = TIMESTAMPTZ '2000-01-01 00:00:00+00' WHERE transport_job_id = :job", ['job' => $jobId]);
    }

    /** @return list<array{transport_job_id:string,handed_at:string}> jobs of a transport whose copies may still change, the longest unread first */
    public function openJobs(string $transport, string $checkedBefore, int $limit): array
    {
        return array_map(static fn(array $row): array => ['transport_job_id' => (string) $row['transport_job_id'], 'handed_at' => (string) $row['handed_at']], $this->connection->fetchAllAssociative(
            'SELECT transport_job_id, MIN(handed_at) AS handed_at FROM ' . self::TABLE . "
              WHERE transport = :transport AND transport_job_id IS NOT NULL AND status IN ('handed', 'deferred') AND COALESCE(checked_at, handed_at) < :before
              GROUP BY transport_job_id ORDER BY MIN(COALESCE(checked_at, handed_at)) LIMIT :limit",
            ['transport' => $transport, 'before' => $checkedBefore, 'limit' => $limit]
        ));
    }

    /** How many copies were handed on since a moment, across every campaign. */
    public function handedSince(string $since): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE handed_at >= :since', ['since' => $since]);
    }

    /** When news was first handed on from this site: the start of its gentle first days. */
    public function firstHandedAt(): ?string
    {
        $at = $this->connection->fetchOne('SELECT MIN(handed_at) FROM ' . self::TABLE);

        return is_string($at) && $at !== '' ? $at : null;
    }

    public function recordOpen(int $id): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET open_count = open_count + 1, first_opened_at = COALESCE(first_opened_at, NOW()), last_opened_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $content what the copy holds of its own, in full */
    public function setContent(int $id, array $content): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET content = CAST(:content AS JSONB) WHERE id = :id', ['id' => $id, 'content' => json_encode($content === [] ? new \stdClass() : $content, JSON_THROW_ON_ERROR)]);
    }

    /** The daily clean-up: copies made before a moment go, their clicks with them; campaigns and addresses never emailed stay. */
    public function deleteBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE created_at < :before', ['before' => $before->format('Y-m-d H:i:sP')]);
    }

    /**
     * The jobs handed to catto-mail, newest first: one per campaign and list, with what became of their copies.
     *
     * @return list<array<string,mixed>>
     */
    public function cattomailJobs(int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT d.transport_job_id AS job_id, c.id AS campaign_id, c.name AS campaign, c.kind, MIN(d.handed_at) AS handed_at, MAX(d.checked_at) AS checked_at, COUNT(*) AS copies,
                    COUNT(*) FILTER (WHERE d.status = 'handed') AS on_the_way, COUNT(*) FILTER (WHERE d.status = 'delivered') AS delivered,
                    COUNT(*) FILTER (WHERE d.status IN ('bounced', 'soft_bounced', 'complained', 'failed', 'suppressed')) AS problems
               FROM " . self::TABLE . ' d JOIN ' . Campaigns::TABLE . " c ON c.id = d.campaign_id
              WHERE d.transport = 'cattomail' AND d.transport_job_id IS NOT NULL GROUP BY d.transport_job_id, c.id, c.name, c.kind ORDER BY MIN(d.handed_at) DESC LIMIT :limit",
            ['limit' => $limit]
        );
    }

    /** A person whose account is gone: their copies stay, for the figures, without them. */
    public function forget(string $account): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET account = NULL WHERE account = :account', ['account' => $account]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function decode(array $row): array
    {
        foreach (['reasons', 'content'] as $json) if (isset($row[$json]) && is_string($row[$json])) $row[$json] = json_decode($row[$json], true) ?? [];
        foreach (['id', 'campaign_id', 'list_id', 'attempts', 'open_count', 'click_count'] as $int) if (isset($row[$int])) $row[$int] = (int) $row[$int];

        return $row;
    }
}
