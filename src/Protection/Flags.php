<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Flagged addresses (cattophp_site_flags): what each did, how serious it is, and whether a person marked it not a
 * problem. Where an address stands is worked out from what keeps it out now: waiting for a decision, timed out and
 * ended, locked out, or marked not a problem.
 */
class Flags
{
    public const STATES = ['waiting' => 'Waiting for you', 'timed' => 'Timed out automatically, ended', 'locked' => 'Locked out', 'fine' => 'Marked not a problem'];

    /** How many reasons a flag keeps, newest first. */
    public const REASONS_KEPT = 20;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Raises a flag, or adds to one. A rule that keeps firing inside its window adds nothing more.
     *
     * @return bool whether anything new was recorded
     */
    public function raise(string $subject, string $level, string $rule, string $words, int $sameWithinSeconds): bool
    {
        $current = $this->findBySubject($subject);
        $entry = ['at' => gmdate(DATE_ATOM), 'rule' => $rule, 'words' => $words];
        if ($current === null) {
            $this->connection->executeStatement(
                'INSERT INTO cattophp_site_flags (subject, level, why) VALUES (CAST(:subject AS inet), :level, CAST(:why AS jsonb)) ON CONFLICT (subject) DO NOTHING',
                ['subject' => $subject, 'level' => $level, 'why' => json_encode([$entry], JSON_THROW_ON_ERROR)]
            );

            return true;
        }
        $why = self::why($current);
        foreach ($why as $earlier) {
            if ($earlier['rule'] === $rule && time() - (int) strtotime($earlier['at']) < $sameWithinSeconds) return false;
        }
        array_unshift($why, $entry);
        $this->connection->executeStatement(
            "UPDATE cattophp_site_flags SET why = CAST(:why AS jsonb), last_flagged_at = NOW(), level = CASE WHEN level = 'high' OR :level = 'high' THEN 'high' ELSE 'medium' END WHERE id = :id",
            ['id' => (int) $current['id'], 'why' => json_encode(array_slice($why, 0, self::REASONS_KEPT), JSON_THROW_ON_ERROR), 'level' => $level]
        );

        return true;
    }

    /** @return array<string,mixed>|null */
    public function findBySubject(string $subject): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT f.*, abbrev(f.subject) AS subject_text FROM cattophp_site_flags f WHERE f.subject = CAST(:subject AS inet)', ['subject' => $subject]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT f.*, abbrev(f.subject) AS subject_text FROM cattophp_site_flags f WHERE f.id = :id', ['id' => $id]);

        return is_array($row) ? $row : null;
    }

    /** @param string|null $by who decided, by account id */
    public function markFine(string $subject, ?string $by): void
    {
        $this->connection->executeStatement('UPDATE cattophp_site_flags SET fine = TRUE, decided_by = :by, decided_at = NOW() WHERE subject = CAST(:subject AS inet)', ['subject' => $subject, 'by' => $by]);
    }

    /** Whether an address was marked not a problem within these days, and is left alone. */
    public function isFine(string $subject, int $days): bool
    {
        return (bool) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_site_flags WHERE subject = CAST(:subject AS inet) AND fine AND decided_at > NOW() - make_interval(days => :days)', ['subject' => $subject, 'days' => $days]);
    }

    /** A mark of not a problem that has run out: the address can be flagged again. */
    public function reopen(string $subject): void
    {
        $this->connection->executeStatement('UPDATE cattophp_site_flags SET fine = FALSE, decided_at = NULL, decided_by = NULL WHERE subject = CAST(:subject AS inet)', ['subject' => $subject]);
    }

    /**
     * Every flagged address with what it did and what keeps it out, and where it stands.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT f.id, abbrev(f.subject) AS subject, f.level, f.fine, f.why, f.first_flagged_at, f.last_flagged_at, f.decided_at, f.decided_by,
                s.requests, s.last_seen, n.country_code, n.network_kind, n.network_name, n.network_asn,
                force.id AS lockout_id, force.which AS lockout_which, force.until_at AS lockout_until, force.automatic AS lockout_automatic, force.started_at AS lockout_since,
                ended.id AS ended_id, ended.until_at AS ended_until, ended.lifted_at AS ended_lifted, ended.automatic AS ended_automatic
             FROM cattophp_site_flags f
             LEFT JOIN LATERAL (SELECT COUNT(*) AS requests, MAX(at) AS last_seen FROM cattophp_site_requests r WHERE r.subject = f.subject) s ON TRUE
             LEFT JOIN LATERAL (SELECT country_code, network_kind, network_name, network_asn FROM cattophp_site_requests r WHERE r.subject = f.subject ORDER BY r.at DESC LIMIT 1) n ON TRUE
             LEFT JOIN LATERAL (SELECT l.id, l.which, l.until_at, l.automatic, l.started_at FROM cattophp_site_lockouts l WHERE l.network >>= f.subject AND " . Lockouts::IN_FORCE . " ORDER BY CASE l.which WHEN 'lockout' THEN 0 ELSE 1 END, l.until_at DESC NULLS FIRST LIMIT 1) force ON TRUE
             LEFT JOIN LATERAL (SELECT l.id, l.until_at, l.lifted_at, l.automatic FROM cattophp_site_lockouts l WHERE l.network >>= f.subject AND NOT " . Lockouts::IN_FORCE . " ORDER BY COALESCE(l.lifted_at, l.until_at) DESC LIMIT 1) ended ON TRUE
             ORDER BY f.last_flagged_at DESC"
        );

        return array_map(static fn(array $row): array => $row + ['state' => self::state($row), 'why_list' => self::why($row)], $rows);
    }

    /** @return list<array<string,mixed>> the addresses waiting for a decision */
    public function waiting(): array
    {
        return array_values(array_filter($this->all(), static fn(array $row): bool => $row['state'] === 'waiting'));
    }

    /** @param array<string,mixed> $row */
    public static function state(array $row): string
    {
        if ($row['fine'] === true || $row['fine'] === 't') return 'fine';
        if (($row['lockout_which'] ?? null) === 'lockout') return 'locked';
        if (($row['lockout_id'] ?? null) !== null) return 'waiting';

        return ($row['ended_id'] ?? null) !== null ? 'timed' : 'waiting';
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array{at:string,rule:string,words:string}>
     */
    public static function why(array $row): array
    {
        $why = json_decode((string) ($row['why'] ?? '[]'), true);
        $reasons = [];
        foreach (is_array($why) ? $why : [] as $entry) {
            if (is_array($entry)) $reasons[] = ['at' => (string) ($entry['at'] ?? ''), 'rule' => (string) ($entry['rule'] ?? ''), 'words' => (string) ($entry['words'] ?? '')];
        }

        return $reasons;
    }

    /** Removes flags not raised since a moment, unless something keeps the address out now. */
    public function deleteBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM cattophp_site_flags f WHERE f.last_flagged_at < :before AND NOT EXISTS (SELECT 1 FROM cattophp_site_lockouts l WHERE l.network >>= f.subject AND ' . Lockouts::IN_FORCE . ')',
            ['before' => $before->format(DATE_ATOM)]
        );
    }
}
