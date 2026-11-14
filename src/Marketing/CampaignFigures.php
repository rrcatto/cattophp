<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Country\Countries;
use CattoPHP\Installation\Installation;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * The figures the Campaign Marketing Manager's pages show, for a period (Period) and all campaigns or one: clicks, unique
 * visitors, repeat visits, the openings set aside (link previews and bots) and spend, in total and compared by campaign,
 * channel, publication, advert, place, country and day; and what needs attention on the overview. Clicks are people's
 * openings that count: a repeat within the same visit, and an opening while the campaign was not running, are not
 * clicks. A browser that asked not to be tracked has no visitor, so each of its clicks counts as one unique visitor.
 */
final class CampaignFigures
{
    /** The comparisons a page may ask for, each in words. */
    public const BREAKDOWNS = ['campaign' => 'Campaign', 'channel' => 'Channel', 'publication' => 'Publication', 'advert' => 'Advert', 'place' => 'Place', 'country' => 'Country', 'day' => 'Day'];

    /** The most days a comparison by day fills in. */
    private const LONGEST_DAYS = 3660;

    private const COUNTS = "COUNT(*) FILTER (WHERE t.counted) AS clicks,
        COUNT(DISTINCT t.visitor_id) FILTER (WHERE t.counted) + COUNT(*) FILTER (WHERE t.counted AND t.visitor_id IS NULL) AS unique_visitors,
        COUNT(*) FILTER (WHERE t.counted AND t.visitor_id IS NOT NULL AND NOT t.first_for_visitor) AS repeat_visits,
        COUNT(*) FILTER (WHERE t.agent <> 'person') AS set_aside";

    private const TOUCH_JOINS = ' JOIN ' . Campaigns::TABLE . ' c ON c.id = t.campaign_id LEFT JOIN ' . Publications::TABLE . ' p ON p.id = t.publication_id
        LEFT JOIN ' . Channels::TABLE . ' ch ON ch.key = COALESCE(p.channel_key, t.source) LEFT JOIN ' . Adverts::TABLE . ' a ON a.id = p.advert_id';

    private const PUBLICATION_JOINS = ' JOIN ' . Campaigns::TABLE . ' c ON c.id = p.campaign_id LEFT JOIN ' . Channels::TABLE . ' ch ON ch.key = p.channel_key
        LEFT JOIN ' . Adverts::TABLE . ' a ON a.id = p.advert_id';

    private const SPEND_JOINS = ' JOIN ' . Campaigns::TABLE . ' c ON c.id = s.campaign_id';

    /** The day a publication's cost counts on: when it was published, else when it was made. */
    private const PUBLISHED_DAY = '(COALESCE(p.published_on, (p.created_at AT TIME ZONE :zone)::date))';

    /**
     * Each comparison: its key and label for openings, for publications' costs (null: costs are not its), for other costs
     * (null: likewise), and what a row belongs to.
     *
     * @var array<string,array{key:string,label:string,context:string,publication_key:?string,spend_key:?string}>
     */
    private const DIMENSIONS = [
        'campaign' => ['key' => 'c.code', 'label' => 'c.name', 'context' => "''", 'publication_key' => 'c.code', 'spend_key' => 'c.code'],
        'channel' => ['key' => "COALESCE(ch.key, '')", 'label' => "COALESCE(ch.label, '')", 'context' => "''", 'publication_key' => "COALESCE(ch.key, '')", 'spend_key' => null],
        'publication' => ['key' => "c.code || '/' || COALESCE(p.code, '')", 'label' => "COALESCE(p.code, '')",
            'context' => "c.name || COALESCE(', ' || ch.label, '') || CASE WHEN COALESCE(p.place, '') <> '' THEN ', ' || p.place ELSE '' END",
            'publication_key' => "c.code || '/' || COALESCE(p.code, '')", 'spend_key' => null],
        'advert' => ['key' => "COALESCE(a.reference, '')", 'label' => "COALESCE(a.name, '')", 'context' => "CASE WHEN a.id IS NULL THEN '' ELSE c.name END", 'publication_key' => "COALESCE(a.reference, '')", 'spend_key' => null],
        'place' => ['key' => "lower(trim(COALESCE(p.place, '')))", 'label' => "trim(COALESCE(p.place, ''))", 'context' => "''", 'publication_key' => "lower(trim(COALESCE(p.place, '')))", 'spend_key' => null],
        'country' => ['key' => 't.country', 'label' => 't.country', 'context' => "''", 'publication_key' => null, 'spend_key' => null],
        'day' => ['key' => "to_char((t.at AT TIME ZONE :zone)::date, 'YYYY-MM-DD')", 'label' => "to_char((t.at AT TIME ZONE :zone)::date, 'YYYY-MM-DD')", 'context' => "''",
            'publication_key' => "to_char(" . self::PUBLISHED_DAY . ", 'YYYY-MM-DD')", 'spend_key' => "to_char(s.spent_on, 'YYYY-MM-DD')"],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly MarketingProject $project = new NoProject(),
        private readonly MarketingSettings $settings = new MarketingSettings(),
        private readonly ?Installation $installation = null,
    ) {
    }

