<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The Site Log (cattophp_site_requests): every request, written as it ends, and what the rules count in it. A project's
 * own pages read it for their figures and lists; this keeps what site protection itself needs.
 */
class SiteLog
{
    public const COLUMNS = ['address', 'subject', 'account_id', 'by_administrator', 'visitor', 'agent_kind', 'agent_label', 'device', 'user_agent', 'country_code', 'network_kind', 'network_asn', 'network_name',
        'method', 'path', 'status', 'is_page', 'came_from', 'source', 'form', 'form_signal', 'form_refused', 'form_email', 'form_email_hash'];

    /** What each rule counts, among requests not refused. */
    private const CONDITIONS = [
        'missing' => 'r.status = 404',
        'flood' => 'TRUE',
        'links' => "r.form = 'sign_in'",
        'register' => "r.form = 'register'",
        'tokens' => "r.method = 'POST' AND r.status = 400",
        'trap' => "r.form_signal = 'trap'",
        'script' => "r.form_signal IN ('script', 'suspect_network')",
        'old' => "r.form_signal IN ('outdated', 'no_browser')",
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string,mixed> $row a value for each of COLUMNS */
    public function record(array $row): void
    {
        $values = [];
        foreach (self::COLUMNS as $column) {
            $values[$column] = in_array($column, ['by_administrator', 'is_page', 'form_refused'], true) ? (bool) ($row[$column] ?? false) : ($row[$column] ?? null);
        }
        $placeholders = array_map(static fn(string $column): string => in_array($column, ['address', 'subject'], true) ? 'CAST(:' . $column . ' AS inet)' : ':' . $column, self::COLUMNS);
        $this->connection->executeStatement(
            'INSERT INTO cattophp_site_requests (' . implode(', ', self::COLUMNS) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $values,
            ['by_administrator' => 'boolean', 'is_page' => 'boolean', 'form_refused' => 'boolean']
        );
    }

    /**
     * A request the lockout gate refused.
     *
     * @param array{address:string,subject:string,visitor:string,kind:string,label:string,device:string,agent:string,country:?string,network_kind:string,asn:?int,network:string,method:string,path:string} $row
     */
    public function recordRefused(array $row): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_site_requests (address, subject, visitor, agent_kind, agent_label, device, user_agent, country_code, network_kind, network_asn, network_name, method, path, status, refused)
             VALUES (CAST(:address AS inet), CAST(:subject AS inet), :visitor, :kind, :label, :device, :agent, :country, :network_kind, :asn, :network, :method, :path, 403, TRUE)',
            $row
        );
    }

    /** @return array{country_code:?string,network_kind:string,network_asn:?int,network_name:string}|null the country and network the log last had for this address */
    public function lastKnown(string $subject): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT country_code, network_kind, network_asn, network_name FROM cattophp_site_requests WHERE subject = CAST(:subject AS inet) ORDER BY at DESC LIMIT 1',
            ['subject' => $subject]
        );

        return is_array($row) ? ['country_code' => $row['country_code'] === null ? null : (string) $row['country_code'], 'network_kind' => (string) $row['network_kind'],
            'network_asn' => $row['network_asn'] === null ? null : (int) $row['network_asn'], 'network_name' => (string) $row['network_name']] : null;
    }

    /**
     * The addresses over a rule's limit. Requests from before a lockout of the address ended are not counted again.
     *
     * @return list<array{subject:string,n:int}>
     */
    public function offenders(string $rule, int $withinSeconds, int $count): array
    {
        $measure = $rule === 'links' ? 'COUNT(DISTINCT r.form_email_hash)' : 'COUNT(*)';
        $rows = $this->connection->fetchAllAssociative(
            "SELECT abbrev(r.subject) AS subject, $measure AS n FROM cattophp_site_requests r
             WHERE r.at > NOW() - make_interval(secs => :within) AND NOT r.refused AND " . self::CONDITIONS[$rule] . "
               AND NOT EXISTS (SELECT 1 FROM cattophp_site_lockouts l WHERE l.network >>= r.subject AND r.at < COALESCE(l.lifted_at, l.until_at, 'infinity'))
             GROUP BY r.subject HAVING $measure >= :count",
            ['within' => $withinSeconds, 'count' => $count]
        );

        return array_map(static fn(array $row): array => ['subject' => (string) $row['subject'], 'n' => (int) $row['n']], $rows);
    }

    public function usedByAnAdministrator(string $subject, int $days): bool
    {
        return (bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM cattophp_site_requests WHERE subject = CAST(:subject AS inet) AND by_administrator AND at > NOW() - make_interval(days => :days))', ['subject' => $subject, 'days' => $days]);
    }

    public function usedBySomeoneSignedIn(string $subject, int $days): bool
    {
        return (bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM cattophp_site_requests WHERE subject = CAST(:subject AS inet) AND account_id IS NOT NULL AND at > NOW() - make_interval(days => :days))', ['subject' => $subject, 'days' => $days]);
    }

    public function isProvedSearchEngine(string $subject): bool
    {
        return (bool) $this->connection->fetchOne("SELECT EXISTS (SELECT 1 FROM cattophp_site_requests WHERE subject = CAST(:subject AS inet) AND agent_kind = 'search' AND agent_proved)", ['subject' => $subject]);
    }

    /** @return list<array{address:string,engine:string}> addresses calling themselves search engines in the past day, not yet asked about */
    public function searchEnginesToProve(int $limit): array
    {
        return array_map(static fn(array $row): array => ['address' => (string) $row['address'], 'engine' => (string) $row['engine']], $this->connection->fetchAllAssociative(
            "SELECT host(address) AS address, MAX(agent_label) AS engine FROM cattophp_site_requests WHERE agent_kind = 'search' AND agent_proved IS NULL AND at > NOW() - INTERVAL '1 day' GROUP BY address ORDER BY MAX(at) DESC LIMIT :limit",
            ['limit' => $limit]
        ));
    }

    /** The answer given for this address in the past week, or null. */
    public function earlierProof(string $address): ?bool
    {
        // In words, since the database's own false would read as no answer at all.
        $known = $this->connection->fetchOne("SELECT CASE WHEN agent_proved THEN 'yes' ELSE 'no' END FROM cattophp_site_requests WHERE address = CAST(:address AS inet) AND agent_proved IS NOT NULL AND at > NOW() - INTERVAL '7 days' ORDER BY at DESC LIMIT 1", ['address' => $address]);

        return $known === 'yes' ? true : ($known === 'no' ? false : null);
    }

    public function proved(string $address, bool $proved): void
    {
        $this->connection->executeStatement('UPDATE cattophp_site_requests SET agent_proved = :proved WHERE address = CAST(:address AS inet) AND agent_proved IS NULL', ['proved' => $proved, 'address' => $address], ['proved' => 'boolean']);
    }

    public function deleteBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_site_requests WHERE at < :before', ['before' => $before->format(DATE_ATOM)]);
    }

    /** An account being deleted: its requests stay in the Site Log, signed in as nobody now. Returns how many. */
    public function forget(string $account): int
    {
        return (int) $this->connection->executeStatement('UPDATE cattophp_site_requests SET account_id = NULL WHERE account_id = :account', ['account' => $account]);
    }
}
