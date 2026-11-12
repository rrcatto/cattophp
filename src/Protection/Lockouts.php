<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * What is kept off the site (cattophp_site_lockouts): an address or range, or a network owner by its number; a time-out
 * or a lockout; automatic or by hand; until a time or for good. A lockout counts every request it turned away, and how
 * many it refused today, so the Site Log keeps only the first of them.
 */
class Lockouts
{
    /** A lockout that keeps someone out now. */
    public const IN_FORCE = '(l.lifted_at IS NULL AND (l.until_at IS NULL OR l.until_at > NOW()))';

    /** The kinds of lockout a list offers, and what each holds. */
    public const KINDS = [
        'force' => ['label' => 'In force now', 'where' => self::IN_FORCE],
        'auto' => ['label' => 'In force, automatic', 'where' => self::IN_FORCE . ' AND l.automatic'],
        'you' => ['label' => 'In force, by you', 'where' => self::IN_FORCE . ' AND NOT l.automatic'],
        'ended' => ['label' => 'Ended or lifted', 'where' => 'NOT ' . self::IN_FORCE],
    ];

    private const COLUMNS = 'l.*, abbrev(CAST(l.network AS inet)) AS network_text, ' . self::IN_FORCE . ' AS in_force';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{kind:string,network:?string,asn:?int,label:string,which:string,why:string,rule_key:?string,automatic:bool,created_by:?string,until:?DateTimeImmutable,firewall:string} $lockout
     */
    public function add(array $lockout): int
    {
        return (int) $this->connection->fetchOne(
            'INSERT INTO cattophp_site_lockouts (kind, network, asn, label, which, why, rule_key, automatic, created_by, until_at, firewall)
             VALUES (:kind, CAST(:network AS cidr), :asn, :label, :which, :why, :rule_key, :automatic, :created_by, :until, :firewall) RETURNING id',
            ['kind' => $lockout['kind'], 'network' => $lockout['network'], 'asn' => $lockout['asn'], 'label' => mb_substr($lockout['label'], 0, 200), 'which' => $lockout['which'], 'why' => mb_substr($lockout['why'], 0, 500),
                'rule_key' => $lockout['rule_key'], 'automatic' => $lockout['automatic'], 'created_by' => $lockout['created_by'], 'until' => $lockout['until']?->format(DATE_ATOM), 'firewall' => $lockout['firewall']],
            ['automatic' => 'boolean']
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM cattophp_site_lockouts l WHERE l.id = :id', ['id' => $id]);

        return is_array($row) ? $row : null;
    }

    /** Ends a lockout in force; false when it had already ended. */
    public function lift(int $id, ?string $by, ?string $firewall = null): bool
    {
        return $this->connection->executeStatement(
            'UPDATE cattophp_site_lockouts l SET lifted_at = NOW(), lifted_by = :by, firewall = COALESCE(:firewall, firewall) WHERE l.id = :id AND ' . self::IN_FORCE,
            ['id' => $id, 'by' => $by, 'firewall' => $firewall]
        ) > 0;
    }

    /** @return array<string,int> how many each kind holds */
    public function counts(): array
    {
        $columns = [];
        foreach (self::KINDS as $key => $kind) {
            $columns[] = 'COUNT(*) FILTER (WHERE ' . $kind['where'] . ') AS ' . $key;
        }
        $row = $this->connection->fetchAssociative('SELECT ' . implode(', ', $columns) . ' FROM cattophp_site_lockouts l');

        return array_map('intval', is_array($row) ? $row : array_fill_keys(array_keys(self::KINDS), 0));
    }

    /** @return list<array<string,mixed>> */
    public function page(string $kind, int $limit, int $offset): array
    {
        $where = self::KINDS[$kind]['where'] ?? self::IN_FORCE;

        return $this->connection->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM cattophp_site_lockouts l WHERE ' . $where . ' ORDER BY l.started_at DESC, l.id DESC LIMIT :limit OFFSET :offset', ['limit' => $limit, 'offset' => $offset]);
    }

    /** @return list<array<string,mixed>> every lockout that covered an address, newest first */
    public function forSubject(string $subject): array
    {
        return $this->connection->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM cattophp_site_lockouts l WHERE l.network >>= CAST(:subject AS inet) ORDER BY l.started_at DESC', ['subject' => $subject]);
    }

    /**
     * The lockout keeping an address out now, the longest kind first, or a network owner's.
     *
     * @return array<string,mixed>|null
     */
    public function inForceFor(string $subject, ?int $asn): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM cattophp_site_lockouts l WHERE ' . self::IN_FORCE . ' AND (l.network >>= CAST(:subject AS inet) OR l.asn = :asn)
             ORDER BY CASE l.which WHEN \'lockout\' THEN 0 ELSE 1 END, l.until_at DESC NULLS FIRST LIMIT 1',
            ['subject' => $subject, 'asn' => $asn]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * For the lockout gate: what is in force on this address, and every network owner locked out (whose addresses it
     * cannot tell without the network's number).
     *
     * @return list<array{id:int,asn:?int}>
     */
    public function inForceOnAddressOrAnyOwner(string $address): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'asn' => $row['asn'] === null ? null : (int) $row['asn']], $this->connection->fetchAllAssociative(
            'SELECT l.id, l.asn FROM cattophp_site_lockouts l
             WHERE ' . self::IN_FORCE . ' AND (l.asn IS NOT NULL OR l.network >>= CAST(:address AS inet))
             ORDER BY l.asn NULLS FIRST, l.started_at',
            ['address' => $address]
        ));
    }

    /** Adds to a lockout's counter, and says how many it has refused today. */
    public function countRefused(int $id): int
    {
        return (int) $this->connection->fetchOne(
            'UPDATE cattophp_site_lockouts SET refused = refused + 1, last_refused_at = NOW(),
                logged_on_day = CASE WHEN logged_day = CURRENT_DATE THEN logged_on_day + 1 ELSE 1 END, logged_day = CURRENT_DATE
             WHERE id = :id RETURNING logged_on_day',
            ['id' => $id]
        );
    }

    /** Ends what is in force on exactly this target, so a new lockout replaces it. */
    public function liftSameTarget(?string $network, ?int $asn, ?string $by): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE cattophp_site_lockouts l SET lifted_at = NOW(), lifted_by = :by WHERE ' . self::IN_FORCE . ' AND (l.network = CAST(:network AS cidr) OR l.asn = :asn)',
            ['by' => $by, 'network' => $network, 'asn' => $asn]
        );
    }

    /** Ends what the site itself put on an address. */
    public function liftAutomatic(string $subject, ?string $by): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE cattophp_site_lockouts l SET lifted_at = NOW(), lifted_by = :by WHERE ' . self::IN_FORCE . ' AND l.automatic AND l.network = CAST(:subject AS cidr)',
            ['by' => $by, 'subject' => $subject]
        );
    }

    /** @return list<array{started_at:string,until_at:?string,which:string}> an address's automatic time-outs since a moment, newest first */
    public function automaticTimeoutsSince(string $subject, DateTimeImmutable $since): array
    {
        return array_map(static fn(array $row): array => ['started_at' => (string) $row['started_at'], 'until_at' => $row['until_at'] === null ? null : (string) $row['until_at'], 'which' => (string) $row['which']], $this->connection->fetchAllAssociative(
            "SELECT started_at, until_at, which FROM cattophp_site_lockouts WHERE automatic AND which = 'timeout' AND network = CAST(:subject AS cidr) AND started_at >= :since ORDER BY started_at DESC",
            ['subject' => $subject, 'since' => $since->format(DATE_ATOM)]
        ));
    }

    /** @return list<array{id:int,network_text:string}> lockouts at the firewall whose time has run out and which it still holds */
    public function firewallDue(): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'network_text' => (string) $row['network_text']], $this->connection->fetchAllAssociative(
            "SELECT l.id, abbrev(CAST(l.network AS inet)) AS network_text FROM cattophp_site_lockouts l WHERE l.firewall = 'banned' AND NOT " . self::IN_FORCE
        ));
    }

    public function firewallReleased(int $id): void
    {
        $this->connection->executeStatement("UPDATE cattophp_site_lockouts SET firewall = 'released' WHERE id = :id", ['id' => $id]);
    }

    /** Removes lockouts that ended before a moment, unless the firewall still holds them. */
    public function deleteEndedBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM cattophp_site_lockouts l WHERE NOT ' . self::IN_FORCE . " AND l.firewall <> 'banned' AND COALESCE(l.lifted_at, l.until_at) < :before",
            ['before' => $before->format(DATE_ATOM)]
        );
    }
}
