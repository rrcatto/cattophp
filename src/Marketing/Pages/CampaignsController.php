<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Activity\ActivityDetails;
use CattoPHP\Activity\ActivityEntry;
use CattoPHP\Admin\AccountNames;
use CattoPHP\Http\CsvDownload;
use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\Marketing\Advert;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\CampaignFigures;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\CampaignOffer;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\CampaignSummary;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\FigureRow;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingTemplate;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Marketing\MarketingActionWords;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\Period;
use CattoPHP\Marketing\Publication;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Spend;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Ui\Pager;
use CattoPHP\Ui\Pagination;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The campaigns (`config/routes/marketing.php`): the list, with its status as feature cards, search, sort, pages and
 * Download as CSV; a new campaign and a campaign changed; and one campaign's page, holding everything about it with its
 * actions (activate, pause, edit, copy, archive, restore, delete): its figures, its own tracking link, its publications
 * with their links to copy, its adverts, its landing page (made from a template here, its visits and buttons pressed),
 * the offers it promotes (each viewed, favourited, sold and its net revenue), its customers and their orders, its budget
 * and costs, and its history. Its sales and money count each sale for the first or the last touch, as the settings say or
 * as the reader chooses with "A sale counts for" (counts=first or last). Seeing them needs MARKETING.VIEW, a customer's
 * name MARKETING.PEOPLE.VIEW too, changing them MARKETING.CAMPAIGN.MANAGE.
 */
final class CampaignsController
{
    /** The list's name: its search (campaigns_q), pager (campaigns_page) and sort (campaigns_sort, campaigns_dir). */
    public const LIST = 'campaigns';

    /** The statuses the list may show, in words, with the icon of each card. */
    public const FILTERS = [
        'current' => ['Current', 'layers', 'Not archived'],
        'draft' => ['Draft', 'pencil-line', ''],
        'scheduled' => ['Scheduled', 'calendar-clock', ''],
        'active' => ['Active', 'circle-play', ''],
        'paused' => ['Paused', 'circle-pause', ''],
        'ended' => ['Ended', 'flag', ''],
        'archived' => ['Archived', 'archive', ''],
        'all' => ['All', 'list', 'Archived too'],
    ];

    /** How many of its orders a campaign's page shows, the newest first. */
    private const ORDERS = 10;

