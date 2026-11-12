<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use Doctrine\DBAL\Connection;

/**
 * The figures of a project's mailing, for its overview and reports, read from CattoPHP's records: for a period (from one
 * moment up to another), the copies handed on and what became of them, by kind and by campaign, the forwards, the people
 * who joined or left a list, the most clicked links and the copies sent in each hour, day, week or month; and for the
 * overview, who is subscribed and who joined lately, the lists with someone on them by kind, the campaigns sent lately,
 * the bounces and complaints since a moment, the lists most joined and the campaigns scheduled. Which kinds of campaign
 * count as messages, and what a list is called, are the project's to say.
 */
final class MailingFigures
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{sent:int,opened:int,clicked:int,bounced:int,complained:int,unsubscribed:int} the copies handed on in a period and what became of them */
    public function totals(string $from, string $to): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS sent, COUNT(*) FILTER (WHERE d.first_opened_at IS NOT NULL) AS opened, COUNT(*) FILTER (WHERE d.click_count > 0) AS clicked,
                    COUNT(*) FILTER (WHERE d.status IN ('bounced', 'soft_bounced')) AS bounced, COUNT(*) FILTER (WHERE d.status = 'complained') AS complained,
                    COUNT(*) FILTER (WHERE d.unsubscribed_at IS NOT NULL) AS unsubscribed
               FROM " . Copies::TABLE . ' d WHERE d.handed_at >= :from AND d.handed_at < :to',
            ['from' => $from, 'to' => $to]
        );
        $row = $row === false ? [] : $row;

        return ['sent' => (int) ($row['sent'] ?? 0), 'opened' => (int) ($row['opened'] ?? 0), 'clicked' => (int) ($row['clicked'] ?? 0), 'bounced' => (int) ($row['bounced'] ?? 0),
            'complained' => (int) ($row['complained'] ?? 0), 'unsubscribed' => (int) ($row['unsubscribed'] ?? 0)];
    }

    /** How many copies were forwarded to a friend in a period. */
    public function forwarded(string $from, string $to): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . Forwards::TABLE . ' WHERE created_at >= :from AND created_at < :to', ['from' => $from, 'to' => $to]);
    }

    /** How many people joined (subscribed) or left (unsubscribed) a list in a period. */
    public function people(string $status, string $from, string $to): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT account) FROM ' . Subscriptions::EVENTS . ' WHERE status = :status AND created_at >= :from AND created_at < :to',
            ['status' => $status, 'from' => $from, 'to' => $to]);
    }

    /** @return list<array{kind:string,sent:int,opened:int,clicked:int}> the copies handed on in a period by the kind of their campaign, the most first */
    public function byKind(string $from, string $to): array
    {
        return array_map(static fn(array $row): array => ['kind' => (string) $row['kind'], 'sent' => (int) $row['sent'], 'opened' => (int) $row['opened'], 'clicked' => (int) $row['clicked']],
            $this->connection->fetchAllAssociative(
                'SELECT c.kind, COUNT(*) AS sent, COUNT(*) FILTER (WHERE d.first_opened_at IS NOT NULL) AS opened, COUNT(*) FILTER (WHERE d.click_count > 0) AS clicked
                   FROM ' . Copies::TABLE . ' d JOIN ' . Campaigns::TABLE . ' c ON c.id = d.campaign_id WHERE d.handed_at >= :from AND d.handed_at < :to GROUP BY c.kind ORDER BY sent DESC',
                ['from' => $from, 'to' => $to]
            ));
    }

    /**
     * The campaigns of these kinds started in a period, the newest first, with what became of their copies.
     *
     * @param list<string> $kinds
     * @return list<array{id:int,name:string,kind:string,started_at:string,sent:int,opened:int,clicked:int,unsubscribed:int}>
     */
    public function campaigns(string $from, string $to, array $kinds, int $limit = 50): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'kind' => (string) $row['kind'], 'started_at' => (string) $row['started_at'],
            'sent' => (int) $row['sent'], 'opened' => (int) $row['opened'], 'clicked' => (int) $row['clicked'], 'unsubscribed' => (int) $row['unsubscribed']], $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name, c.kind, c.started_at, COUNT(d.id) FILTER (WHERE d.handed_at IS NOT NULL) AS sent, COUNT(d.id) FILTER (WHERE d.first_opened_at IS NOT NULL) AS opened,
                    COUNT(d.id) FILTER (WHERE d.click_count > 0) AS clicked, COUNT(d.id) FILTER (WHERE d.unsubscribed_at IS NOT NULL) AS unsubscribed
               FROM ' . Campaigns::TABLE . ' c LEFT JOIN ' . Copies::TABLE . ' d ON d.campaign_id = c.id
              WHERE c.started_at >= :from AND c.started_at < :to AND c.kind = ANY(CAST(:kinds AS TEXT[])) GROUP BY c.id ORDER BY c.started_at DESC LIMIT :limit',
            ['from' => $from, 'to' => $to, 'kinds' => self::textArray($kinds), 'limit' => $limit]
        ));
    }

    /** @return list<array{url:string,campaign_id:int,campaign:string,people:int,clicks:int}> the links most clicked for the first time in a period, by the people who clicked */
    public function links(string $from, string $to, int $limit = 10): array
    {
        return array_map(static fn(array $row): array => ['url' => (string) $row['url'], 'campaign_id' => (int) $row['campaign_id'], 'campaign' => (string) $row['campaign'], 'people' => (int) $row['people'], 'clicks' => (int) $row['clicks']],
            $this->connection->fetchAllAssociative(
                'SELECT k.url, c.id AS campaign_id, c.name AS campaign, COUNT(*) AS people, SUM(x.clicks) AS clicks FROM ' . Links::CLICKS . ' x JOIN ' . Links::TABLE . ' k ON k.id = x.link_id
                   JOIN ' . Campaigns::TABLE . ' c ON c.id = k.campaign_id WHERE x.first_at >= :from AND x.first_at < :to GROUP BY k.url, c.id, c.name ORDER BY people DESC, clicks DESC LIMIT :limit',
                ['from' => $from, 'to' => $to, 'limit' => $limit]
            ));
    }

    /** When the first copy was handed on, or null when none was yet. */
    public function firstHanded(): ?string
    {
        $first = $this->connection->fetchOne('SELECT MIN(handed_at) FROM ' . Copies::TABLE);

        return is_string($first) ? $first : null;
    }

    /**
     * Copies handed on in each slice (hour, day, week or month) of a period, in a time zone.
     *
     * @return list<array{slice:string,sent:int}>
     */
    public function slices(string $unit, string $zone, string $from, string $to): array
    {
        if (!in_array($unit, ['hour', 'day', 'week', 'month'], true)) throw new \InvalidArgumentException('A slice is an hour, a day, a week or a month.');

        return array_map(static fn(array $row): array => ['slice' => (string) $row['slice'], 'sent' => (int) $row['sent']], $this->connection->fetchAllAssociative(
            'SELECT date_trunc(:unit, d.handed_at AT TIME ZONE :zone) AS slice, COUNT(*) AS sent FROM ' . Copies::TABLE . ' d WHERE d.handed_at >= :from AND d.handed_at < :to GROUP BY 1',
            ['unit' => $unit, 'zone' => $zone, 'from' => $from, 'to' => $to]
        ));
    }

    /** @return array{total:int,joined:int} the people on at least one list, and how many of them joined their first since a moment */
    public function subscribers(string $since): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(DISTINCT s.account) AS total, COUNT(DISTINCT s.account) FILTER (WHERE first.joined >= :since) AS joined
               FROM ' . Subscriptions::TABLE . " s
               JOIN (SELECT account, MIN(created_at) AS joined FROM " . Subscriptions::EVENTS . " WHERE status = 'subscribed' GROUP BY account) first ON first.account = s.account
              WHERE s.status = 'subscribed'",
            ['since' => $since]
        );

        return ['total' => (int) (($row === false ? [] : $row)['total'] ?? 0), 'joined' => (int) (($row === false ? [] : $row)['joined'] ?? 0)];
    }

    /** @return array<string,int> the lists with someone on them, by kind */
    public function listsByKind(): array
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT l.kind, COUNT(*) AS lists FROM ' . MailingLists::TABLE . ' l WHERE EXISTS (SELECT 1 FROM ' . Subscriptions::TABLE . " s WHERE s.list_id = l.id AND s.status = 'subscribed') GROUP BY l.kind"
        ) as $row) $counts[(string) $row['kind']] = (int) $row['lists'];

        return $counts;
    }

    /**
     * The campaigns of these kinds sent most recently, the newest first, with what became of their copies.
     *
     * @param list<string> $kinds
     * @return list<array{id:int,kind:string,name:string,list_ids:list<int>,started_at:string,sent:int,opened:int,clicked:int}>
     */
    public function recentlySent(array $kinds, int $limit): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'name' => (string) $row['name'],
            'list_ids' => array_values(array_map('intval', (array) json_decode((string) $row['list_ids'], true))), 'started_at' => (string) $row['started_at'],
            'sent' => (int) $row['sent'], 'opened' => (int) $row['opened'], 'clicked' => (int) $row['clicked']], $this->connection->fetchAllAssociative(
            'SELECT c.id, c.kind, c.name, c.list_ids, c.started_at,
                    (SELECT COUNT(*) FROM ' . Copies::TABLE . ' d WHERE d.campaign_id = c.id AND d.handed_at IS NOT NULL) AS sent,
                    (SELECT COUNT(*) FROM ' . Copies::TABLE . ' d WHERE d.campaign_id = c.id AND d.first_opened_at IS NOT NULL) AS opened,
                    (SELECT COUNT(*) FROM ' . Copies::TABLE . ' d WHERE d.campaign_id = c.id AND d.first_clicked_at IS NOT NULL) AS clicked
               FROM ' . Campaigns::TABLE . ' c WHERE c.started_at IS NOT NULL AND c.kind = ANY(CAST(:kinds AS TEXT[])) ORDER BY c.started_at DESC, c.id DESC LIMIT :limit',
            ['kinds' => self::textArray($kinds), 'limit' => $limit]
        ));
    }

    /** @return array{sent:int,bounced:int,complained:int} the copies handed on since a moment, and how many bounced (hard or soft) or were marked as spam */
    public function bouncesSince(string $since): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS sent, COUNT(*) FILTER (WHERE status IN ('bounced', 'soft_bounced')) AS bounced, COUNT(*) FILTER (WHERE status = 'complained') AS complained
               FROM " . Copies::TABLE . ' WHERE handed_at >= :since',
            ['since' => $since]
        );
        $row = $row === false ? [] : $row;

        return ['sent' => (int) ($row['sent'] ?? 0), 'bounced' => (int) ($row['bounced'] ?? 0), 'complained' => (int) ($row['complained'] ?? 0)];
    }

    /** @return list<array{list_id:int,joined:int}> the lists most joined since a moment by people still on them */
    public function growing(string $since, int $limit): array
    {
        return array_map(static fn(array $row): array => ['list_id' => (int) $row['list_id'], 'joined' => (int) $row['joined']], $this->connection->fetchAllAssociative(
            'SELECT e.list_id, COUNT(DISTINCT e.account) AS joined FROM ' . Subscriptions::EVENTS . " e
              WHERE e.status = 'subscribed' AND e.created_at >= :since
                AND EXISTS (SELECT 1 FROM " . Subscriptions::TABLE . " s WHERE s.list_id = e.list_id AND s.account = e.account AND s.status = 'subscribed')
              GROUP BY e.list_id ORDER BY joined DESC, e.list_id LIMIT :limit",
            ['since' => $since, 'limit' => $limit]
        ));
    }

    /**
     * The campaigns of a kind scheduled, the soonest first.
     *
     * @return list<array{id:int,name:string,send_at:string,list_ids:list<int>}>
     */
    public function scheduled(string $kind): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'send_at' => (string) $row['send_at'],
            'list_ids' => array_values(array_map('intval', (array) json_decode((string) $row['list_ids'], true)))], $this->connection->fetchAllAssociative(
            'SELECT id, name, send_at, list_ids FROM ' . Campaigns::TABLE . " WHERE status = 'scheduled' AND kind = :kind ORDER BY COALESCE(send_at, created_at), id",
            ['kind' => $kind]
        ));
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn(string $value): string => '"' . addcslashes($value, '"\\') . '"', $values)) . '}';
    }
}