    /** The figures of a period, for one campaign (by its id) or all. */
    public function totals(Period $period, ?int $campaignId = null): FigureTotals
    {
        [$where, $params] = $this->touchWhere($period, $campaignId);
        $sql = 'SELECT ' . self::COUNTS . ", COUNT(*) FILTER (WHERE t.agent = 'preview') AS previews, COUNT(*) FILTER (WHERE t.agent IN ('search', 'ai', 'bot')) AS other_set_aside,
                    COUNT(*) FILTER (WHERE t.not_counted_because IN ('draft', 'paused', 'archived', 'not_started', 'ended')) AS not_running,
                    COUNT(*) FILTER (WHERE t.not_counted_because = 'same_visit') AS same_visit
               FROM " . Touches::TABLE . ' t WHERE ' . $where;
        $row = $this->connection->fetchAssociative($sql, $this->used($sql, $params)) ?: [];
        $spend = 0;
        foreach ($this->spendRows('campaign', $period, $campaignId) as $item) {
            $spend += $item['spend'];
        }

        return new FigureTotals(Rows::int($row['clicks'] ?? 0), Rows::int($row['unique_visitors'] ?? 0), Rows::int($row['repeat_visits'] ?? 0), Rows::int($row['previews'] ?? 0),
            Rows::int($row['other_set_aside'] ?? 0), Rows::int($row['not_running'] ?? 0), Rows::int($row['same_visit'] ?? 0), $spend);
    }

    /**
     * A comparison (BREAKDOWNS: campaign, channel, publication, advert, place, country or day) for a period, for one
     * campaign or all: one row for each, the most clicks first (a comparison by day in date order, every day filled in).
     *
     * @return list<FigureRow>
     */
    public function breakdown(string $by, Period $period, ?int $campaignId = null): array
    {
        if (!isset(self::DIMENSIONS[$by])) {
            throw new \InvalidArgumentException('The figures compare by campaign, channel, publication, advert, place, country or day.');
        }
        $dimension = self::DIMENSIONS[$by];
        [$where, $params] = $this->touchWhere($period, $campaignId);
        $sql = 'SELECT ' . $dimension['key'] . ' AS key, MAX(' . $dimension['label'] . ') AS label, MAX(' . $dimension['context'] . ') AS context, ' . self::COUNTS
            . ' FROM ' . Touches::TABLE . ' t' . self::TOUCH_JOINS . ' WHERE ' . $where . ' GROUP BY 1';
        $rows = [];
        foreach ($this->connection->fetchAllAssociative($sql, $this->used($sql, $params)) as $row) {
            $key = Rows::text($row['key']);
            $rows[$key] = new FigureRow($key, Rows::text($row['label']), Rows::text($row['context']), Rows::int($row['clicks']), Rows::int($row['unique_visitors']),
                Rows::int($row['repeat_visits']), Rows::int($row['set_aside']));
        }
        foreach ($this->spendRows($by, $period, $campaignId) as $key => $item) {
            $rows[$key] = ($rows[$key] ?? new FigureRow($key, $item['label'], $item['context']))->withSpend(($rows[$key]->spendMinor ?? 0) + $item['spend']);
        }
        $rows = array_map(fn(FigureRow $row): FigureRow => $this->worded($by, $row), $rows);
        if ($by === 'day') {
            return $this->everyDay($rows, $period);
        }
        $rows = array_values($rows);
        usort($rows, static fn(FigureRow $a, FigureRow $b): int => [$b->clicks, $b->spendMinor, mb_strtolower($a->label)] <=> [$a->clicks, $a->spendMinor, mb_strtolower($b->label)]);

        return $rows;
    }

