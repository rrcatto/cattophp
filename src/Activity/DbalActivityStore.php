<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** The activity log in the database (`cattophp_activity`). */
final class DbalActivityStore implements ActivityStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(ActivityEntry $entry): void
    {
        $this->connection->insert('cattophp_activity', [
            'happened_at' => $entry->at->format('Y-m-d H:i:s.uP'),
            'action' => $entry->action,
            'actor_id' => $entry->actorId,
            'actor_name' => $entry->actorName,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'details' => json_encode($entry->details === [] ? new \stdClass() : $entry->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => $entry->ipAddress,
            'request_reference' => $entry->requestReference,
        ]);
    }

    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null, int $offset = 0): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('happened_at', 'action', 'actor_id', 'actor_name', 'subject_type', 'subject_id', 'details', 'ip_address', 'request_reference')
            ->from('cattophp_activity')
            ->orderBy('happened_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->setFirstResult(max(0, $offset));
        self::narrow($query, $action, $subjectType, $subjectId, $actorId);
        $entries = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $details = json_decode((string) $row['details'], true);
            $entries[] = new ActivityEntry(
                new DateTimeImmutable((string) $row['happened_at']),
                (string) $row['action'],
                self::text($row['actor_id']),
                self::text($row['actor_name']),
                self::text($row['subject_type']),
                self::text($row['subject_id']),
                is_array($details) ? self::keyed($details) : [],
                self::text($row['ip_address']),
                self::text($row['request_reference']),
            );
        }

        return $entries;
    }

    public function count(?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): int
    {
        $query = $this->connection->createQueryBuilder()->select('COUNT(*)')->from('cattophp_activity');
        self::narrow($query, $action, $subjectType, $subjectId, $actorId);

        return (int) $query->executeQuery()->fetchOne();
    }

    public function actions(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn('SELECT DISTINCT action FROM cattophp_activity ORDER BY action'));
    }

    public function removeBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_activity WHERE happened_at < ?', [$before->format('Y-m-d H:i:s.uP')]);
    }

    /**
     * Every entry naming the account (ActivityEntry::forgetting()) changed so it no longer does, its network address gone
     * too. The database finds the entries that may (by it, about it, a value in what it says that is its id, or one of the
     * words anywhere in what it says) and each is then changed as ActivityEntry::forgetting() says, a few hundred at a time.
     */
    public function forgetAccount(string $accountId, string $name, array $words = []): int
    {
        $words = array_values(array_filter(array_map(static fn (string $word): string => trim($word), $words), static fn (string $word): bool => $word !== ''));
        $likes = array_map(static fn (string $word): string => '%' . addcslashes($word, '%_\\') . '%', $words);
        $numeric = preg_match('/^-?\d{1,15}$/', $accountId) === 1;
        $where = "(actor_id = :account OR (subject_type = 'account' AND subject_id = :account)
            OR jsonb_path_exists(details, " . ($numeric ? "'$.** ? (@ == \$id || @ == \$number)'" : "'$.** ? (@ == \$id)'") . ', CAST(:path AS jsonb))'
            . ($likes === [] ? '' : ' OR CAST(details AS text) ILIKE ANY(CAST(:likes AS text[]))') . ')';
        $parameters = [
            'account' => $accountId,
            'path' => json_encode($numeric ? ['id' => $accountId, 'number' => (int) $accountId] : ['id' => $accountId], JSON_THROW_ON_ERROR),
            'likes' => '{' . implode(',', array_map(static fn (string $like): string => '"' . addcslashes($like, '"\\') . '"', $likes)) . '}',
        ];
        $changed = 0;
        $after = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, happened_at, action, actor_id, actor_name, subject_type, subject_id, details, ip_address, request_reference FROM cattophp_activity
                 WHERE ' . $where . ' AND id > :after ORDER BY id LIMIT 500',
                $parameters + ['after' => $after],
            );
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $details = json_decode((string) $row['details'], true);
                $entry = new ActivityEntry(new DateTimeImmutable((string) $row['happened_at']), (string) $row['action'], self::text($row['actor_id']), self::text($row['actor_name']),
                    self::text($row['subject_type']), self::text($row['subject_id']), is_array($details) ? self::keyed($details) : [], self::text($row['ip_address']),
                    self::text($row['request_reference']));
                $forgotten = ActivityEntry::forgetting($entry, $accountId, $name, $words);
                if ($forgotten === $entry) {
                    continue;
                }
                $this->connection->executeStatement(
                    'UPDATE cattophp_activity SET actor_id = :actor, actor_name = :actor_name, subject_id = :subject, details = CAST(:details AS jsonb), ip_address = NULL WHERE id = :id',
                    ['id' => $after, 'actor' => $forgotten->actorId, 'actor_name' => $forgotten->actorName, 'subject' => $forgotten->subjectId,
                        'details' => is_array($details) ? json_encode($forgotten->details === [] ? new \stdClass() : $forgotten->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $row['details']],
                );
                ++$changed;
            }
        } while (count($rows) === 500);

        return $changed;
    }

    private static function narrow(QueryBuilder $query, ?string $action, ?string $subjectType, ?string $subjectId, ?string $actorId): void
    {
        foreach (['action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'actor_id' => $actorId] as $column => $value) {
            if ($value !== null) {
                $query->andWhere($column . ' = ' . $query->createNamedParameter($value));
            }
        }
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<mixed> $details
     * @return array<string,mixed>
     */
    private static function keyed(array $details): array
    {
        $keyed = [];
        foreach ($details as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
