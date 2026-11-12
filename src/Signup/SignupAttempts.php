<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Every registration attempt (cattophp_signup_attempts), with what the sign-up check found: the address, its country
 * and network, the browser, the form's time open and the browser's time zone, the score, band, signals and hold
 * reasons, and its outcome (link_sent, refused, trapped, or completed once it became an account). The project's own
 * fields of the form are kept as its details. An attempt that never became an account is deleted by the project's
 * clean-up after the time it keeps them.
 */
final class SignupAttempts
{
    public const OUTCOMES = ['link_sent', 'refused', 'trapped', 'completed'];

    /** What a list reads of an attempt. */
    public const COLUMNS = "a.id, a.public_id::text AS public_id, to_char(a.created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
            a.email, a.first_name, a.last_name, a.details::text AS details, COALESCE(host(a.ip_address), '') AS ip_address, COALESCE(a.country_code, '') AS country_code,
            COALESCE(a.home_country, '') AS home_country, a.network_asn, a.network_name, a.user_agent, a.browser_time_zone, a.form_seconds, a.score, a.band, a.signals::text AS signals,
            a.hold_reasons::text AS hold_reasons, a.outcome, a.refusal, a.held, a.account, to_char(a.completed_at, 'YYYY-MM-DD HH24:MI') AS completed_at";

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Records an attempt: its id.
     *
     * @param array{email:string,first_name?:string,last_name?:string,details?:array<string,mixed>,ip?:string,country?:string,home_country?:string,asn?:?int,network?:string,agent?:string,zone?:string,seconds?:?int,score:int,band:string,signals?:list<array<string,mixed>>,hold_reasons?:list<string>,outcome:string,refusal?:?string} $row
     */
    public function record(array $row): int
    {
        return (int) $this->connection->fetchOne(
            "INSERT INTO cattophp_signup_attempts (email, first_name, last_name, details, ip_address, country_code, home_country, network_asn, network_name,
                 user_agent, browser_time_zone, form_seconds, score, band, signals, hold_reasons, outcome, refusal)
             VALUES (:email, :first_name, :last_name, CAST(:details AS jsonb), CAST(NULLIF(:ip, '') AS inet), NULLIF(:country, ''), NULLIF(:home, ''), :asn, :network,
                 :agent, :zone, :seconds, :score, :band, CAST(:signals AS jsonb), CAST(:hold AS jsonb), :outcome, :refusal)
             RETURNING id",
            [
                'email' => mb_substr($row['email'], 0, 254), 'first_name' => mb_substr($row['first_name'] ?? '', 0, 120), 'last_name' => mb_substr($row['last_name'] ?? '', 0, 120),
                'details' => json_encode((object) ($row['details'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'ip' => $row['ip'] ?? '',
                'country' => $row['country'] ?? '', 'home' => $row['home_country'] ?? '', 'asn' => $row['asn'] ?? null, 'network' => mb_substr($row['network'] ?? '', 0, 200),
                'agent' => mb_substr($row['agent'] ?? '', 0, 500), 'zone' => mb_substr($row['zone'] ?? '', 0, 64), 'seconds' => $row['seconds'] ?? null,
                'score' => $row['score'], 'band' => $row['band'], 'signals' => json_encode($row['signals'] ?? [], JSON_THROW_ON_ERROR),
                'hold' => json_encode($row['hold_reasons'] ?? [], JSON_THROW_ON_ERROR), 'outcome' => $row['outcome'],
                'refusal' => isset($row['refusal']) ? mb_substr($row['refusal'], 0, 300) : null,
            ],
        );
    }

    /** The attempt became an account, held for approval or not. */
    public function complete(int $id, string $account, bool $held): void
    {
        $this->connection->executeStatement(
            "UPDATE cattophp_signup_attempts SET outcome = 'completed', account = :account, held = :held, completed_at = NOW() WHERE id = :id AND account IS NULL",
            ['id' => $id, 'account' => $account, 'held' => $held ? 'true' : 'false'],
        );
    }

    /** Other attempts from the same address in the last day. */
    public function recentFromAddress(string $address): int
    {
        if ($address === '') {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM cattophp_signup_attempts WHERE ip_address = CAST(:ip AS inet) AND created_at > NOW() - INTERVAL '24 hours'",
            ['ip' => $address],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM cattophp_signup_attempts a WHERE a.id = :id', ['id' => $id]);

        return $row === false ? null : self::decode($row);
    }

    /** @return array<string,mixed>|null the attempt an account was registered by */
    public function forAccount(string $account): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM cattophp_signup_attempts a WHERE a.account = :account ORDER BY a.completed_at DESC NULLS LAST, a.id DESC LIMIT 1',
            ['account' => $account],
        );

        return $row === false ? null : self::decode($row);
    }

    /**
     * Each account's band, score and country, for a page of people.
     *
     * @param list<string> $accounts
     * @return array<string,array{id:int,band:string,score:int,country_code:string}>
     */
    public function forAccounts(array $accounts): array
    {
        $accounts = array_values(array_unique(array_filter($accounts, static fn(string $account): bool => $account !== '')));
        if ($accounts === []) {
            return [];
        }
        $found = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT DISTINCT ON (account) id, account, band, score, COALESCE(country_code, '') AS country_code
               FROM cattophp_signup_attempts WHERE account = ANY(CAST(:accounts AS text[])) ORDER BY account, completed_at DESC NULLS LAST, id DESC",
            ['accounts' => self::textArray($accounts)],
        ) as $row) {
            $found[(string) $row['account']] = ['id' => (int) $row['id'], 'band' => (string) $row['band'], 'score' => (int) $row['score'], 'country_code' => (string) $row['country_code']];
        }

        return $found;
    }

    /** @return array<string,int> attempts in the last 30 days by band, and in all */
    public function lastMonth(): array
    {
        $counts = ['low' => 0, 'medium' => 0, 'high' => 0, 'all' => 0];
        foreach ($this->connection->fetchAllAssociative("SELECT band, COUNT(*) AS n FROM cattophp_signup_attempts WHERE created_at > NOW() - INTERVAL '30 days' GROUP BY band") as $row) {
            $counts[(string) $row['band']] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }

        return $counts;
    }

    /** Registrations completed in a period, for a project's figures. */
    public function completedBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM cattophp_signup_attempts WHERE outcome = 'completed' AND completed_at >= :from AND completed_at < :to",
            ['from' => $from->format('Y-m-d H:i:sP'), 'to' => $to->format('Y-m-d H:i:sP')],
        );
    }

    /** Deletes attempts that never became an account, made before the cutoff: how many. */
    public function deleteUnclaimedBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_signup_attempts WHERE account IS NULL AND created_at < :before', ['before' => $before->format('Y-m-d H:i:sP')]);
    }

    /**
     * An attempt as read: its signals, hold reasons and details as lists and maps.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function decode(array $row): array
    {
        $row['signals'] = json_decode((string) $row['signals'], true) ?: [];
        $row['hold_reasons'] = json_decode((string) $row['hold_reasons'], true) ?: [];
        $row['details'] = json_decode((string) ($row['details'] ?? '{}'), true) ?: [];
        $row['id'] = (int) $row['id'];
        $row['score'] = (int) $row['score'];
        $row['account'] = $row['account'] === null ? null : (string) $row['account'];

        return $row;
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn(string $value): string => '"' . addcslashes($value, '"\\') . '"', $values)) . '}';
    }
}
