<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Country\Countries;
use CattoPHP\Foundation\Duration;
use CattoPHP\Http\CsvDownload;
use CattoPHP\Http\LiveRefresh;
use CattoPHP\Http\StockPages;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\JourneyLine;
use CattoPHP\Marketing\Journey\PersonJourney;
use CattoPHP\Marketing\Journey\PersonJourneys;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Visitor;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Ui\Pager;
use CattoPHP\Ui\Pagination;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The visitors (`config/routes/marketing.php`): the list, one row for each person with all their visitors together and
 * one for each visitor nobody knows yet, with the period they were last seen in, their state as feature cards, a
 * campaign, a country and a search by name, email address, account reference or a visitor's reference, its totals,
 * sort, pages and Download as CSV; and one visitor's page, holding their journey oldest first (with Download as CSV),
 * which campaign gets each of their paid sales, who they are, the other visitors, and Forget this visitor's journey.
 * Seeing them needs MARKETING.PEOPLE.VIEW; forgetting a journey MARKETING.CAMPAIGN.MANAGE too. A visitor made
 * anonymous, or a reference nobody has, is simply not found.
 */
final class VisitorsController
{
    /** The list's name: its search (visitors_q), pager (visitors_page) and sort (visitors_sort, visitors_dir). */
    public const LIST = 'visitors';

    /** The list's columns: what each sorts by (none for a column that cannot be), and its heading. */
    private const COLUMNS = ['visitor' => ['', 'Visitor'], 'state' => ['', 'State'], 'visits' => ['visits', 'Visits'], 'campaigns' => ['campaigns', 'Campaigns'],
        'country' => ['', 'Country'], 'devices' => ['', 'Devices'], 'first_seen' => ['first_seen', 'First seen'], 'last_seen' => ['last_seen', 'Last seen']];

    /** How many other visitors a visitor's page shows. */
    private const OTHERS = 5;

    /** The tone of each badge of a journey's line. */
    private const BADGE_TONES = [JourneyLine::FIRST_TOUCH => 'info', JourneyLine::LAST_TOUCH => 'info', JourneyLine::REGISTRATION => 'success', JourneyLine::FAILED => 'danger',
        JourneyLine::SALE => 'success'];

    /** The tone of each state of a person, for its badge. */
    private const STATE_TONES = [PersonJourney::PAYING => 'success', PersonJourney::WAITING => 'warning', PersonJourney::REGISTERED => 'info', PersonJourney::NOT_REGISTERED => 'neutral'];

    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly SaleWords $words,
        private readonly VisitorList $list,
        private readonly Visitors $visitors,
        private readonly PersonJourneys $journeys,
        private readonly Campaigns $campaigns,
        private readonly Events $events,
        private readonly Sales $sales,
        private readonly MarketingSettings $settings,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    public function list(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::PEOPLE);
        LiveRefresh::notAVisit($request);
        $query = $this->listQuery($request);
        $filter = $query['filter'];
        $total = $this->list->count($filter);
        $pagination = Pagination::create($request->query->get(self::LIST . '_page'), $request->query->get(self::LIST . '_page_size'), $total);
        $base = $this->pages->path('cattophp_marketing_visitors');
        $kept = $query['kept'];
        $counts = $this->list->stateCounts($filter);
        $cards = [];
        foreach (VisitorFilter::STATES as $value => [$label, $icon]) {
            $cards[] = ['value' => $value, 'label' => $label, 'icon' => $this->marketing->icon($icon), 'note' => $counts[$value] === 0 ? 'None' : MarketingPages::count($counts[$value], 'visitor', 'visitors')];
        }
        $headers = [];
        foreach (self::COLUMNS as $key => [$sorts, $label]) {
            $next = $sorts === $query['sort'] ? ($query['direction'] === 'asc' ? 'desc' : 'asc') : 'desc';
            $headers[] = ['sortable' => $sorts !== '', 'key' => $key, 'label' => $label, 'width' => 0, 'end' => in_array($key, ['visits', 'campaigns', 'first_seen', 'last_seen'], true),
                'active' => $sorts !== '' && $sorts === $query['sort'], 'direction' => $query['direction'],
                'href' => $sorts === '' ? '' : $base . '?' . http_build_query(array_filter([self::LIST . '_sort' => $sorts, self::LIST . '_dir' => $next] + $kept, static fn (string $value): bool => $value !== ''))];
        }
        $totals = $this->list->totals($filter);
        $sorted = [self::LIST . '_sort' => $query['sort'], self::LIST . '_dir' => $query['direction']];

