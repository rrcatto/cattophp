<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Country\Countries;
use CattoPHP\Foundation\Duration;
use CattoPHP\Http\CsvDownload;
use CattoPHP\Http\LiveRefresh;
use CattoPHP\Http\StockPages;
use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Period;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleMovement;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Sites\SiteReports;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Ui\Pager;
use CattoPHP\Ui\Pagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The sales (`config/routes/marketing.php`): every sale the project reported, whether or not a campaign brought it, and
 * every sale a connected site reported, with the period, its state as feature cards (paid to start), the campaign (any,
 * none or one), the channel, the publication, the offer, the country, which touch a sale counts for and where it was
 * sold, a search by order reference, their totals (from Sales::totals(), under the same filter), sort, pages and two
 * downloads (the sales, and their lines); and one sale's page, holding its lines, its money as it moved, which campaign
 * gets it and what was reported of it to connected sites. Seeing them needs MARKETING.VIEW; who the customers are,
 * MARKETING.PEOPLE.VIEW too (else "A customer").
 */
final class SalesController
{
    /** The list's name: its search (sales_q), pager (sales_page) and sort (sales_sort, sales_dir). */
    public const LIST = 'sales';

    /** The states the list may show, in words, with the icon of each card and the shown states each counts. */
    public const STATES = [
        'paid' => ['Paid', 'circle-check', ['paid', 'partly_refunded', 'refunded', 'charged_back']],
        'waiting' => ['Waiting for payment', 'hourglass', ['waiting']],
        'cancelled' => ['Cancelled', 'circle-x', ['cancelled']],
        'all' => ['All', 'list', ['paid', 'partly_refunded', 'refunded', 'charged_back', 'waiting', 'cancelled']],
    ];

