<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Http\CsvDownload;
use CattoPHP\Http\LiveRefresh;
use CattoPHP\Http\StockPages;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\Attention;
use CattoPHP\Marketing\CampaignFigures;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\FigureRow;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Period;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\Sales;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Campaign Marketing Manager's overview (`config/routes/marketing.php`, /marketing): what needs attention, then for a
 * period (today, 7 or 30 days, this month, last month, this year, all, or two dates) the totals, a comparison by
 * campaign, channel, publication, advert or place (sortable, with Download as CSV), clicks over time and where the clicks
 * came from, and the latest sales (those paid within MARKETING_LATEST_SALES, counted from the start of that day, with
 * All sales opening the Sales page for the same days). It refreshes itself every 30 seconds while it is open. Seeing it
 * needs MARKETING.VIEW; a customer's name, MARKETING.PEOPLE.VIEW too.
 */
final class OverviewController
{
    /** The comparisons the overview offers, each in words and the icon of its card. */
    public const BY = [
        'campaign' => ['Campaign', 'megaphone'],
        'channel' => ['Channel', 'radio'],
        'publication' => ['Publication', 'link'],
        'advert' => ['Advert', 'image'],
        'place' => ['Place', 'map-pin'],
    ];

    /** What the comparison may be sorted by. */
    public const SORTS = ['label', 'clicks', 'unique_visitors', 'repeat_visits', 'spend'];

    /** How many sales Latest sales shows. */
    private const LATEST = 10;

    /** A comparison by day becomes one by month past this many days, so a year still reads as columns. */
    private const MOST_DAYS = 92;

    /** Whether the chart just drawn has a column for each month, a long period's. */
    private bool $monthly = false;

