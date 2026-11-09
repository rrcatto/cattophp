<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

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

    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('happened_at', 'action', 'actor_id', 'actor_name', 'subject_type', 'subject_id', 'details', 'ip_address', 'request_reference')
            ->from('cattophp_activity')
            ->orderBy('happened_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, min(1000, $limit)));
        foreach (['action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'actor_id' => $actorId] as $column => $value) {
            if ($value !== null) {
                $query->andWhere($column . ' = ' . $query->createNamedParameter($value));
            }
        }
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

    public function removeBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_activity WHERE happened_at < ?', [$before->format('Y-m-d H:i:s.uP')]);
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