    /** The list's columns: what each sorts by (none for a column that cannot be), and its heading. */
    private const COLUMNS = ['when' => ['when', 'Paid or placed'], 'reference' => ['reference', 'Order'], 'customer' => ['', 'Customer'], 'bought' => ['', 'Bought'],
        'campaign' => ['', 'Campaign'], 'through' => ['', 'Came through'], 'country' => ['country', 'Country'], 'gross' => ['amount', 'Gross'], 'refunds' => ['refunds', 'Refunds'],
        'net' => ['net', 'Net'], 'state' => ['state', 'State']];

    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly SaleWords $words,
        private readonly Sales $sales,
        private readonly Campaigns $campaigns,
        private readonly Publications $publications,
        private readonly Channels $channels,
        private readonly MarketingProject $project,
        private readonly Sites $sites,
        private readonly ?SiteReports $reports = null,
    ) {
    }

    public function list(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        LiveRefresh::notAVisit($request);
        $query = $this->listQuery($request);
        $filter = $query['filter'];
        $model = $query['model'];
        $total = $this->sales->count($filter);
        $pagination = Pagination::create($request->query->get(self::LIST . '_page'), $request->query->get(self::LIST . '_page_size'), $total);
        $base = $this->pages->path('cattophp_marketing_sales');
        $kept = $query['kept'];
        $sorted = [self::LIST . '_sort' => $query['sort'], self::LIST . '_dir' => $query['direction']];
        $counts = $this->sales->stateCounts($filter);
        $cards = [];
        foreach (self::STATES as $value => [$label, $icon, $shown]) {
            $count = array_sum(array_map(static fn (string $state): int => $counts[$state] ?? 0, $shown));
            $cards[] = ['value' => $value, 'label' => $label, 'icon' => $this->marketing->icon($icon), 'note' => $count === 0 ? 'None' : MarketingPages::count($count, 'sale', 'sales')];
        }
        $headers = [];
        foreach (self::COLUMNS as $key => [$sorts, $label]) {
            $next = $sorts === $query['sort'] ? ($query['direction'] === 'asc' ? 'desc' : 'asc') : (in_array($sorts, ['reference', 'country', 'state'], true) ? 'asc' : 'desc');
            $headers[] = ['sortable' => $sorts !== '', 'key' => $key, 'label' => $label, 'width' => 0, 'end' => in_array($key, ['gross', 'refunds', 'net'], true),
                'active' => $sorts !== '' && $sorts === $query['sort'], 'direction' => $query['direction'],
                'href' => $sorts === '' ? '' : $base . '?' . http_build_query(array_filter([self::LIST . '_sort' => $sorts, self::LIST . '_dir' => $next] + $kept, static fn (string $value): bool => $value !== ''))];
        }
        $totals = $this->sales->totals($filter);
        $sites = $this->sites->all();
        $where = [['value' => SaleFilter::EVERYWHERE, 'label' => 'Everywhere', 'icon' => $this->marketing->icon('globe'), 'note' => 'Here and on connected sites'],
            ['value' => SaleFilter::HERE, 'label' => 'Sold here', 'icon' => $this->marketing->icon('store'), 'note' => 'On this site']];
        foreach ($sites as $site) {
            $where[] = ['value' => $site->key, 'label' => 'Sold on ' . $site->name, 'icon' => $this->marketing->icon('earth'), 'note' => 'A connected site, ' . $site->host()];
        }
        $campaign = $query['campaign'];

        return $this->pages->page($request, 'marketing-sales', '@CattoPHP/marketing/sales.html.twig', [
            'title' => 'Sales',
            'lead' => 'Every order, with or without a campaign. Revenue counts only once an order is paid; a sale counts for one campaign at a time.',
            'kicker' => 'Campaign Marketing Manager',
            'period' => $query['period']['name'],
            'period_words' => $query['period']['words'],
            'period_problem' => $query['period']['problem'],
            'from' => $query['period']['from'],
            'to' => $query['period']['to'],
            'period_cards' => $this->marketing->periodCards(),
            'state' => $filter->state,
            'state_cards' => $cards,
            'model' => $model,
            'model_cards' => $this->words->modelCards(),
            'where' => $filter->where,
            'where_cards' => $sites === [] && $filter->where === SaleFilter::EVERYWHERE ? [] : $where,
            'campaign' => $query['campaign_value'],
            'campaign_options' => $this->campaignOptions(),
            'publication' => $query['publication'],
            'publication_options' => $campaign === null ? [] : array_map(fn ($publication): array => ['value' => $publication->code,
                'label' => $publication->code . ', ' . ($this->channels->get($publication->channelKey)->label ?? $publication->channelKey) . ($publication->place !== '' ? ', ' . $publication->place : '')],
                $this->publications->list($campaign->id)),
            'channel' => $filter->channel ?? '',
            'channel_options' => array_map(static fn ($channel): array => ['value' => $channel->key, 'label' => $channel->label], $this->channels->all()),
            'offer' => $query['offer'],
            'offer_options' => $this->offerOptions(),
            'offers_heading' => $this->project->offersHeading(),
            'country' => $filter->country ?? '',
            'country_options' => Countries::options(),
            'searched' => $filter->search,
            'sort' => $query['sort'],
            'direction' => $query['direction'],
            'kept' => $kept,
            'total' => $total,
            'any' => $this->sales->count(new SaleFilter(state: 'all')) > 0,
            'rows' => array_map(fn (Sale $sale): array => $this->words->row($sale, $model), $this->sales->list($filter, $pagination->pageSize, $pagination->offset, $query['sort'], $query['direction'])),
            'headers' => $headers,
            'totals' => $this->words->totals($totals),
            'left_out' => $totals->leftOutNote(),
            'search' => ['ds_name' => self::LIST, 'ds_label' => 'Search by order reference', 'ds_action' => $base, 'ds_value' => $filter->search, 'ds_push_url' => true,
                'ds_hidden' => array_filter($sorted + array_diff_key($kept, [self::LIST . '_q' => true]), static fn (string $value): bool => $value !== ''),
                'ds_help' => 'A part of the order\'s reference is enough.'],
            'pagination' => Pager::payload($pagination, self::LIST, $base, 'Sales', $kept + $sorted),
            'base' => $base,
            'csv_href' => $this->pages->path('cattophp_marketing_sales_csv', array_filter($kept + $sorted, static fn (string $value): bool => $value !== '')),
            'lines_href' => $this->pages->path('cattophp_marketing_sales_lines_csv', array_filter($kept + $sorted, static fn (string $value): bool => $value !== '')),
        ]);
    }

    /** The sales the list shows (its filters and order, every page), and their totals, as a CSV file. */
    public function download(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $query = $this->listQuery($request);
        $filter = $query['filter'];
        $model = $query['model'];
        $rows = [];
        foreach ($this->sales->list($filter, max(1, $this->sales->count($filter)), 0, $query['sort'], $query['direction']) as $sale) {
            $row = $this->words->row($sale, $model);
            $paid = $sale->isPaid();
            $rows[] = [$sale->paidAt ?? $sale->placedAt, $row['when_words'], $sale->reference, $row['customer'], $row['bought'], $row['campaign'], $row['through'], $row['country'],
                $paid ? MarketingPages::decimal($sale->paidMinor) : '', $paid ? MarketingPages::decimal($sale->refundedMinor + $sale->chargedBackMinor) : '',
                $paid ? MarketingPages::decimal($sale->netMinor()) : '', $row['state'], $row['sold_on'], $sale->currency, $paid ? '' : MarketingPages::decimal($sale->totalMinor)];
        }
        $totals = $this->sales->totals($filter);
        $rows[] = [];
        foreach (SaleWords::totalsRows($totals) as $total) {
            $rows[] = $total;
        }
        if ($totals->leftOutNote() !== '') {
            $rows[] = [$totals->leftOutNote()];
        }

        return new CsvDownload('marketing-sales', ['Paid or placed', 'Which', 'Order', 'Customer', 'Bought', 'Campaign', 'Came through', 'Country', 'Gross', 'Refunds', 'Net', 'State',
            'Sold on', 'Currency', 'To pay'], $rows, $this->marketing->now());
    }

    /** The lines of the sales the list shows (its filters and order, every page), one row each, as a CSV file. */
    public function downloadLines(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $query = $this->listQuery($request);
        $filter = $query['filter'];
        $model = $query['model'];
        $rows = [];
        foreach ($this->sales->list($filter, max(1, $this->sales->count($filter)), 0, $query['sort'], $query['direction']) as $sale) {
            $row = $this->words->row($sale, $model);
            foreach ($sale->lines as $line) {
                $rows[] = [$sale->reference, $sale->paidAt ?? $sale->placedAt, $row['state'], $line->label, $line->kindLabel, $line->offerType . ':' . $line->offerReference, $line->quantity,
                    MarketingPages::decimal($line->priceMinor), MarketingPages::decimal($line->discountMinor), MarketingPages::decimal($line->paidMinor()),
                    MarketingPages::decimal($line->refundedMinor), MarketingPages::decimal($line->chargedBackMinor), MarketingPages::decimal($line->netMinor()), $row['campaign'], $sale->currency,
                    implode(', ', $line->contains)];
            }
        }

        return new CsvDownload('marketing-sale-lines', ['Order', 'Paid or placed', 'State', 'Offer', 'Kind', 'Offer key', 'Quantity', 'Price', 'Discount', 'Paid', 'Refunded',
            'Charged back', 'Net', 'Campaign', 'Currency', 'Holds'], $rows, $this->marketing->now());
    }

    /** One sale made here, by its reference. */
    public function show(Request $request, string $reference): Response
    {
        return $this->sale($request, $reference, null);
    }

    /** One sale a connected site reported, by that site's key and the sale's reference. */
    public function showFromSite(Request $request, string $site, string $reference): Response
    {
        return $this->sale($request, $reference, $site);
    }

    private function sale(Request $request, string $reference, ?string $site): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $sale = $this->sales->get($reference, $site) ?? throw new NotFoundHttpException('There is no such sale.');
        $model = $this->words->model(SaleWords::asked($request));
        $state = $this->words->state($sale);
        $campaign = $this->words->campaign($sale, $model);
        $customer = $this->words->customer($sale);
        $order = $sale->originSite === null ? $this->project->saleHref($sale->reference) : null;
        $lines = [];
        foreach ($sale->lines as $line) {
            $lines[] = $this->lineRow($sale, $line);
        }
        $movements = array_map(fn (SaleMovement $movement): array => ['at' => $this->marketing->moment($movement->at), 'kind' => $movement->kindWords(),
            'amount' => $this->words->money($sale, $movement->amountMinor), 'how' => $movement->how !== '' ? $movement->how : 'Not said', 'takes_back' => $movement->takesBack()], $sale->movements);
        $reports = [];
        foreach ($sale->originSite === null && $this->reports !== null ? $this->reports->forSale($sale->reference) : [] as $report) {
            $reports[] = ['site' => $this->sites->name($report['site']), 'kind' => self::reportWords($report['kind']), 'made' => $this->marketing->moment($report['made_at']),
                'state' => $report['delivered_at'] !== null ? 'Delivered ' . $this->marketing->moment($report['delivered_at'])
                    : ($report['gave_up_at'] !== null ? 'Not delivered: given up ' . $this->marketing->moment($report['gave_up_at']) : 'Waiting to be delivered'),
                'tone' => $report['delivered_at'] !== null ? 'success' : ($report['gave_up_at'] !== null ? 'danger' : 'warning')];
        }
        $channel = $this->words->channel($sale, $model);

        return $this->pages->page($request, 'marketing-sale', '@CattoPHP/marketing/sale.html.twig', [
            'title' => 'Order ' . $sale->reference,
            'lead' => self::bought($sale),
            'kicker' => 'Sale',
            'reference' => $sale->reference,
            'state' => $state['label'],
            'tone' => $state['tone'],
            'campaign' => $campaign['name'],
            'campaign_href' => $campaign['href'],
            'channel' => $channel,
            'sold_on' => $this->words->soldOn($sale),
            'model' => $model,
            'order_href' => $order ?? '',
            'journey_href' => $this->words->journeyHref($sale) ?? '',
            'facts' => array_values(array_filter([
                ['label' => 'Placed', 'value' => $this->marketing->moment($sale->placedAt)],
                ['label' => 'Paid', 'value' => $sale->paidAt !== null ? $this->marketing->moment($sale->paidAt) : 'Not paid'],
                $sale->cancelledAt !== null ? ['label' => 'Cancelled', 'value' => $this->marketing->moment($sale->cancelledAt)] : null,
                ['label' => $sale->organisation !== null && $this->words->maySeePeople() ? 'Company' : 'Customer', 'value' => $customer['name'] . ($customer['note'] !== '' ? ' (' . lcfirst($customer['note']) . ')' : '')],
                ['label' => 'Country', 'value' => SaleWords::country($sale->country)],
                ['label' => 'Promotion', 'value' => $sale->promotion ?? 'None'],
                ['label' => 'To pay', 'value' => $this->words->money($sale, $sale->totalMinor) . ($sale->walletMinor > 0 ? ', ' . $this->words->money($sale, $sale->walletMinor) . ' of it in wallet credit' : '')],
                $sale->originSite !== null ? ['label' => 'Sold on', 'value' => $this->sites->name($sale->originSite) . ', a connected site'] : null,
            ])),
            'money' => [
                ['label' => 'Gross', 'value' => $this->words->money($sale, $sale->paidMinor)],
                ['label' => 'Discount', 'value' => $this->words->money($sale, $sale->discountMinor)],
                ['label' => 'Refunds', 'value' => $this->words->money($sale, $sale->refundedMinor)],
                ['label' => 'Chargebacks', 'value' => $this->words->money($sale, $sale->chargedBackMinor)],
                ['label' => 'Net', 'value' => $this->words->money($sale, $sale->netMinor())],
            ],
            'other_currency' => $this->words->otherCurrency($sale) ? 'This sale is in ' . $sale->currency . ', so it is left out of every total.' : '',
            'lines' => $lines,
            'movements' => $movements,
            'touches' => [$this->touchRow($sale, 'last'), $this->touchRow($sale, 'first')],
            'window' => $this->windowWords($sale),
            'promotion_words' => $this->promotionWords($sale),
            'counts_now' => 'The reports count the ' . $this->words->setting() . ' touch now: ' . $this->words->campaign($sale, $this->words->setting())['name'] . '.',
            'settings_href' => $this->pages->may(MarketingPermissions::SETTINGS) ? $this->pages->path('cattophp_marketing_settings') . '#settings-marketing' : '',
            'reports' => $reports,
            'sales_href' => $this->pages->path('cattophp_marketing_sales'),
        ]);
    }

    /** @return array<string,mixed> one line of a sale, with a bundle's offers in words */
    private function lineRow(Sale $sale, SaleLine $line): array
    {
        $holds = [];
        foreach ($line->contains as $key) {
            [$type, $reference] = explode(':', $key, 2) + [1 => ''];
            $holds[] = $this->project->offer($type, $reference)->label ?? $reference;
        }

        return [
            'label' => $line->label,
            'kind' => $line->kindLabel,
            'holds' => $holds === [] ? '' : 'Holds ' . implode(', ', $holds),
            'quantity' => number_format($line->quantity),
            'price' => $this->words->money($sale, $line->priceMinor),
            'was' => $line->wasMinor !== null && $line->wasMinor > $line->priceMinor ? $this->words->money($sale, $line->wasMinor) : '',
            'discount' => $this->words->money($sale, $line->discountMinor),
            'paid' => $this->words->money($sale, $line->paidMinor()),
            'refunded' => $this->words->money($sale, $line->refundedMinor),
            'charged_back' => $this->words->money($sale, $line->chargedBackMinor),
        ];
    }

    /** @return array<string,string> a sale's first or last touch: its campaign, what it came through, when, and how it was found */
    private function touchRow(Sale $sale, string $model): array
    {
        $campaign = $this->words->campaign($sale, $model);
        $touchId = $sale->touchId($model);
        $touch = $touchId === null ? null : $this->words->touch($touchId);
        $by = $sale->by($model);
        $at = $by !== 'promotion' && $touch !== null ? $this->marketing->moment($touch->at) : '';

        return [
            'which' => $model === 'first' ? 'First touch' : 'Last touch',
            'counts' => $model === $this->words->setting() ? 'Counted now' : '',
            'campaign' => $campaign['name'],
            'campaign_href' => $campaign['href'],
            'through' => $this->words->cameThrough($sale, $model),
            'at' => $at,
            'how' => $by === null ? 'No click within the time, and no campaign\'s promotion code' : (Sale::BY[$by] ?? $by),
        ];
    }

    /** The time a sale counted for a campaign within, as it was, in words. */
    private function windowWords(Sale $sale): string
    {
        $within = $sale->countedWithin !== null && Duration::isValid($sale->countedWithin) ? Duration::parse($sale->countedWithin)->words() : '';
        if ($within === '') {
            return 'Which campaign gets it is worked out when it is paid.';
        }

        return $sale->fixed ? 'When it was paid, a sale counted for a campaign within ' . $within . ' of the click. That is kept with it, so a later change of the setting changes nothing here.'
            : 'Worked out from when it was placed, within ' . $within . ' of the click, and again when it is paid.';
    }

    /** What the promotion code used means for the sale, in words, or ''. */
    private function promotionWords(Sale $sale): string
    {
        if ($sale->promotion === null || $sale->promotionCampaignId === null) {
            return '';
        }
        $campaign = $this->words->campaignOf($sale->promotionCampaignId);
        $name = $campaign->name ?? 'a campaign';

        return 'Its promotion code ' . $sale->promotion . ' is ' . $name . '\'s, and was set to count ' . ($sale->promotionCounts === 'always' ? 'always, even over another campaign\'s link' : 'only when the customer followed no link') . ' when it was paid.';
    }

    private static function bought(Sale $sale): string
    {
        return SaleWords::bought($sale);
    }

    private static function reportWords(string $kind): string
    {
        return match ($kind) {
            'order_placed' => 'Order placed',
            'order_cancelled' => 'Order cancelled',
            'sale_paid' => 'Paid',
            'sale_refunded' => 'Refunded',
            'sale_charged_back' => 'Charged back',
            default => ucfirst(str_replace('_', ' ', $kind)),
        };
    }

    /** @return list<array{value:string,label:string}> the campaign choices: every sale, any campaign, none, then every campaign by name */
    private function campaignOptions(): array
    {
        $options = [['value' => '', 'label' => 'With or without a campaign'], ['value' => SaleFilter::ANY_CAMPAIGN, 'label' => 'Any campaign'],
            ['value' => SaleFilter::NO_CAMPAIGN, 'label' => 'No campaign']];
        foreach ($this->campaigns->list(null, '', 'name', 'asc', max(1, $this->campaigns->count())) as $summary) {
            $options[] = ['value' => $summary->campaign->code, 'label' => $summary->campaign->name];
        }

        return $options;
    }

    /** @return list<array{value:string,label:string}> the site's offers, for the list's choice */
    private function offerOptions(): array
    {
        $kinds = $this->project->offerKinds();

        return array_map(static fn ($offer): array => ['value' => $offer->key(), 'label' => $offer->label . (isset($kinds[$offer->type]) ? ' (' . strtolower($kinds[$offer->type]) . ')' : '')],
            $this->project->offers());
    }

    /**
     * The list's filters, sort and direction from the address, with what its links keep.
     *
     * @return array{filter:SaleFilter,model:string,period:array{period:Period,name:string,from:string,to:string,words:string,problem:string},campaign:?Campaign,campaign_value:string,publication:string,offer:string,sort:string,direction:string,kept:array<string,string>}
     */
    private function listQuery(Request $request): array
    {
        $period = $this->marketing->period($request);
        $state = MarketingPages::text($request->query->get('state', 'paid'));
        $state = isset(self::STATES[$state]) ? $state : 'paid';
        $value = MarketingPages::text($request->query->get('campaign', ''));
        $campaign = in_array($value, ['', SaleFilter::ANY_CAMPAIGN, SaleFilter::NO_CAMPAIGN], true) ? null : $this->campaigns->get($value);
        $value = $campaign !== null || in_array($value, [SaleFilter::ANY_CAMPAIGN, SaleFilter::NO_CAMPAIGN], true) ? ($campaign->code ?? $value) : '';
        $code = MarketingPages::text($request->query->get('publication', ''));
        $publication = $campaign === null || $code === '' ? null : $this->publications->get($campaign, $code);
        $channel = MarketingPages::text($request->query->get('channel', ''));
        $channel = $channel !== '' && $this->channels->get($channel) !== null ? $channel : null;
        $offer = MarketingPages::text($request->query->get('offer', ''));
        [$type, $reference] = str_contains($offer, ':') ? explode(':', $offer, 2) : ['', ''];
        $offered = preg_match(\CattoPHP\Marketing\Sales\SaleLine::OFFER_TYPE, $type) === 1 && $reference !== '';
        $country = strtoupper(MarketingPages::text($request->query->get('country', '')));
        $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
        $where = MarketingPages::text($request->query->get('where', SaleFilter::EVERYWHERE));
        $where = in_array($where, [SaleFilter::EVERYWHERE, SaleFilter::HERE], true) || ($where !== '' && $this->sites->find($where) !== null) ? $where : SaleFilter::EVERYWHERE;
        $asked = SaleWords::asked($request);
        $model = $this->words->model($asked);
        $search = mb_substr(trim(MarketingPages::text($request->query->get(self::LIST . '_q', ''))), 0, 60);
        $sort = MarketingPages::text($request->query->get(self::LIST . '_sort', 'when'));
        $sort = in_array($sort, Sales::SORTS, true) ? $sort : 'when';
        $direction = MarketingPages::text($request->query->get(self::LIST . '_dir', ''));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : (in_array($sort, ['reference', 'country', 'state'], true) ? 'asc' : 'desc');
        $filter = new SaleFilter($period['period']->from, $period['period']->to, $state, $campaign->id ?? ($value === '' ? null : $value), $asked, $channel, $publication?->id, null, null,
            $country, $offered ? $type : null, $offered ? $reference : null, $where, null, null, $search);
        $kept = MarketingPages::periodQuery($period) + ['state' => $state === 'paid' ? '' : $state, 'campaign' => $value, 'publication' => $publication->code ?? '', 'channel' => $channel ?? '',
            'offer' => $offered ? $offer : '', 'country' => $country ?? '', 'counts' => $asked ?? '', 'where' => $where === SaleFilter::EVERYWHERE ? '' : $where, self::LIST . '_q' => $search];

        return ['filter' => $filter, 'model' => $model, 'period' => $period, 'campaign' => $campaign, 'campaign_value' => $value, 'publication' => $publication->code ?? '',
            'offer' => $offered ? $offer : '', 'sort' => $sort, 'direction' => $direction, 'kept' => $kept];
    }
}