        return $this->pages->page($request, 'marketing-visitors', '@CattoPHP/marketing/visitors.html.twig', [
            'title' => 'Visitors',
            'lead' => 'Everyone who came through a campaign\'s link: a person once, with all their devices together, and each visitor not known by name yet. A name opens their journey.',
            'kicker' => 'Campaign Marketing Manager',
            'period' => $query['period']['name'],
            'period_words' => $query['period']['words'],
            'period_problem' => $query['period']['problem'],
            'from' => $query['period']['from'],
            'to' => $query['period']['to'],
            'period_cards' => $this->marketing->periodCards(),
            'state' => $filter->state,
            'state_cards' => $cards,
            'campaign' => $query['campaign'],
            'campaign_options' => $this->campaignOptions(),
            'country' => $filter->country ?? '',
            'country_options' => Countries::options(),
            'searched' => $filter->search,
            'sort' => $query['sort'],
            'direction' => $query['direction'],
            'kept' => $kept,
            'total' => $total,
            'any' => $this->visitors->count() > 0,
            'rows' => array_map($this->row(...), $this->list->list($filter, $pagination->pageSize, $pagination->offset, $query['sort'], $query['direction'])),
            'headers' => $headers,
            'totals' => [
                ['label' => 'Visitors', 'value' => number_format($totals['visitors']), 'note' => 'Each person once, on ' . MarketingPages::count($totals['devices'], 'device', 'devices')],
                ['label' => 'Known people', 'value' => number_format($totals['known']), 'note' => 'Registered or signed in'],
                ['label' => 'Paying customers', 'value' => number_format($totals['paying']), 'note' => 'Who paid for an order'],
            ],
            'search' => ['ds_name' => self::LIST, 'ds_label' => 'Search the visitors', 'ds_action' => $base, 'ds_value' => $filter->search, 'ds_push_url' => true,
                'ds_hidden' => array_filter($sorted + array_diff_key($kept, [self::LIST . '_q' => true]), static fn (string $value): bool => $value !== ''),
                'ds_help' => 'A name, an email address, an account reference, or a visitor\'s reference such as 7k2m9q4d.'],
            'pagination' => Pager::payload($pagination, self::LIST, $base, 'Visitors', $kept + $sorted),
            'base' => $base,
            'csv_href' => $this->pages->path('cattophp_marketing_visitors_csv', array_filter($kept + $sorted, static fn (string $value): bool => $value !== '')),
        ]);
    }

    /** The visitors the list shows (its filters and order, every page), and their totals, as a CSV file. */
    public function download(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::PEOPLE);
        $query = $this->listQuery($request);
        $filter = $query['filter'];
        $rows = [];
        foreach ($this->list->list($filter, max(1, $this->list->count($filter)), 0, $query['sort'], $query['direction']) as $row) {
            $shown = $this->row($row);
            $rows[] = [$shown['name'], $row->reference, $shown['state'], $row->visits, $row->campaigns, $shown['country'], $shown['devices'], $row->firstSeenAt, $row->lastSeenAt];
        }
        $totals = $this->list->totals($filter);
        $rows[] = [];
        foreach (['Visitors' => $totals['visitors'], 'Known people' => $totals['known'], 'Paying customers' => $totals['paying']] as $label => $value) {
            $rows[] = [$label, $value];
        }

        return new CsvDownload('marketing-visitors', ['Visitor', 'Reference', 'State', 'Visits', 'Campaigns', 'Country', 'Devices', 'First seen', 'Last seen'], $rows, $this->marketing->now());
    }

    public function show(Request $request, string $reference): Response
    {
        $this->pages->deny(MarketingPermissions::PEOPLE);
        $visitor = $this->visitor($reference);
        $journey = $this->journeys->of($visitor);
        $name = $this->name($journey);
        $person = $journey->account === null ? null : $this->words->account($journey->account);
        $sales = [];
        foreach ($journey->sales as $sale) {
            $sales[$sale->reference] ??= $sale;
        }
        $paid = array_values(array_filter($journey->sales, static fn (Sale $sale): bool => $sale->isPaid()));
        $registered = null;
        foreach ($journey->lines as $line) {
            if ($line->kind === Events::REGISTERED) {
                $registered ??= $line->at;
            }
        }

        return $this->pages->page($request, 'marketing-visitor', '@CattoPHP/marketing/visitor.html.twig', [
            'title' => $name,
            'lead' => '',
            'kicker' => 'Visitor',
            'reference' => $visitor->reference,
            'state' => $journey->stateWords(),
            'state_tone' => self::STATE_TONES[$journey->state] ?? 'neutral',
            'first_seen' => $this->marketing->day($journey->firstSeenAt()),
            'countries' => implode(', ', array_map(SaleWords::country(...), $journey->countries)),
            'devices' => $journey->devicesWords(),
            'account_href' => $person['href'] ?? '',
            'may_forget' => $this->pages->may(MarketingPermissions::MANAGE),
            'figures' => $this->figures($journey, $sales, $paid),
            'lines' => array_map(fn (JourneyLine $line): array => $this->line($line, $sales), $journey->lines),
            'csv_href' => $this->pages->path('cattophp_marketing_visitor_csv', ['reference' => $visitor->reference]),
            'attributions' => array_map($this->attribution(...), $paid),
            'settings_href' => $this->pages->may(MarketingPermissions::SETTINGS) ? $this->pages->path('cattophp_marketing_settings') . '#settings-marketing' : '',
            'person' => array_values(array_filter([
                ['label' => 'Name', 'value' => $name],
                $person !== null ? ['label' => 'Account reference', 'value' => $person['reference']] : null,
                ['label' => 'Registered', 'value' => $registered !== null ? $this->marketing->day($registered) : ($journey->account !== null ? 'Before they came by a campaign' : 'Not yet')],
                ['label' => 'Country', 'value' => $journey->countries === [] ? 'Not known' : implode(', ', array_map(SaleWords::country(...), $journey->countries))],
                ['label' => 'Devices', 'value' => $this->devicesAnd($journey) ?: 'Not known'],
            ])),
            'ip_words' => 'IP addresses are kept for ' . $this->settings->ipKept()->words() . ' and then removed. The country stays.',
            'others' => $this->others($journey),
            'visitors_href' => $this->pages->path('cattophp_marketing_visitors'),
            'forget_action' => $this->pages->path('cattophp_marketing_visitor_forget', ['reference' => $visitor->reference]),
            'forget_confirm' => 'Forget ' . $name . '\'s journey? Their visitors are made anonymous and their sales no longer name them. The campaigns\' clicks and money stay. This cannot be undone.',
        ]);
    }

    /** One visitor's journey, as the page shows it, as a CSV file. */
    public function downloadJourney(string $reference): Response
    {
        $this->pages->deny(MarketingPermissions::PEOPLE);
        $visitor = $this->visitor($reference);
        $journey = $this->journeys->of($visitor);
        $rows = [];
        foreach ($journey->lines as $line) {
            $rows[] = [$line->at, $line->words, $line->campaignName ?? '', $line->detail, implode(', ', $line->badgeWords())];
        }

        return new CsvDownload('journey-' . $visitor->reference, ['When', 'What happened', 'Campaign', 'Detail', 'Badges'], $rows, $this->marketing->now());
    }

    /**
     * Forget this visitor's journey: every visitor of the person made anonymous, their events and sales no longer naming
     * their account, the clicks, money and totals kept. In the activity log as marketing.visitor.forgotten.
     */
    public function forget(Request $request, string $reference): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::PEOPLE);
        $this->pages->deny(MarketingPermissions::MANAGE);
        $visitor = $this->visitor($reference);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_visitor', ['reference' => $visitor->reference], 'forget');
        }
        $name = $this->name($this->journeys->of($visitor));
        $account = $visitor->account;
        $ids = $this->visitors->anonymise($visitor, $this->marketing->now());
        if ($account !== null) {
            $this->events->forgetAccount($account);
            $this->sales->forgetAccount($account);
        }
        $this->events->anonymiseVisitors();
        $this->sales->forgetAccountsOfAnonymousVisitors();
        $this->activity?->record('marketing.visitor.forgotten', ['visitor' => $visitor->reference, 'visitors' => count($ids)], Visitors::SUBJECT, $visitor->reference, $this->pages->actor());

        return $this->pages->done($request, $name . '\'s journey is forgotten: ' . MarketingPages::count(count($ids), 'visitor is', 'visitors are') . ' made anonymous, and their sales name nobody. The clicks and the money stay in the totals.',
            'cattophp_marketing_visitors');
    }

    /** A visitor by their reference, or not found: none has it, or they were made anonymous. */
    private function visitor(string $reference): Visitor
    {
        $visitor = $this->visitors->byReference($reference);
        if ($visitor === null || $visitor->isAnonymous()) {
            throw new NotFoundHttpException('There is no such visitor.');
        }

        return $visitor;
    }

    /** A person's name as the project gives it, else the visitor's: "Visitor 7k2m9q4d". */
    private function name(PersonJourney $journey): string
    {
        $person = $journey->account === null ? null : $this->words->account($journey->account);

        return $person['label'] ?? $journey->visitor->name();
    }

    /**
     * @param array<string,Sale> $sales the person's sales, by reference
     * @param list<Sale> $paid
     * @return list<array{label:string,value:string,note:string,href:string}>
     */
    private function figures(PersonJourney $journey, array $sales, array $paid): array
    {
        $days = (int) $journey->firstSeenAt()->setTime(0, 0)->diff($journey->lastSeenAt()->setTime(0, 0))->format('%a') + 1;
        $publications = [];
        foreach ($journey->lines as $line) {
            if ($line->from === JourneyLine::FROM_TOUCH && $line->counted === true && $line->publicationCode !== null) {
                $publications[$line->campaignId . '/' . $line->publicationCode] = true;
            }
        }
        $campaign = count($journey->campaignIds) === 1 ? $this->campaigns->byId($journey->campaignIds[0]) : null;
        $orders = array_values($sales);
        $paidMinor = array_sum(array_map(static fn (Sale $sale): int => $sale->paidMinor, $paid));
        $paidNote = 'Nothing paid yet';
        if (count($paid) === 1) {
            $how = '';
            foreach ($paid[0]->movements as $movement) {
                if ($movement->kind === 'paid') {
                    $how = $movement->how;
                }
            }
            $paidNote = ($how !== '' ? 'By ' . MarketingPages::inSentence($how) . ', ' : 'On ') . $this->marketing->day($paid[0]->paidAt);
        } elseif ($paid !== []) {
            $paidNote = MarketingPages::count(count($paid), 'paid order', 'paid orders');
        }

        return [
            ['label' => 'Visits', 'value' => number_format($journey->visits), 'note' => $days === 1 ? 'On one day' : 'Over ' . $days . ' days', 'href' => '#journey'],
            ['label' => 'Campaigns', 'value' => number_format($journey->campaigns()), 'note' => $publications === [] ? 'By their own links' : 'Through ' . MarketingPages::count(count($publications), 'publication', 'publications'),
                'href' => $campaign !== null ? $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code]) : '#journey'],
            ['label' => 'Orders', 'value' => number_format(count($journey->sales)), 'note' => $orders === [] ? 'None yet' : implode(', ', array_slice(array_keys($sales), 0, 3)) . (count($sales) > 3 ? ' and more' : ''),
                'href' => count($orders) === 1 ? $this->words->href($orders[0]) : '#journey'],
            ['label' => 'Paid', 'value' => $this->marketing->money($paidMinor), 'note' => $paidNote, 'href' => count($paid) === 1 ? $this->words->href($paid[0]) : ($paid !== [] ? '#which-campaign' : '#journey')],
        ];
    }

    /**
     * @param array<string,Sale> $sales
     * @return array<string,mixed>
     */
    private function line(JourneyLine $line, array $sales): array
    {
        $sale = $line->saleReference === null ? null : ($sales[$line->saleReference] ?? null);

        return [
            'at' => $this->marketing->moment($line->at),
            'words' => $line->words,
            'href' => $sale !== null ? $this->words->href($sale) : '',
            'campaign' => $line->campaignName ?? '',
            'campaign_href' => $line->campaignCode !== null ? $this->pages->path('cattophp_marketing_campaign', ['code' => $line->campaignCode]) : '',
            'detail' => $line->detail,
            'badges' => array_map(static fn (string $badge): array => ['label' => JourneyLine::BADGES[$badge] ?? $badge, 'tone' => self::BADGE_TONES[$badge] ?? 'neutral'], $line->badges),
        ];
    }

    /**
     * Which campaign gets a paid sale: its last and first touch, whether both fell within the setting as it was, and
     * which of the two the reports count now.
     *
     * @return array<string,mixed>
     */
    private function attribution(Sale $sale): array
    {
        $within = $sale->countedWithin !== null && Duration::isValid($sale->countedWithin) ? Duration::parse($sale->countedWithin)->words() : '';
        $touch = function (string $model) use ($sale): string {
            $campaign = $this->words->campaign($sale, $model);
            if ($campaign['href'] === '') {
                return 'No campaign.';
            }
            $through = $this->words->cameThrough($sale, $model);
            $same = $model === 'first' && $sale->firstCampaignId === $sale->lastCampaignId;

            return ($same ? 'the same campaign' : $campaign['name']) . ($sale->by($model) === 'promotion' ? ', by its promotion code ' . ($sale->promotion ?? '') . '.' : ', through ' . $through . '.');
        };
        $counted = $sale->firstCampaignId !== null || $sale->lastCampaignId !== null;
        $one = $sale->firstTouchId !== null && $sale->firstTouchId === $sale->lastTouchId;
        $amount = $this->words->money($sale, $sale->paidMinor);

        return [
            'reference' => $sale->reference,
            'href' => $this->words->href($sale),
            'last' => $touch('last'),
            'first' => $one ? 'the same click.' : $touch('first'),
            'counted' => $counted,
            'within' => !$counted ? 'No click of theirs fell within ' . ($within !== '' ? $within : 'the time a sale counts for a campaign') . ' before the payment, and no campaign\'s promotion code was used.'
                : ($one ? 'It was' : 'Both were') . ' within ' . ($within !== '' ? $within : 'the time a sale counts for a campaign') . ' of the payment.',
            'once' => !$counted ? 'Its ' . $amount . ' counts for no campaign.' : 'The ' . $amount . ' is counted once, for whichever of the two the reports are set to: the ' . ($this->words->setting() === 'first' ? 'first' : 'last')
                . ' touch now, ' . $this->words->campaign($sale, $this->words->setting())['name'] . '.',
        ];
    }

    /** The devices of a person in words: "A phone and a computer". */
    private function devicesAnd(PersonJourney $journey): string
    {
        return (new VisitorRow('', null, [], $journey->firstSeenAt(), $journey->lastSeenAt(), [], $journey->devices, 0, 0, $journey->state))->devicesWords();
    }

    /** @return list<array<string,mixed>> the latest others, the person themselves left out */
    private function others(PersonJourney $journey): array
    {
        $mine = array_map(static fn (Visitor $visitor): string => $visitor->reference, $journey->visitors);
        $others = [];
        foreach ($this->list->list(new VisitorFilter(), self::OTHERS + 1) as $row) {
            if (count($others) < self::OTHERS && array_intersect($row->references, $mine) === []) {
                $others[] = $this->row($row);
            }
        }

        return $others;
    }

    /** @return array<string,mixed> a row of the list as the page shows it */
    private function row(VisitorRow $row): array
    {
        $person = $row->account === null ? null : $this->words->account($row->account);

        return [
            'name' => $person['label'] ?? 'Visitor ' . $row->reference,
            'href' => $this->pages->path('cattophp_marketing_visitor', ['reference' => $row->reference]),
            'state' => PersonJourney::STATES[$row->state] ?? $row->state,
            'tone' => self::STATE_TONES[$row->state] ?? 'neutral',
            'visits' => number_format($row->visits),
            'campaigns' => number_format($row->campaigns),
            'country' => $row->countries === [] ? 'Not known' : implode(', ', array_map(SaleWords::country(...), $row->countries)),
            'devices' => $row->devicesWords() ?: 'Not known',
            'first_seen' => $this->marketing->day($row->firstSeenAt),
            'last_seen' => $this->marketing->day($row->lastSeenAt),
        ];
    }

    /** @return list<array{value:string,label:string}> every campaign, by name, for the list's choice */
    private function campaignOptions(): array
    {
        $options = [];
        foreach ($this->campaigns->list(null, '', 'name', 'asc', max(1, $this->campaigns->count())) as $summary) {
            $options[] = ['value' => $summary->campaign->code, 'label' => $summary->campaign->name];
        }

        return $options;
    }

    /**
     * The list's filters, sort and direction from the address, with what its links keep.
     *
     * @return array{filter:VisitorFilter,period:array{period:\CattoPHP\Marketing\Period,name:string,from:string,to:string,words:string,problem:string},campaign:string,sort:string,direction:string,kept:array<string,string>}
     */
    private function listQuery(Request $request): array
    {
        $period = $this->marketing->period($request);
        $state = MarketingPages::text($request->query->get('state', 'everyone'));
        $state = isset(VisitorFilter::STATES[$state]) ? $state : 'everyone';
        $code = MarketingPages::text($request->query->get('campaign', ''));
        $campaign = $code === '' ? null : $this->campaigns->get($code);
        $country = strtoupper(MarketingPages::text($request->query->get('country', '')));
        $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
        $search = mb_substr(trim(MarketingPages::text($request->query->get(self::LIST . '_q', ''))), 0, 120);
        $sort = MarketingPages::text($request->query->get(self::LIST . '_sort', 'last_seen'));
        $sort = isset(VisitorList::SORTS[$sort]) ? $sort : 'last_seen';
        $direction = MarketingPages::text($request->query->get(self::LIST . '_dir', ''));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';
        $filter = new VisitorFilter($period['period']->from, $period['period']->to, $state, $campaign?->id, $country, $search);
        $kept = MarketingPages::periodQuery($period) + ['state' => $state === 'everyone' ? '' : $state, 'campaign' => $campaign->code ?? '', 'country' => $country ?? '', self::LIST . '_q' => $search];

        return ['filter' => $filter, 'period' => $period, 'campaign' => $campaign->code ?? '', 'sort' => $sort, 'direction' => $direction, 'kept' => $kept];
    }
}
