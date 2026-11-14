<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Rows;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The Visitors list's reads: one row for each person, all of their visitors together (every visitor tied to one
 * account), and one row for each visitor nobody knows yet, with the figures one person's journey gives
 * (Journey\PersonJourneys): visits (counted clicks and comings back), campaigns came by, state (a paid sale, else one
 * waiting for payment, else an account, else not registered; a sale is theirs when one of their visitors placed it or it
 * names their account), countries and devices, first and last seen. A visitor made anonymous (their journey forgotten,
 * or kept past MARKETING_HISTORY_KEPT) names nobody and is not listed. Its totals and state counts are of the same rows.
 */
final class VisitorList
{
    /** What the list may be sorted by. */
    public const SORTS = ['last_seen' => 'p.last_seen', 'first_seen' => 'p.first_seen', 'visits' => 'p.visits', 'campaigns' => 'p.campaigns'];

    /** At most this many of the project's people a search names. */
    private const MATCHED = 500;

    public function __construct(private readonly Connection $connection, private readonly MarketingProject $project)
    {
    }

    /** @return list<VisitorRow> the rows a filter holds, in an order (SORTS), the last seen first by default */
    public function list(VisitorFilter $filter, int $limit = 25, int $offset = 0, string $sort = 'last_seen', string $direction = 'desc'): array
    {
        if (!isset(self::SORTS[$sort])) {
            throw new \InvalidArgumentException('Visitors are sorted by ' . implode(', ', array_keys(self::SORTS)) . '.');
        }
        [$where, $params, $types] = $this->filtered($filter);
        $order = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $utc = new DateTimeZone('UTC');
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('SELECT p.account, p.refs, p.countries, p.devices, p.first_seen, p.last_seen, p.visits, p.campaigns, p.state FROM (' . self::people()
            . ') p WHERE ' . $where . ' ORDER BY ' . self::SORTS[$sort] . ' ' . $order . ', p.last_seen ' . $order . ', p.person LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, $limit), 'offset' => max(0, $offset)], $types) as $row) {
            $references = self::split($row['refs']);
            $countries = array_values(array_unique(array_filter(self::split($row['countries']), static fn (string $country): bool => preg_match('/^[A-Z]{2}$/', $country) === 1)));
            $devices = array_values(array_unique(array_filter(self::split($row['devices']), static fn (string $device): bool => $device !== 'unknown')));
            $rows[] = new VisitorRow($references[0] ?? '', Rows::nullableText($row['account']), $references, Rows::instant($row['first_seen'], $utc) ?? new \DateTimeImmutable(),
                Rows::instant($row['last_seen'], $utc) ?? new \DateTimeImmutable(), $countries, $devices, Rows::int($row['visits']), Rows::int($row['campaigns']), Rows::text($row['state']));
        }

        return $rows;
    }

    /** How many rows a filter holds. */
    public function count(VisitorFilter $filter): int
    {
        [$where, $params, $types] = $this->filtered($filter);

        return Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM (' . self::people() . ') p WHERE ' . $where, $params, $types));
    }