    /** The list's columns: what each sorts by, and its heading. */
    private const COLUMNS = ['name' => 'Campaign', 'status' => 'Status', 'starts' => 'Runs', 'publications' => 'Publications', 'clicks' => 'Clicks',
        'unique_visitors' => 'Unique visitors', 'spend' => 'Spend and budget'];

    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly Campaigns $campaigns,
        private readonly Publications $publications,
        private readonly Adverts $adverts,
        private readonly Channels $channels,
        private readonly Spend $spend,
        private readonly TrackingLinks $links,
        private readonly CampaignFigures $figures,
        private readonly MarketingProject $project,
        private readonly AccountNames $names,
        private readonly ActivityDetails $details,
        private readonly ?LandingPages $landingPages = null,
        private readonly ?LandingTemplates $landingTemplates = null,
        private readonly ?Events $events = null,
        private readonly ?Sales $sales = null,
        private readonly ?SaleWords $saleWords = null,
        private readonly ?OfferInterest $interest = null,
    ) {
    }

    public function list(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        [$status, $search, $sort, $direction] = $this->listQuery($request);
        $filter = $status === 'all' ? null : $status;
        $total = $this->campaigns->count($filter, $search);
        $pagination = Pagination::create($request->query->get(self::LIST . '_page'), $request->query->get(self::LIST . '_page_size'), $total);
        $base = $this->pages->path('cattophp_marketing_campaigns');
        $filters = ['status' => $status, self::LIST . '_q' => $search, self::LIST . '_sort' => $sort, self::LIST . '_dir' => $direction];
        $counts = $this->campaigns->statusCounts();
        $cards = [];
        foreach (self::FILTERS as $value => [$label, $icon, $note]) {
            $count = $counts[$value === 'all' ? 'all' : $value] ?? 0;
            $words = $count === 0 ? 'none' : MarketingPages::count($count, 'campaign', 'campaigns');
            $cards[] = ['value' => $value, 'label' => $label, 'icon' => $this->marketing->icon($icon), 'note' => $note !== '' ? $note . ': ' . $words : ucfirst($words)];
        }
        $headers = [];
        foreach (self::COLUMNS as $key => $label) {
            $next = $key === $sort ? ($direction === 'asc' ? 'desc' : 'asc') : (in_array($key, ['name', 'status', 'starts'], true) ? 'asc' : 'desc');
            $headers[] = ['sortable' => true, 'key' => $key, 'label' => $label, 'width' => 0, 'end' => in_array($key, ['publications', 'clicks', 'unique_visitors', 'spend'], true),
                'active' => $key === $sort, 'direction' => $direction,
                'href' => $base . '?' . http_build_query(array_filter(['status' => $status, self::LIST . '_q' => $search, self::LIST . '_sort' => $key, self::LIST . '_dir' => $next], static fn (string $value): bool => $value !== ''))];
        }

        return $this->pages->page($request, 'marketing-campaigns', '@CattoPHP/marketing/campaigns.html.twig', [
            'title' => 'Campaigns',
            'lead' => 'Every campaign, running or not. A name opens everything about it.',
            'may_manage' => $this->pages->may(MarketingPermissions::MANAGE),
            'status' => $status,
            'status_cards' => $cards,
            'status_words' => self::FILTERS[$status][0],
            'searched' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'total' => $total,
            'any' => $counts['all'] > 0,
            'campaigns' => array_map($this->summary(...), $this->campaigns->list($filter, $search, $sort, $direction, $pagination->pageSize, $pagination->offset)),
            'headers' => $headers,
            'search' => ['ds_name' => self::LIST, 'ds_label' => 'Search the campaigns', 'ds_action' => $base, 'ds_value' => $search, 'ds_push_url' => true,
                'ds_hidden' => ['status' => $status, self::LIST . '_sort' => $sort, self::LIST . '_dir' => $direction], 'ds_help' => 'Searches every campaign\'s name, code and description.'],
            'pagination' => Pager::payload($pagination, self::LIST, $base, 'Campaigns', $filters),
            'base' => $base,
            'csv_href' => $this->pages->path('cattophp_marketing_campaigns_csv', array_filter($filters, static fn (string $value): bool => $value !== '')),
        ]);
    }

    /** The campaigns the list shows (its status, search and order, every page) as a CSV file. */
    public function download(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        [$status, $search, $sort, $direction] = $this->listQuery($request);
        $filter = $status === 'all' ? null : $status;
        $rows = [];
        foreach ($this->campaigns->list($filter, $search, $sort, $direction, max(1, $this->campaigns->count($filter, $search))) as $summary) {
            $campaign = $summary->campaign;
            $rows[] = [$campaign->code, $campaign->name, $this->marketing->status($campaign)['label'], $campaign->startsAt?->format('Y-m-d') ?? '', $campaign->endsAt?->format('Y-m-d') ?? '',
                $summary->publications, $summary->clicks, $summary->uniqueVisitors, MarketingPages::decimal($summary->spendMinor),
                $campaign->budgetMinor === null ? '' : MarketingPages::decimal($campaign->budgetMinor)];
        }

        return new CsvDownload('campaigns', ['Code', 'Name', 'Status', 'Starts', 'Ends', 'Publications', 'Clicks', 'Unique visitors', 'Spend', 'Budget'], $rows, $this->marketing->now());
    }

    public function new(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $errors = $this->pages->errors($request);
        $typed = TypedForm::take($request, $this->pages->path('cattophp_marketing_campaign_make'));

        return $this->pages->page($request, 'marketing-campaign-new', '@CattoPHP/marketing/campaign-form.html.twig', [
            'title' => 'New campaign',
            'lead' => 'It is saved as a draft. Nothing counts until you add where it is published and make it active.',
            'kicker' => 'Campaign Marketing Manager',
            'action' => $this->pages->path('cattophp_marketing_campaign_make'),
            'back_href' => $this->pages->path('cattophp_marketing_campaigns'),
            'back_label' => 'Every campaign',
            'save_label' => 'Save as a draft',
            'code_fixed' => '',
        ] + $this->form($typed === [] ? new CampaignInput() : CampaignInput::fromForm($typed), $errors, []));
    }

    public function make(Request $request): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_campaign_new');
        }
        try {
            $campaign = $this->campaigns->create(CampaignInput::fromForm($request->request->all()), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, self::why($refused, 'The campaign was not saved'), 'cattophp_marketing_campaign_new', errors: $refused);
        }

        return $this->pages->done($request, $campaign->name . ' is saved as a draft. Add where it is published, then make it active.', 'cattophp_marketing_campaign', ['code' => $campaign->code]);
    }

    public function edit(Request $request, string $code): Response
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        $errors = $this->pages->errors($request);
        $typed = TypedForm::take($request, $this->pages->path('cattophp_marketing_campaign_save', ['code' => $campaign->code]));
        $offers = $this->campaigns->offers($campaign->id);

        return $this->pages->page($request, 'marketing-campaign-edit', '@CattoPHP/marketing/campaign-form.html.twig', [
            'title' => 'Change ' . $campaign->name,
            'lead' => 'What you change here is in the campaign\'s history.',
            'kicker' => 'Campaign',
            'action' => $this->pages->path('cattophp_marketing_campaign_save', ['code' => $campaign->code]),
            'back_href' => $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code]),
            'back_label' => 'Back to the campaign',
            'save_label' => 'Save the campaign',
            'code_fixed' => $this->campaigns->hasClicks($campaign) ? $campaign->code : '',
        ] + $this->form($typed === [] ? CampaignInput::of($campaign, $offers) : CampaignInput::fromForm($typed), $errors, $offers));
    }

    public function save(Request $request, string $code): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_campaign_edit', ['code' => $campaign->code]);
        }
        try {
            $changed = $this->campaigns->change($campaign->code, CampaignInput::fromForm($request->request->all()), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, self::why($refused, $campaign->name . ' was not saved'), 'cattophp_marketing_campaign_edit', ['code' => $campaign->code], errors: $refused);
        }

        return $this->pages->done($request, $changed->name . ' is saved.', 'cattophp_marketing_campaign', ['code' => $changed->code]);
    }

    public function show(Request $request, string $code): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $campaign = $this->marketing->campaign($code);
        $mayManage = $this->pages->may(MarketingPermissions::MANAGE);
        $errors = $this->pages->errors($request);
        $costAction = $this->pages->path('cattophp_marketing_spend_add', ['code' => $campaign->code]);
        $costTyped = TypedForm::take($request, $costAction);
        $all = Period::all();
        $totals = $this->figures->totals($all, $campaign->id);
        $byPublication = [];
        foreach ($this->figures->byPublication($all, $campaign->id) as $row) {
            $byPublication[$row->key] = $row;
        }
        $adverts = $this->adverts->list($campaign->id);
        $advertNames = [];
        foreach ($adverts as $advert) {
            $advertNames[$advert->id] = $advert->name;
        }
        $published = $this->publications->list($campaign->id);
        $model = $this->saleWords?->model(SaleWords::asked($request)) ?? 'last';
        $publications = array_map(fn (Publication $publication): array => $this->publicationRow($campaign, $publication, $byPublication, $advertNames, $model), $published);
        $publicationCosts = array_sum(array_map(static fn (Publication $publication): int => $publication->costMinor, $published));
        $other = [];
        foreach ($this->spend->list($campaign->id) as $item) {
            $other[] = ['reference' => $item->reference, 'day' => $this->marketing->day($item->spentOn), 'note' => $item->note, 'amount' => $this->marketing->money($item->amountMinor),
                'remove_confirm' => 'Remove the cost of ' . $this->marketing->money($item->amountMinor) . ' from ' . $campaign->name . '?'];
        }
        $spent = $this->spend->total($campaign->id);
        $status = $this->marketing->status($campaign);
        $promotions = $this->project->promotions();
        $landing = $this->landingCard($campaign, $mayManage);
        $money = $this->sales?->totals(new SaleFilter(campaign: $campaign->id, model: $model));
        $registrations = $this->events?->count(Events::REGISTERED, campaignId: $campaign->id) ?? 0;

        return $this->pages->page($request, 'marketing-campaign', '@CattoPHP/marketing/campaign.html.twig', [
            'title' => $campaign->name,
            'lead' => $campaign->description,
            'kicker' => 'Campaign',
            'may_manage' => $mayManage,
            'campaign' => [
                'code' => $campaign->code,
                'name' => $campaign->name,
                'status' => $status['label'],
                'status_key' => $status['key'],
                'tone' => $status['tone'],
                'runs' => $this->marketing->runs($campaign),
                'left' => $this->marketing->left($campaign),
                'budget' => $campaign->budgetMinor === null ? '' : $this->marketing->money($campaign->budgetMinor),
                'objective' => $campaign->objective,
                'destination' => $landing['leads'] ? 'Its landing page, ' . $landing['address'] : $campaign->destination,
                'promotion' => $campaign->promotion ?? '',
                'promotion_words' => $campaign->promotion === null ? '' : ($promotions[$campaign->promotion] ?? $campaign->promotion),
                'promotion_href' => $campaign->promotion === null ? '' : ($this->project->promotionHref($campaign->promotion) ?? ''),
                'made' => $this->marketing->day($campaign->createdAt) . ($campaign->createdBy !== null ? ' by ' . $this->names->name($campaign->createdBy) : ''),
                'changed' => $this->marketing->moment($campaign->updatedAt),
                'may_activate' => $campaign->canActivate(),
                'may_pause' => $campaign->canPause(),
                'may_archive' => $campaign->canArchive(),
                'may_restore' => $campaign->canRestore(),
                'may_delete' => $this->campaigns->canDelete($campaign),
                'link' => $this->links->campaignLink($campaign),
            ],
            'figures' => [
                ['label' => 'Clicks', 'value' => number_format($totals->clicks), 'note' => 'Tracking links followed by people'],
                ['label' => 'Unique visitors', 'value' => number_format($totals->uniqueVisitors), 'note' => MarketingPages::count($totals->repeatVisits, 'repeat visit', 'repeat visits')],
                ['label' => 'Set aside', 'value' => number_format($totals->setAside() + $totals->notRunning + $totals->sameVisit),
                    'note' => MarketingPages::count($totals->linkPreviews, 'link preview', 'link previews') . ', ' . MarketingPages::count($totals->notRunning, 'opening', 'openings') . ' while not running'],
                ['label' => 'Spend', 'value' => $this->marketing->money($spent), 'note' => $campaign->budgetMinor === null ? 'No budget set' : 'Of a ' . $this->marketing->money($campaign->budgetMinor) . ' budget'],
                ['label' => 'Landing page visits', 'value' => number_format($landing['visits']), 'note' => $landing['exists'] ? 'Its page drawn for a person' : 'It has no landing page'],
                ['label' => 'Buttons pressed', 'value' => number_format($landing['pressed']), 'note' => 'On its landing page'],
                ['label' => 'Registrations', 'value' => number_format($registrations), 'note' => 'By people who came by it'],
                ['label' => 'Paying customers', 'value' => number_format($money->payingCustomers ?? 0), 'note' => MarketingPages::count($money->paidSales ?? 0, 'paid order', 'paid orders')],
                ['label' => 'Net revenue', 'value' => $this->marketing->money($money?->netMinor() ?? 0), 'note' => 'Gross ' . $this->marketing->money($money->grossMinor ?? 0) . ', less refunds and chargebacks'],
                ['label' => 'Back for every ' . $this->marketing->money(100), 'value' => $spent > 0 ? $this->marketing->money((int) round(($money?->netMinor() ?? 0) * 100 / $spent)) : 'No spend',
                    'note' => $spent > 0 ? 'Net revenue for each ' . $this->marketing->money(100) . ' spent' : 'Nothing spent on it yet'],
            ],
            'model' => $model,
            'model_cards' => $this->saleWords?->modelCards() ?? [],
            'left_out' => $money?->leftOutNote() ?? '',
            'landing' => $landing,
            'columns' => array_map(static fn (FigureRow $day): array => ['label' => $day->label, 'value' => $day->clicks, 'words' => MarketingPages::count($day->clicks, 'click', 'clicks')],
                self::lastDays($this->figures->byDay($all, $campaign->id))),
            'publications' => $publications,
            'adverts' => array_map(fn (Advert $advert): array => $this->advertCard($campaign, $advert, $published, $byPublication, $model), $adverts),
            'offers_heading' => $this->project->offersHeading(),
            'offers' => $this->offerRows($this->campaigns->offers($campaign->id), $campaign, $model),
            'orders' => $this->orders($campaign, $model),
            'has_offers' => $this->project->offers() !== [],
            'spend' => [
                'total' => $this->marketing->money($spent),
                'publications' => $this->marketing->money($publicationCosts),
                'other' => $other,
                'budget' => $campaign->budgetMinor,
                'used' => $campaign->budgetMinor === null ? null : min(100, $campaign->budgetMinor === 0 ? 100 : (int) round($spent * 100 / $campaign->budgetMinor)),
                'over' => $campaign->budgetMinor !== null && $spent > $campaign->budgetMinor,
                'words' => $campaign->budgetMinor === null ? $this->marketing->money($spent) . ' spent, with no budget set.'
                    : $this->marketing->money($spent) . ' spent of ' . $this->marketing->money($campaign->budgetMinor) . ($campaign->budgetMinor > 0 ? ' (' . (int) round($spent * 100 / $campaign->budgetMinor) . '%)' : '') . '.',
            ],
            'cost' => [
                'action' => $costAction,
                'spent_on' => MarketingPages::text($costTyped['spent_on'] ?? $this->marketing->now()->format('Y-m-d')),
                'amount' => MarketingPages::text($costTyped['amount'] ?? ''),
                'note' => MarketingPages::text($costTyped['note'] ?? ''),
                'errors' => $costTyped === [] ? [] : $errors,
                'open' => $costTyped !== [],
            ],
            'currency' => $this->project->currency(),
            'history' => array_map($this->historyRow(...), $this->campaigns->history($campaign)),
        ]);
    }

    public function activate(Request $request, string $code): RedirectResponse
    {
        return $this->act($request, $code, function (Campaign $campaign): string {
            $active = $this->campaigns->activate($campaign->code, $this->pages->actor());

            return $active->shownStatus($this->marketing->now()) === 'scheduled'
                ? $active->name . ' is active, and its clicks count from ' . $this->marketing->day($active->startsAt) . '.'
                : $active->name . ' is now active.';
        });
    }

    public function pause(Request $request, string $code): RedirectResponse
    {
        return $this->act($request, $code, fn (Campaign $campaign): string => $this->campaigns->pause($campaign->code, $this->pages->actor())->name
            . ' is paused. Its links still lead where they did, and their clicks are set aside until it is active again.');
    }

    public function archive(Request $request, string $code): RedirectResponse
    {
        return $this->act($request, $code, fn (Campaign $campaign): string => $this->campaigns->archive($campaign->code, $this->pages->actor())->name
            . ' is archived, with everything it holds. Its links still lead where they did.');
    }

    public function restore(Request $request, string $code): RedirectResponse
    {
        return $this->act($request, $code, function (Campaign $campaign): string {
            $restored = $this->campaigns->restore($campaign->code, $this->pages->actor());

            return $restored->name . ' is restored, ' . ($restored->status === 'draft' ? 'as a draft.' : 'paused: make it active when it should count again.');
        });
    }

    public function copy(Request $request, string $code): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_campaign', ['code' => $campaign->code]);
        }
        try {
            $copy = $this->campaigns->copy($campaign->code, $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), 'cattophp_marketing_campaign', ['code' => $campaign->code]);
        }

        return $this->pages->done($request, 'A copy of ' . $campaign->name . ' is saved as a draft, ' . $copy->name . ', with its adverts and offers. Give it its dates and publications.',
            'cattophp_marketing_campaign', ['code' => $copy->code]);
    }

    public function delete(Request $request, string $code): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_campaign', ['code' => $campaign->code]);
        }
        try {
            $this->campaigns->delete($campaign->code, $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), 'cattophp_marketing_campaign', ['code' => $campaign->code]);
        }

        return $this->pages->done($request, $campaign->name . ' is deleted, with its adverts and costs.', 'cattophp_marketing_campaigns');
    }

    public function addCost(Request $request, string $code): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_campaign', ['code' => $campaign->code], 'spend');
        }
        try {
            $item = $this->spend->add($campaign->code, MarketingPages::text($request->request->get('spent_on', '')), MarketingPages::text($request->request->get('amount', '')),
                MarketingPages::text($request->request->get('note', '')), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, self::why($refused, 'The cost was not added'), 'cattophp_marketing_campaign', ['code' => $campaign->code], 'spend', errors: $refused);
        }

        return $this->pages->done($request, 'A cost of ' . $this->marketing->money($item->amountMinor) . ' is added to ' . $campaign->name . '.', 'cattophp_marketing_campaign', ['code' => $campaign->code], 'spend');
    }

    public function removeCost(Request $request, string $code, string $reference): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_campaign', ['code' => $campaign->code], 'spend');
        }
        $item = null;
        foreach ($this->spend->list($campaign->id) as $cost) {
            if ($cost->reference === $reference) {
                $item = $cost;
            }
        }
        if ($item === null) {
            return $this->pages->refused($request, 'That cost is not one of ' . $campaign->name . '\'s now, so nothing was removed.', 'cattophp_marketing_campaign', ['code' => $campaign->code], 'spend');
        }
        $this->spend->remove($campaign->code, $reference, $this->pages->actor());

        return $this->pages->done($request, 'The cost of ' . $this->marketing->money($item->amountMinor) . ' on ' . $this->marketing->day($item->spentOn) . ' is removed from ' . $campaign->name . '.',
            'cattophp_marketing_campaign', ['code' => $campaign->code], 'spend');
    }

    /**
     * The campaign's landing page as its card shows it: its status, address, visits and buttons pressed, and Edit and View;
     * or, with none, the templates a page may start from, for Make the landing page.
     *
     * @return array{exists:bool,leads:bool,status:string,tone:string,address:string,visits:int,pressed:int,edit_href:string,view_href:string,template_cards:list<array<string,mixed>>,template:string}
     */
    private function landingCard(Campaign $campaign, bool $mayManage): array
    {
        $page = $this->landingPages?->forCampaign($campaign);
        $card = ['exists' => $page !== null, 'leads' => false, 'status' => '', 'tone' => 'neutral', 'address' => '', 'visits' => 0, 'pressed' => 0, 'edit_href' => '', 'view_href' => '',
            'template_cards' => [], 'template' => ''];
        if ($this->landingPages === null) {
            return $card;
        }
        $card['visits'] = $this->events?->count(Events::LANDING_OPENED, campaignId: $campaign->id) ?? 0;
        $card['pressed'] = $this->events?->count(Events::LANDING_PRESSED, campaignId: $campaign->id) ?? 0;
        if ($page !== null) {
            $address = $this->landingPages->address($page);

            return ['exists' => true, 'leads' => $page->isPublished(), 'status' => $page->isPublished() ? 'Published' : 'Draft', 'tone' => $page->isPublished() ? 'success' : 'neutral',
                'address' => $address, 'edit_href' => $this->pages->path('cattophp_marketing_landing_edit', ['reference' => $page->reference]),
                'view_href' => $page->isPublished() ? $address : $this->pages->path('cattophp_marketing_landing_preview', ['reference' => $page->reference])] + $card;
        }
        if ($mayManage && $this->landingTemplates !== null) {
            $context = $this->landingPages->contextFor($campaign, null);
            $card['template_cards'] = array_map(fn (LandingTemplate $template): array => ['value' => $template->key, 'label' => $template->name,
                'icon' => $this->marketing->icon($template->builtIn ? 'layout-template' : 'bookmark'), 'note' => $template->note !== '' ? $template->note : 'Saved by someone here'],
                $this->landingTemplates->available($context, true));
            $card['template'] = $card['template_cards'][0]['value'] ?? '';
        }

        return $card;
    }

    /** Why a form was refused, as its notice: the reason when it is about the whole form, else where to look. */
    public static function why(MarketingRefused $refused, string $notDone): string
    {
        $fields = array_diff_key($refused->errors(), ['' => true]);

        return $fields === [] ? $refused->getMessage() : $notDone . ': see why beside each field.';
    }

    /**
     * A change of a campaign's status from its page, answered by its page with what was done or why not.
     *
     * @param callable(Campaign): string $change
     */
    private function act(Request $request, string $code, callable $change): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_campaign', ['code' => $campaign->code]);
        }
        try {
            $done = $change($campaign);
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), 'cattophp_marketing_campaign', ['code' => $campaign->code]);
        }

        return $this->pages->done($request, $done, 'cattophp_marketing_campaign', ['code' => $campaign->code]);
    }

    /** @return array{0:string,1:string,2:string,3:string} the list's status, search, sort and direction from the address */
    private function listQuery(Request $request): array
    {
        $status = MarketingPages::text($request->query->get('status', 'current'));
        $status = isset(self::FILTERS[$status]) ? $status : 'current';
        $search = trim(MarketingPages::text($request->query->get(self::LIST . '_q', '')));
        $sort = MarketingPages::text($request->query->get(self::LIST . '_sort', 'updated'));
        $sort = in_array($sort, Campaigns::SORTS, true) ? $sort : 'updated';
        $direction = MarketingPages::text($request->query->get(self::LIST . '_dir', ''));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : (in_array($sort, ['name', 'code', 'status', 'starts'], true) ? 'asc' : 'desc');

        return [$status, mb_substr($search, 0, 120), $sort, $direction];
    }

    /** @return array<string,mixed> a campaign as a row of the list */
    private function summary(CampaignSummary $summary): array
    {
        $campaign = $summary->campaign;
        $status = $this->marketing->status($campaign);
        $budget = $campaign->budgetMinor;

        return [
            'code' => $campaign->code,
            'name' => $campaign->name,
            'href' => $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code]),
            'status' => $status['label'],
            'tone' => $status['tone'],
            'runs' => $this->marketing->runs($campaign),
            'publications' => number_format($summary->publications),
            'clicks' => number_format($summary->clicks),
            'unique_visitors' => number_format($summary->uniqueVisitors),
            'spend' => $this->marketing->money($summary->spendMinor) . ($budget === null ? '' : ' of ' . $this->marketing->money($budget)),
            'over' => $summary->overBudget(),
        ];
    }

    /**
     * What the new and edit pages draw: the campaign as typed, why each field was refused, the offers as feature cards
     * and the promotions.
     *
     * @param array<string,string> $errors
     * @param list<CampaignOffer> $kept the offers the campaign has now, kept as choices though the project no longer offers them
     * @return array<string,mixed>
     */
    private function form(CampaignInput $input, array $errors, array $kept): array
    {
        $kinds = $this->project->offerKinds();
        $cards = [];
        $known = [];
        foreach ($this->project->offers() as $offer) {
            $known[$offer->key()] = true;
            $note = trim(($kinds[$offer->type] ?? '') . ($offer->price !== '' ? ', ' . $offer->price : '') . ($offer->wasPrice !== '' ? ' (was ' . $offer->wasPrice . ')' : ''), ', ');
            $cards[] = ['value' => $offer->key(), 'label' => $offer->label, 'icon' => $this->marketing->icon($offer->type === 'bundle' ? 'package' : 'tag'),
                'note' => $note . ($offer->available ? '' : ($note !== '' ? '. ' : '') . 'Not on sale now')];
        }
        foreach ($kept as $offer) {
            if (!isset($known[$offer->key()])) {
                $cards[] = ['value' => $offer->key(), 'label' => $offer->label, 'icon' => $this->marketing->icon('tag'), 'note' => 'No longer offered by the site'];
            }
        }
        $promotions = [];
        foreach ($this->project->promotions() as $reference => $words) {
            $promotions[] = ['value' => (string) $reference, 'label' => (string) $reference, 'icon' => $this->marketing->icon('ticket-percent'), 'note' => $words];
        }
        if ($input->promotion !== '' && !isset($this->project->promotions()[$input->promotion])) {
            $promotions[] = ['value' => $input->promotion, 'label' => $input->promotion, 'icon' => $this->marketing->icon('ticket-percent'), 'note' => 'No longer one of the site\'s promotions'];
        }
        if ($promotions !== []) {
            array_unshift($promotions, ['value' => '', 'label' => 'None', 'icon' => $this->marketing->icon('ban'), 'note' => 'No discount code']);
        }

        return [
            'typed' => [
                'name' => $input->name,
                'code' => $input->code,
                'description' => $input->description,
                'objective' => $input->objective,
                'starts_at' => substr($input->startsAt, 0, 10),
                'ends_at' => substr($input->endsAt, 0, 10),
                'budget' => $input->budget,
                'destination' => $input->destination,
                'promotion' => $input->promotion,
                'offers' => $input->offers,
            ],
            'errors' => $errors + ['name' => '', 'code' => '', 'description' => '', 'objective' => '', 'starts_at' => '', 'ends_at' => '', 'budget' => '', 'destination' => '',
                'promotion' => '', 'offers' => ''],
            'offer_cards' => $cards,
            'offers_heading' => $this->project->offersHeading(),
            'promotion_cards' => $promotions,
            'currency' => $this->project->currency(),
        ];
    }

    /**
     * @param array<string,FigureRow> $figures each publication's figures, by campaign and publication code
     * @param array<int,string> $advertNames
     * @return array<string,mixed>
     */
    private function publicationRow(Campaign $campaign, Publication $publication, array $figures, array $advertNames, string $model): array
    {
        $channel = $this->channels->get($publication->channelKey);
        $row = $figures[$campaign->code . '/' . $publication->code] ?? null;
        $money = $this->sales?->totals(new SaleFilter(campaign: $campaign->id, model: $model, publicationId: $publication->id));
        $source = $publication->sourceType !== null && $publication->sourceReference !== null ? $this->project->publicationSource($publication->sourceType, $publication->sourceReference) : null;

        return [
            'code' => $publication->code,
            'day' => $this->marketing->day($publication->publishedOn ?? $publication->createdAt),
            'channel' => $channel->label ?? $publication->channelKey,
            'icon' => $this->marketing->icon($channel->icon ?? 'megaphone'),
            'place' => $publication->place,
            'advert' => $publication->advertId !== null ? ($advertNames[$publication->advertId] ?? '') : '',
            'link' => $this->links->forms($campaign, $publication)->short,
            'clicks' => number_format($row->clicks ?? 0),
            'unique_visitors' => number_format($row->uniqueVisitors ?? 0),
            'cost' => $this->marketing->money($publication->costMinor),
            'paid' => number_format($money->paidSales ?? 0),
            'net' => $this->marketing->money($money?->netMinor() ?? 0),
            'source' => $source === null ? '' : ($publication->sourceType === 'mailing' ? 'Written in Mailing, under Messages: ' : 'Made from ') . $source['label'],
            'source_href' => $source['href'] ?? '',
            'retired' => $publication->isRetired(),
            'href' => $this->pages->path('cattophp_marketing_publication', ['code' => $campaign->code, 'publication' => $publication->code]),
            'qr_href' => $this->pages->path('cattophp_marketing_publication_qr', ['code' => $campaign->code, 'publication' => $publication->code]),
            'retire_action' => $this->pages->path($publication->isRetired() ? 'cattophp_marketing_publication_restore' : 'cattophp_marketing_publication_retire',
                ['code' => $campaign->code, 'publication' => $publication->code]),
        ];
    }

    /**
     * An advert as a card on its campaign's page, with the net revenue of the sales its publications brought and their clicks.
     *
     * @param list<Publication> $published
     * @param array<string,FigureRow> $figures each publication's figures, by campaign and publication code
     * @return array<string,mixed>
     */
    private function advertCard(Campaign $campaign, Advert $advert, array $published = [], array $figures = [], string $model = 'last'): array
    {
        $parameters = ['code' => $campaign->code, 'reference' => $advert->reference];
        $clicks = 0;
        foreach ($published as $publication) {
            if ($publication->advertId === $advert->id) {
                $clicks += ($figures[$campaign->code . '/' . $publication->code] ?? null)->clicks ?? 0;
            }
        }
        $net = $this->sales?->totals(new SaleFilter(campaign: $campaign->id, model: $model, advertId: $advert->id))->netMinor() ?? 0;

        return [
            'name' => $advert->name,
            'kind' => Advert::KINDS[$advert->kind] ?? $advert->kind,
            'headline' => $advert->headline,
            'words' => mb_strlen($advert->words) > 160 ? mb_substr($advert->words, 0, 157) . '...' : $advert->words,
            'picture' => $advert->picture === null ? '' : $this->pages->path('cattophp_marketing_advert_picture', $parameters + ['v' => substr($advert->picture, 0, 12)]),
            'icon' => $this->marketing->icon(match ($advert->kind) { 'picture' => 'image', 'video' => 'video', default => 'type' }),
            'used' => $advert->publications === 0 ? 'Not published yet' : 'In ' . MarketingPages::count($advert->publications, 'publication', 'publications'),
            'revenue' => $this->marketing->money($net) . ' net revenue from ' . MarketingPages::count($clicks, 'click', 'clicks'),
            'retired' => $advert->isRetired(),
            'href' => $this->pages->path('cattophp_marketing_advert', $parameters),
            'retire_action' => $this->pages->path($advert->isRetired() ? 'cattophp_marketing_advert_restore' : 'cattophp_marketing_advert_retire', $parameters),
        ];
    }

    /**
     * The offers a campaign promotes, each viewed, favourited and sold (units) by people who came by it and its net
     * revenue, then any other offer its sales held, marked as not among what it promotes.
     *
     * @param list<CampaignOffer> $offers
     * @return list<array<string,mixed>>
     */
    private function offerRows(array $offers, ?Campaign $campaign = null, string $model = 'last'): array
    {
        $kinds = $this->project->offerKinds();
        $interest = $campaign === null ? [] : ($this->interest?->forCampaign($campaign->id) ?? []);
        $sold = [];
        foreach ($campaign === null ? [] : ($this->sales?->offers(new SaleFilter(campaign: $campaign->id, model: $model)) ?? []) as $offerSales) {
            $sold[$offerSales->offerKey()] = $offerSales;
        }
        $figures = fn (string $key): array => ['viewed' => number_format($interest[$key]['viewed'] ?? 0), 'favourited' => number_format($interest[$key]['favourited'] ?? 0),
            'sold' => number_format(($sold[$key] ?? null)->units ?? 0), 'net' => $this->marketing->money(($sold[$key] ?? null)?->netMinor() ?? 0)];
        $rows = [];
        foreach ($offers as $chosen) {
            $offer = $this->project->offer($chosen->type, $chosen->reference);
            $rows[] = [
                'label' => $offer->label ?? $chosen->label,
                'kind' => $kinds[$chosen->type] ?? ucfirst($chosen->type),
                'href' => $offer->href ?? '',
                'price' => $offer === null ? 'No longer offered' : ($offer->price !== '' ? $offer->price : ''),
                'was' => $offer->wasPrice ?? '',
                'available' => $offer !== null && $offer->available,
                'promoted' => true,
            ] + $figures($chosen->key());
            unset($sold[$chosen->key()]);
        }
        foreach ($sold as $key => $offerSales) {
            $offer = $this->project->offer($offerSales->offerType, $offerSales->offerReference);
            $rows[] = ['label' => $offer->label ?? $offerSales->label, 'kind' => $offerSales->kindLabel !== '' ? $offerSales->kindLabel : ($kinds[$offerSales->offerType] ?? ucfirst($offerSales->offerType)),
                'href' => $offer->href ?? '', 'price' => $offer === null ? 'No longer offered' : $offer->price, 'was' => $offer->wasPrice ?? '', 'available' => $offer !== null && $offer->available,
                'promoted' => false] + $figures($key);
        }

        return $rows;
    }

    /**
     * "Customers and their orders": the campaign's latest orders in every state, newest first, with how many there are in
     * each state, See all its sales and Download as CSV (the Sales page and its CSV for the campaign).
     *
     * @return array<string,mixed>
     */
    private function orders(Campaign $campaign, string $model): array
    {
        if ($this->sales === null || $this->saleWords === null) {
            return ['rows' => [], 'summary' => '', 'all_href' => '', 'csv_href' => ''];
        }
        $filter = new SaleFilter(state: 'all', campaign: $campaign->id, model: $model);
        $total = $this->sales->count($filter);
        $parts = [];
        foreach ($this->sales->stateCounts($filter) as $state => $count) {
            if ($count > 0) {
                $parts[] = number_format($count) . ' ' . lcfirst(Sale::SHOWN[$state]);
            }
        }
        $rows = array_map(fn (Sale $sale): array => $this->saleWords->row($sale, $model), $this->sales->forCampaign($campaign->id, $model, self::ORDERS));
        $query = ['period' => 'all', 'state' => 'all', 'campaign' => $campaign->code] + ($model !== $this->saleWords->setting() ? ['counts' => $model] : []);

        return [
            'rows' => $rows,
            'summary' => $total === 0 ? '' : 'Showing ' . number_format(count($rows)) . ' of ' . MarketingPages::count($total, 'order', 'orders') . ': ' . implode(', ', $parts) . '.',
            'all_href' => $this->pages->path('cattophp_marketing_sales', $query),
            'csv_href' => $this->pages->path('cattophp_marketing_sales_csv', $query),
        ];
    }

    /** @return array<string,mixed> */
    private function historyRow(ActivityEntry $entry): array
    {
        return [
            'at' => $this->marketing->moment($entry->at),
            'who' => $entry->actorId === null ? ($entry->actorName !== null && $entry->actorName !== '' ? $entry->actorName : 'The system') : $this->names->name($entry->actorId, $entry->actorName),
            'what' => MarketingActionWords::WORDS[$entry->action] ?? $entry->action,
            'details' => $this->details->lines($entry->action, array_diff_key($entry->details, ['campaign' => true])),
        ];
    }

    /**
     * The days a campaign's chart shows: its days with figures, the last 92 at most.
     *
     * @param list<FigureRow> $days
     * @return list<FigureRow>
     */
    private static function lastDays(array $days): array
    {
        return array_slice($days, -92);
    }
}