    /** @return list<FigureRow> */
    public function byCampaign(Period $period, ?int $campaignId = null): array
    {
        return $this->breakdown('campaign', $period, $campaignId);
    }

    /** @return list<FigureRow> */
    public function byChannel(Period $period, ?int $campaignId = null): array
    {
        return $this->breakdown('channel', $period, $campaignId);
    }

    /** @return list<FigureRow> */
    public function byPublication(Period $period, ?int $campaignId = null): array
    {
        return $this->breakdown('publication', $period, $campaignId);
    }

    /** @return list<FigureRow> */
    public function byAdvert(Period $period, ?int $campaignId = null): array
    {
        return $this->breakdown('advert', $period, $campaignId);
    }

    /** @return list<FigureRow> */
    public function byPlace(Period $period, ?int $campaignId = null): array
    {
        return $this->breakdown('place', $period, $campaignId);
    }

    /** @return list<FigureRow> */
    public function byCountry(Period $period, ?int $campaignId = null): array
    {
        return $this->breakdown('country', $period, $campaignId);
    }

    /** @return list<FigureRow> */
    public function byDay(Period $period, ?int $campaignId = null): array
    {
        return $this->breakdown('day', $period, $campaignId);
    }

    /**
     * What needs the owner's attention now: an active campaign ending soon, a campaign over its budget, an active
     * campaign with no publication, and a publication of an active campaign with no click a while after it was published.
     *
     * @return list<Attention>
     */
    public function needsAttention(DateTimeImmutable $now): array
    {
        $zone = $this->zone();
        $now = $now->setTimezone($zone);
        $found = [];
        $ending = $this->connection->fetchAllAssociative("SELECT code, name, ends_at FROM " . Campaigns::TABLE . " WHERE status = 'active' AND ends_at > :now AND ends_at <= :soon ORDER BY ends_at",
            ['now' => Rows::sql($now), 'soon' => Rows::sql($this->settings->attentionEnding()->after($now))]);
        foreach ($ending as $row) {
            $found[] = new Attention(Attention::ENDING, Rows::text($row['code']), Rows::text($row['name']), 'Ends on ' . Rows::instant($row['ends_at'], $zone)?->format('j M Y') . '.');
        }
        $over = $this->connection->fetchAllAssociative(
            'SELECT c.code, c.name, c.budget_minor, (SELECT COALESCE(SUM(p.cost_minor), 0) FROM ' . Publications::TABLE . ' p WHERE p.campaign_id = c.id)
                + (SELECT COALESCE(SUM(s.amount_minor), 0) FROM ' . Spend::TABLE . ' s WHERE s.campaign_id = c.id) AS spend
               FROM ' . Campaigns::TABLE . " c WHERE c.status <> 'archived' AND c.budget_minor IS NOT NULL ORDER BY c.name",
        );
        foreach ($over as $row) {
            if (Rows::int($row['spend']) > Rows::int($row['budget_minor'])) {
                $found[] = new Attention(Attention::OVER_BUDGET, Rows::text($row['code']), Rows::text($row['name']),
                    'Over its budget: ' . $this->project->money(Rows::int($row['spend'])) . ' spent of ' . $this->project->money(Rows::int($row['budget_minor'])) . '.');
            }
        }
        $bare = $this->connection->fetchAllAssociative('SELECT c.code, c.name FROM ' . Campaigns::TABLE . " c WHERE c.status = 'active' AND (c.ends_at IS NULL OR c.ends_at > :now)
            AND NOT EXISTS (SELECT 1 FROM " . Publications::TABLE . ' p WHERE p.campaign_id = c.id AND p.retired_at IS NULL) ORDER BY c.name', ['now' => Rows::sql($now)]);
        foreach ($bare as $row) {
            $found[] = new Attention(Attention::NO_PUBLICATION, Rows::text($row['code']), Rows::text($row['name']), 'Active with no publication: add where it was published.');
        }
        $quiet = $this->connection->fetchAllAssociative(
            'SELECT c.code, c.name, p.code AS publication, ' . self::PUBLISHED_DAY . ' AS day FROM ' . Publications::TABLE . ' p JOIN ' . Campaigns::TABLE . " c ON c.id = p.campaign_id
              WHERE c.status = 'active' AND (c.ends_at IS NULL OR c.ends_at > :now) AND p.retired_at IS NULL AND " . self::PUBLISHED_DAY . ' <= :quiet
                AND NOT EXISTS (SELECT 1 FROM ' . Touches::TABLE . ' t WHERE t.publication_id = p.id AND t.counted) ORDER BY c.name, p.code',
            ['now' => Rows::sql($now), 'zone' => $zone->getName(), 'quiet' => $this->settings->attentionNoClicks()->before($now)->format('Y-m-d')],
        );
        foreach ($quiet as $row) {
            $found[] = new Attention(Attention::NO_CLICKS, Rows::text($row['code']), Rows::text($row['name']),
                'No click since it was published on ' . Rows::date($row['day'], $zone)?->format('j M Y') . '.', Rows::text($row['publication']));
        }

        return $found;
    }

    /**
     * The costs of each row of a comparison in a period: publications' costs on the day they were published, other costs
     * on their day, each where the comparison takes them.
     *
     * @return array<string,array{label:string,context:string,spend:int}>
     */
    private function spendRows(string $by, Period $period, ?int $campaignId): array
    {
        $dimension = self::DIMENSIONS[$by];
        [$from, $to] = $this->dateBounds($period);
        $rows = [];
        if ($dimension['publication_key'] !== null) {
            $conditions = ['TRUE'];
            $params = ['zone' => $this->zone()->getName()];
            $this->dateConditions(self::PUBLISHED_DAY, $from, $to, $conditions, $params);
            if ($campaignId !== null) {
                $conditions[] = 'p.campaign_id = :campaign';
                $params['campaign'] = $campaignId;
            }
            $label = $by === 'day' ? $dimension['publication_key'] : $dimension['label'];
            $sql = 'SELECT ' . $dimension['publication_key'] . ' AS key, MAX(' . $label . ') AS label, MAX(' . ($by === 'publication' ? $dimension['context'] : "''") . ') AS context,
                    SUM(p.cost_minor) AS spend FROM ' . Publications::TABLE . ' p' . self::PUBLICATION_JOINS . ' WHERE ' . implode(' AND ', $conditions) . ' GROUP BY 1';
            foreach ($this->connection->fetchAllAssociative($sql, $this->used($sql, $params)) as $row) {
                $rows[Rows::text($row['key'])] = ['label' => Rows::text($row['label']), 'context' => Rows::text($row['context']), 'spend' => Rows::int($row['spend'])];
            }
        }
        if ($dimension['spend_key'] !== null) {
            $conditions = ['TRUE'];
            $params = [];
            $this->dateConditions('s.spent_on', $from, $to, $conditions, $params);
            if ($campaignId !== null) {
                $conditions[] = 's.campaign_id = :campaign';
                $params['campaign'] = $campaignId;
            }
            $label = $by === 'campaign' ? 'c.name' : $dimension['spend_key'];
            $sql = 'SELECT ' . $dimension['spend_key'] . ' AS key, MAX(' . $label . ') AS label, SUM(s.amount_minor) AS spend FROM ' . Spend::TABLE . ' s' . self::SPEND_JOINS
                . ' WHERE ' . implode(' AND ', $conditions) . ' GROUP BY 1';
            foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
                $key = Rows::text($row['key']);
                $rows[$key] = ['label' => $rows[$key]['label'] ?? Rows::text($row['label']), 'context' => $rows[$key]['context'] ?? '', 'spend' => ($rows[$key]['spend'] ?? 0) + Rows::int($row['spend'])];
            }
        }

        return $rows;
    }

    /** A row's label in words where the records leave it empty or as a code (a country, a day, the campaign's own link). */
    private function worded(string $by, FigureRow $row): FigureRow
    {
        $label = match ($by) {
            'channel' => $row->label !== '' ? $row->label : 'No channel',
            'publication' => $row->label !== '' ? $row->label : 'The campaign\'s own link',
            'advert' => $row->label !== '' ? $row->label : 'No advert',
            'place' => $row->label !== '' ? $row->label : 'No place given',
            'country' => $row->key !== '' ? Countries::name($row->key) : 'Unknown',
            'day' => ($day = DateTimeImmutable::createFromFormat('!Y-m-d', $row->key)) !== false ? $day->format('j M Y') : $row->key,
            default => $row->label,
        };

        return new FigureRow($row->key, $label, $row->context, $row->clicks, $row->uniqueVisitors, $row->repeatVisits, $row->setAside, $row->spendMinor);
    }

    /**
     * Every day of a comparison by day, in order: the period's days when both its ends are given, else those from the
     * first day with figures to the last.
     *
     * @param array<string,FigureRow> $rows
     * @return list<FigureRow>
     */
    private function everyDay(array $rows, Period $period): array
    {
        $zone = $this->zone();
        $keys = array_keys($rows);
        sort($keys);
        if ($period->from !== null && $period->to !== null) {
            $first = $period->from->setTimezone($zone)->setTime(0, 0);
            $last = $period->to->setTimezone($zone)->modify('-1 second')->setTime(0, 0);
        } elseif ($keys !== []) {
            $first = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $keys[0], $zone);
            $last = DateTimeImmutable::createFromFormat('!Y-m-d', (string) end($keys), $zone);
            if ($first === false || $last === false) {
                return array_values($rows);
            }
        } else {
            return [];
        }
        $days = [];
        for ($day = $first, $n = 0; $day <= $last && $n < self::LONGEST_DAYS; $day = $day->modify('+1 day'), $n++) {
            $key = $day->format('Y-m-d');
            $days[] = $rows[$key] ?? new FigureRow($key, $day->format('j M Y'));
        }

        return $days;
    }