    /**
     * The totals of the rows a filter holds: visitors (each person once, all their devices together), known people (an
     * account), paying customers, and the browsers and devices they came on.
     *
     * @return array{visitors:int,known:int,paying:int,devices:int}
     */
    public function totals(VisitorFilter $filter): array
    {
        [$where, $params, $types] = $this->filtered($filter);
        $row = $this->connection->fetchAssociative("SELECT COUNT(*) AS visitors, COUNT(*) FILTER (WHERE p.account IS NOT NULL) AS known, COUNT(*) FILTER (WHERE p.state = 'paying') AS paying,
                COALESCE(SUM(cardinality(p.ids)), 0) AS devices FROM (" . self::people() . ') p WHERE ' . $where, $params, $types) ?: [];

        return ['visitors' => Rows::int($row['visitors'] ?? 0), 'known' => Rows::int($row['known'] ?? 0), 'paying' => Rows::int($row['paying'] ?? 0), 'devices' => Rows::int($row['devices'] ?? 0)];
    }

    /**
     * How many of the rows a filter holds are in each state, whatever the filter's own state, and everyone.
     *
     * @return array<string,int>
     */
    public function stateCounts(VisitorFilter $filter): array
    {
        [$where, $params, $types] = $this->filtered($filter->everyone());
        $counts = array_fill_keys(array_keys(VisitorFilter::STATES), 0);
        foreach ($this->connection->fetchAllAssociative('SELECT p.state, COUNT(*) AS n FROM (' . self::people() . ') p WHERE ' . $where . ' GROUP BY p.state', $params, $types) as $row) {
            $counts[Rows::text($row['state'])] = Rows::int($row['n']);
            $counts['everyone'] += Rows::int($row['n']);
        }

        return $counts;
    }

    /**
     * Every person and every visitor nobody knows, as one row each, with their figures.
     */
    private static function people(): string
    {
        $sales = 'SELECT 1 FROM ' . Sales::TABLE . ' s WHERE (s.visitor_id = ANY(g.ids) OR s.account = g.account) AND s.state = ';

        return 'SELECT g.*,
                (SELECT COUNT(*) FROM ' . Touches::TABLE . ' t WHERE t.visitor_id = ANY(g.ids) AND t.counted)
                + (SELECT COUNT(*) FROM ' . Events::TABLE . " e WHERE e.visitor_id = ANY(g.ids) AND e.kind = 'came_back' AND (e.account IS NULL OR e.account IS NOT DISTINCT FROM g.account)) AS visits,
                (SELECT COUNT(DISTINCT t.campaign_id) FROM " . Touches::TABLE . " t WHERE t.visitor_id = ANY(g.ids) AND t.counted) AS campaigns,
                CASE WHEN EXISTS (" . $sales . "'paid') THEN 'paying' WHEN EXISTS (" . $sales . "'waiting') THEN 'waiting'
                    WHEN g.account IS NOT NULL THEN 'registered' ELSE 'not_registered' END AS state
            FROM (SELECT COALESCE('a:' || v.account, 'v:' || v.id::text) AS person, MIN(v.account) AS account, array_agg(v.id ORDER BY v.first_seen_at, v.id) AS ids,
                    array_agg(v.reference) AS ref_list, string_agg(v.reference, ',' ORDER BY v.first_seen_at, v.id) AS refs, array_agg(v.country::text) AS country_list,
                    string_agg(v.country, ',' ORDER BY v.first_seen_at, v.id) AS countries, string_agg(v.device, ',' ORDER BY v.first_seen_at, v.id) AS devices,
                    MIN(v.first_seen_at) AS first_seen, MAX(v.last_seen_at) AS last_seen
                FROM " . Visitors::TABLE . ' v WHERE v.anonymised_at IS NULL GROUP BY 1) g';
    }

    /**
     * The conditions a filter sets on the rows, with their values.
     *
     * @return array{0:string,1:array<string,mixed>,2:array<string,ArrayParameterType>}
     */
    private function filtered(VisitorFilter $filter): array
    {
        $where = ['TRUE'];
        $params = [];
        $types = [];
        if ($filter->from !== null) {
            $where[] = 'p.last_seen >= :from';
            $params['from'] = Rows::sql($filter->from);
        }
        if ($filter->until !== null) {
            $where[] = 'p.last_seen < :until';
            $params['until'] = Rows::sql($filter->until);
        }
        if ($filter->state !== 'everyone') {
            $where[] = 'p.state = :state';
            $params['state'] = $filter->state;
        }
        if ($filter->campaignId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . Touches::TABLE . ' fc WHERE fc.visitor_id = ANY(p.ids) AND fc.counted AND fc.campaign_id = :campaign)';
            $params['campaign'] = $filter->campaignId;
        }
        if ($filter->country !== null) {
            $where[] = ':country = ANY(p.country_list)';
            $params['country'] = $filter->country;
        }
        $search = trim($filter->search);
        if ($search !== '') {
            $found = [];
            $accounts = $this->project->accountsMatching($search, self::MATCHED);
            if ($accounts !== []) {
                $found[] = 'p.account IN (:accounts)';
                $params['accounts'] = $accounts;
                $types['accounts'] = ArrayParameterType::STRING;
            }
            $reference = strtolower(preg_replace('/^visitor\s+/i', '', $search) ?? $search);
            if (preg_match(Visitors::REFERENCE, $reference) === 1) {
                $found[] = ':reference = ANY(p.ref_list)';
                $params['reference'] = $reference;
            }
            $where[] = $found === [] ? 'FALSE' : '(' . implode(' OR ', $found) . ')';
        }

        return [implode(' AND ', $where), $params, $types];
    }

    /** @return list<string> */
    private static function split(mixed $value): array
    {
        $text = Rows::text($value);

        return $text === '' ? [] : explode(',', $text);
    }
}