    /** @var array<string,?string> each advert's campaign code, by the advert's reference, as looked up */
    private array $advertCampaigns = [];

    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly CampaignFigures $figures,
        private readonly Campaigns $campaigns,
        private readonly Adverts $adverts,
        private readonly ?Sales $sales = null,
        private readonly ?SaleWords $saleWords = null,
        private readonly MarketingSettings $settings = new MarketingSettings(),
    ) {
    }

    public function show(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        LiveRefresh::notAVisit($request);
        $period = $this->marketing->period($request);
        $by = $this->by($request);
        [$sort, $direction] = self::sort($request);
        $totals = $this->figures->totals($period['period']);
        $query = MarketingPages::periodQuery($period);
        $base = $this->pages->path('cattophp_marketing_overview');
        $rows = [];
        foreach ($this->rows($by, $period['period'], $sort, $direction) as $row) {
            $rows[] = [
                'label' => $row->label,
                'context' => $row->context,
                'href' => $this->rowHref($by, $row),
                'clicks' => number_format($row->clicks),
                'unique_visitors' => number_format($row->uniqueVisitors),
                'repeat_visits' => number_format($row->repeatVisits),
                'spend' => $this->marketing->money($row->spendMinor),
            ];
        }
        $headers = [];
        foreach (['label' => self::BY[$by][0], 'clicks' => 'Clicks', 'unique_visitors' => 'Unique visitors', 'repeat_visits' => 'Repeat visits', 'spend' => 'Spend'] as $key => $label) {
            $next = $key === $sort ? ($direction === 'asc' ? 'desc' : 'asc') : ($key === 'label' ? 'asc' : 'desc');
            $headers[] = ['sortable' => true, 'key' => $key, 'label' => $label, 'width' => 0, 'end' => $key !== 'label', 'active' => $key === $sort, 'direction' => $direction,
                'href' => $base . '?' . http_build_query($query + ['by' => $by, 'figures_sort' => $key, 'figures_dir' => $next]) . '#figures'];
        }
        $countries = [];
        foreach ($this->figures->byCountry($period['period']) as $row) {
            if ($row->clicks > 0) {
                $countries[] = ['label' => $row->label, 'value' => $row->clicks, 'words' => MarketingPages::count($row->clicks, 'click', 'clicks'),
                    'note' => MarketingPages::count($row->uniqueVisitors, 'unique visitor', 'unique visitors')];
            }
        }
        $setAside = $totals->setAside();

        return $this->pages->page($request, 'marketing-overview', '@CattoPHP/marketing/overview.html.twig', [
            'title' => 'Campaign Marketing Manager',
            'lead' => 'Which campaigns, channels, adverts and places bring people to the site, and what they cost.',
            'may_manage' => $this->pages->may(MarketingPermissions::MANAGE),
            'attention' => array_map($this->attention(...), $this->figures->needsAttention($this->marketing->now())),
            'period' => $period['name'],
            'period_words' => $period['words'],
            'period_problem' => $period['problem'],
            'from' => $period['from'],
            'to' => $period['to'],
            'period_cards' => $this->marketing->periodCards(),
            'by' => $by,
            'by_cards' => $this->byCards(),
            'totals' => [
                ['label' => 'Clicks', 'value' => number_format($totals->clicks), 'note' => 'Tracking links followed by people'],
                ['label' => 'Unique visitors', 'value' => number_format($totals->uniqueVisitors), 'note' => 'Each person counted once'],
                ['label' => 'Repeat visits', 'value' => number_format($totals->repeatVisits), 'note' => 'Clicks by people who had come before'],
                ['label' => 'Link previews set aside', 'value' => number_format($totals->linkPreviews),
                    'note' => $setAside === $totals->linkPreviews ? 'Not clicks: made when a link is pasted' : 'And ' . MarketingPages::count($totals->otherSetAside, 'opening', 'openings') . ' by search engines and other robots'],
                ['label' => 'Spend', 'value' => $this->marketing->money($totals->spendMinor), 'note' => 'Publications\' costs and other costs'],
            ],
            'not_counted' => self::notCounted($totals->notRunning, $totals->sameVisit),
            'rows' => $rows,
            'headers' => $headers,
            'csv_href' => $this->pages->path('cattophp_marketing_overview_csv', $query + ['by' => $by, 'figures_sort' => $sort, 'figures_dir' => $direction]),
            'base' => $base,
            'columns' => $this->columns($period['period']),
            'chart_heading' => $this->monthly ? 'Month' : 'Day',
            'countries' => $countries,
        ] + $this->latest());
    }

    /**
     * Latest sales: those paid since the start of the day MARKETING_LATEST_SALES ago, the newest first, each with who,
     * what, the amount, the campaign it counts for and what it came through; All sales opens the Sales page for the
     * same days, so its list begins with the same sales.
     *
     * @return array<string,mixed>
     */
    private function latest(): array
    {
        $words = $this->settings->latestSales()->words();
        $since = $this->settings->latestSales()->before($this->marketing->now())->setTimezone($this->campaigns->zone())->setTime(0, 0);
        if ($this->sales === null || $this->saleWords === null) {
            return ['latest' => [], 'latest_words' => $words, 'latest_href' => ''];
        }
        $model = $this->saleWords->model(null);

        return [
            'latest' => array_map(fn (Sale $sale): array => $this->saleWords->row($sale, $model), $this->sales->latest($since, self::LATEST)),
            'latest_words' => $words,
            'latest_href' => $this->pages->path('cattophp_marketing_sales', ['period' => 'dates', 'from' => $since->format('Y-m-d')]),
        ];
    }

    /** The comparison shown, as a CSV file: the same period, comparison and order. */
    public function download(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $period = $this->marketing->period($request);
        $by = $this->by($request);
        [$sort, $direction] = self::sort($request);
        $rows = [];
        foreach ($this->rows($by, $period['period'], $sort, $direction) as $row) {
            $rows[] = [$row->label, $row->context, $row->clicks, $row->uniqueVisitors, $row->repeatVisits, $row->setAside, MarketingPages::decimal($row->spendMinor)];
        }

        return new CsvDownload('marketing-' . $by . 's', [self::BY[$by][0], 'Belongs to', 'Clicks', 'Unique visitors', 'Repeat visits', 'Set aside', 'Spend'], $rows, $this->marketing->now());
    }

    private function by(Request $request): string
    {
        $by = MarketingPages::text($request->query->get('by', 'campaign'));

        return isset(self::BY[$by]) ? $by : 'campaign';
    }

    /** @return array{0:string,1:string} */
    private static function sort(Request $request): array
    {
        $sort = MarketingPages::text($request->query->get('figures_sort', 'clicks'));
        $direction = MarketingPages::text($request->query->get('figures_dir', ''));
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'clicks';

        return [$sort, in_array($direction, ['asc', 'desc'], true) ? $direction : ($sort === 'label' ? 'asc' : 'desc')];
    }

    /** @return list<FigureRow> */
    private function rows(string $by, Period $period, string $sort, string $direction): array
    {
        $rows = $this->figures->breakdown($by, $period);
        usort($rows, static function (FigureRow $a, FigureRow $b) use ($sort): int {
            $order = match ($sort) {
                'label' => strnatcasecmp($a->label, $b->label),
                'unique_visitors' => $a->uniqueVisitors <=> $b->uniqueVisitors,
                'repeat_visits' => $a->repeatVisits <=> $b->repeatVisits,
                'spend' => $a->spendMinor <=> $b->spendMinor,
                default => $a->clicks <=> $b->clicks,
            };

            return $order !== 0 ? $order : strnatcasecmp($a->label, $b->label);
        });

        return $direction === 'desc' ? array_reverse($rows) : $rows;
    }

    /** Where a row of the comparison opens: its campaign, publication or advert's page, or nowhere. */
    private function rowHref(string $by, FigureRow $row): string
    {
        if ($row->key === '') {
            return '';
        }
        if ($by === 'campaign') {
            return $this->pages->path('cattophp_marketing_campaign', ['code' => $row->key]);
        }
        if ($by === 'publication' && preg_match('#^([^/]+)/([a-z0-9]+)$#', $row->key, $parts) === 1) {
            return $this->pages->path('cattophp_marketing_publication', ['code' => $parts[1], 'publication' => $parts[2]]);
        }
        if ($by === 'advert') {
            if (!array_key_exists($row->key, $this->advertCampaigns)) {
                $advert = $this->adverts->get($row->key);
                $this->advertCampaigns[$row->key] = $advert === null ? null : $this->campaigns->byId($advert->campaignId)?->code;
            }
            $code = $this->advertCampaigns[$row->key];

            return $code === null ? '' : $this->pages->path('cattophp_marketing_advert', ['code' => $code, 'reference' => $row->key]);
        }

        return '';
    }

    /** @return array<string,string> */
    private function attention(Attention $attention): array
    {
        $href = $attention->publicationCode !== null
            ? $this->pages->path('cattophp_marketing_publication', ['code' => $attention->campaignCode, 'publication' => $attention->publicationCode])
            : $this->pages->path('cattophp_marketing_campaign', ['code' => $attention->campaignCode]);

        return [
            'kind' => match ($attention->kind) {
                Attention::ENDING => 'Ending soon',
                Attention::OVER_BUDGET => 'Over budget',
                Attention::NO_PUBLICATION => 'No publication',
                default => 'No clicks',
            },
            'tone' => $attention->kind === Attention::OVER_BUDGET ? 'danger' : 'warning',
            'name' => $attention->campaignName . ($attention->publicationCode !== null ? ', ' . $attention->publicationCode : ''),
            'words' => $attention->words,
            'href' => $href,
            'action' => match ($attention->kind) {
                Attention::NO_PUBLICATION => 'Add a publication',
                Attention::NO_CLICKS => 'Open the publication',
                default => 'Open the campaign',
            },
            'action_href' => $attention->kind === Attention::NO_PUBLICATION ? $this->pages->path('cattophp_marketing_publication_new', ['code' => $attention->campaignCode]) : $href,
        ];
    }

    /** @return list<array{value:string,label:string,icon:string}> */
    private function byCards(): array
    {
        $cards = [];
        foreach (self::BY as $value => [$label, $icon]) {
            $cards[] = ['value' => $value, 'label' => $label, 'icon' => $this->marketing->icon($icon)];
        }

        return $cards;
    }

    /**
     * Clicks over the period as columns: a day each, or a month each past three months.
     *
     * @return list<array{label:string,value:int,words:string}>
     */
    private function columns(Period $period): array
    {
        $days = $this->figures->byDay($period);
        $this->monthly = count($days) > self::MOST_DAYS;
        if (!$this->monthly) {
            return array_map(static fn (FigureRow $day): array => ['label' => $day->label, 'value' => $day->clicks, 'words' => MarketingPages::count($day->clicks, 'click', 'clicks')], $days);
        }
        $months = [];
        foreach ($days as $day) {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d', $day->key);
            $month = $at === false ? $day->key : $at->format('M Y');
            $months[$month] = ($months[$month] ?? 0) + $day->clicks;
        }
        $columns = [];
        foreach ($months as $month => $clicks) {
            $columns[] = ['label' => (string) $month, 'value' => $clicks, 'words' => MarketingPages::count($clicks, 'click', 'clicks')];
        }

        return $columns;
    }

    private static function notCounted(int $notRunning, int $sameVisit): string
    {
        $parts = [];
        if ($notRunning > 0) {
            $parts[] = MarketingPages::count($notRunning, 'opening', 'openings') . ' while its campaign was not running';
        }
        if ($sameVisit > 0) {
            $parts[] = MarketingPages::count($sameVisit, 'opening', 'openings') . ' again within the same visit';
        }

        return $parts === [] ? '' : 'Not counted as clicks: ' . implode(', and ', $parts) . '.';
    }
}