    /** @return array{0:string,1:array<string,mixed>} the openings of a period, for one campaign or all */
    private function touchWhere(Period $period, ?int $campaignId): array
    {
        $conditions = ['TRUE'];
        $params = ['zone' => $this->zone()->getName()];
        if ($period->from !== null) {
            $conditions[] = 't.at >= :from';
            $params['from'] = Rows::sql($period->from);
        }
        if ($period->to !== null) {
            $conditions[] = 't.at < :to';
            $params['to'] = Rows::sql($period->to);
        }
        if ($campaignId !== null) {
            $conditions[] = 't.campaign_id = :campaign';
            $params['campaign'] = $campaignId;
        }

        return [implode(' AND ', $conditions), $params];
    }

    /** @return array{0:?string,1:?string} the period's first day and the day after its last, in the installation's time zone */
    private function dateBounds(Period $period): array
    {
        $zone = $this->zone();
        $from = $period->from?->setTimezone($zone)->format('Y-m-d');
        $to = null;
        if ($period->to !== null) {
            $end = $period->to->setTimezone($zone);
            $to = $end->format('H:i:s') === '00:00:00' ? $end->format('Y-m-d') : $end->modify('+1 day')->format('Y-m-d');
        }

        return [$from, $to];
    }

    /**
     * @param list<string> $conditions
     * @param array<string,mixed> $params
     */
    private function dateConditions(string $day, ?string $from, ?string $to, array &$conditions, array &$params): void
    {
        if ($from !== null) {
            $conditions[] = $day . ' >= CAST(:from_day AS date)';
            $params['from_day'] = $from;
        }
        if ($to !== null) {
            $conditions[] = $day . ' < CAST(:to_day AS date)';
            $params['to_day'] = $to;
        }
    }

    /**
     * The parameters a statement names, so none is given that it does not use.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function used(string $sql, array $params): array
    {
        return array_filter($params, static fn(string $name): bool => preg_match('/:' . preg_quote($name, '/') . '\b/', $sql) === 1, ARRAY_FILTER_USE_KEY);
    }

    private function zone(): DateTimeZone
    {
        return $this->installation?->timeZone() ?? new DateTimeZone('UTC');
    }
}
