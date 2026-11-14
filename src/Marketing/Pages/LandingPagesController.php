<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\CampaignSummary;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingPageSummary;
use CattoPHP\Marketing\Landing\LandingProject;
use CattoPHP\Marketing\Landing\LandingTemplate;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Offer;
use CattoPHP\Ui\Pager;
use CattoPHP\Ui\Pagination;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The landing pages (`config/routes/marketing.php`): every page, a campaign's or an offer's, with its title, what it is
 * for, its address, status, sections, last change and visits over the recent setting, narrowed by feature cards, searched,
 * sorted and paged; the saved templates, each deleted from here; and a new page, for a campaign or an offer that has none,
 * started from a template. Seeing them needs MARKETING.VIEW (an offer's page links to its editor only for a reader its
 * project lets see it); making a page and deleting a template MARKETING.CAMPAIGN.MANAGE.
 */
final class LandingPagesController
{
    /** The list's name: its search (landing_q), pager (landing_page) and sort (landing_sort, landing_dir). */
    public const LIST = 'landing';

    /** What the list may show, the icon of each card. */
    private const FILTERS = ['all' => ['All', 'list'], 'campaign' => ['Campaign pages', 'megaphone'], 'offer' => ['', 'tag'], 'draft' => ['Drafts', 'pencil-line'], 'published' => ['Published', 'globe']];

    /** The list's columns: what each sorts by (none for those that do not), its heading, and whether it holds numbers. */
    private const COLUMNS = [['title', 'Page', false], ['', 'For', false], ['', 'Address', false], ['status', 'Status', false], ['sections', 'Sections', true], ['updated', 'Last change', false],
        ['visits', 'Visits', true]];

    public function __construct(
        private readonly StockPages $stock,
        private readonly MarketingPages $marketing,
        private readonly LandingPages $pages,
        private readonly LandingTemplates $templates,
        private readonly Campaigns $campaigns,
        private readonly MarketingProject $project,
        private readonly LandingProject $landing,
        private readonly MarketingSettings $settings,
    ) {
    }

    public function list(Request $request): Response
    {
        $this->stock->deny(MarketingPermissions::VIEW);
        $filter = MarketingPages::text($request->query->get('show', 'all'));
        $filter = isset(self::FILTERS[$filter]) ? $filter : 'all';
        $search = mb_substr(trim(MarketingPages::text($request->query->get(self::LIST . '_q', ''))), 0, 120);
        $sort = MarketingPages::text($request->query->get(self::LIST . '_sort', 'updated'));
        $sort = in_array($sort, LandingPages::SORTS, true) ? $sort : 'updated';
        $direction = MarketingPages::text($request->query->get(self::LIST . '_dir', ''));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : (in_array($sort, ['title', 'status'], true) ? 'asc' : 'desc');
        $total = $this->pages->count($filter, $search);
        $pagination = Pagination::create($request->query->get(self::LIST . '_page'), $request->query->get(self::LIST . '_page_size'), $total);
        $base = $this->stock->path('cattophp_marketing_landing_pages');
        $state = ['show' => $filter, self::LIST . '_q' => $search, self::LIST . '_sort' => $sort, self::LIST . '_dir' => $direction];
        $counts = $this->pages->filterCounts();
        $cards = [];
        foreach (self::FILTERS as $value => [$label, $icon]) {
            $count = $counts[$value] ?? 0;
            $cards[] = ['value' => $value, 'label' => $value === 'offer' ? 'Pages of ' . mb_strtolower($this->project->offersHeading()) : $label, 'icon' => $this->marketing->icon($icon),
                'note' => $count === 0 ? 'None' : MarketingPages::count($count, 'page', 'pages')];
        }
        $headers = [];
        foreach (self::COLUMNS as [$key, $label, $end]) {
            $next = $key === $sort ? ($direction === 'asc' ? 'desc' : 'asc') : (in_array($key, ['title', 'status'], true) ? 'asc' : 'desc');
            $headers[] = ['sortable' => $key !== '', 'key' => $key, 'label' => $label, 'width' => 0, 'end' => $end, 'active' => $key !== '' && $key === $sort, 'direction' => $direction,
                'href' => $base . '?' . http_build_query(array_filter([...$state, self::LIST . '_sort' => $key, self::LIST . '_dir' => $next], static fn (string $value): bool => $value !== ''))];
        }
        $mayManage = $this->stock->may(MarketingPermissions::MANAGE);

        return $this->stock->page($request, 'marketing-landing-pages', '@CattoPHP/marketing/landing-pages.html.twig', [
            'title' => 'Landing pages',
            'lead' => 'The pages visitors land on: each campaign\'s, at its tracking link\'s address, and each of the ' . mb_strtolower($this->project->offersHeading()) . ' that has one.',
            'kicker' => 'Campaign Marketing Manager',
            'may_manage' => $mayManage,
            'show' => $filter,
            'show_cards' => $cards,
            'show_words' => $filter === 'offer' ? 'Pages of ' . mb_strtolower($this->project->offersHeading()) : self::FILTERS[$filter][0],
            'searched' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'total' => $total,
            'any' => ($counts['all'] ?? 0) > 0,
            'recent' => $this->settings->landingRecent()->words(),
            'landing_pages' => array_map($this->row(...), $this->pages->list($filter, $search, $sort, $direction, $pagination->pageSize, $pagination->offset)),
            'headers' => $headers,
            'search' => ['ds_name' => self::LIST, 'ds_label' => 'Search the landing pages', 'ds_action' => $base, 'ds_value' => $search, 'ds_push_url' => true,
                'ds_hidden' => ['show' => $filter, self::LIST . '_sort' => $sort, self::LIST . '_dir' => $direction], 'ds_help' => 'Searches each page\'s headline, title, address, campaign and offer.'],
            'pagination' => Pager::payload($pagination, self::LIST, $base, 'Landing pages', $state),
            'base' => $base,
            'saved_templates' => $mayManage ? array_map(fn (LandingTemplate $template): array => ['name' => $template->name, 'note' => $template->note, 'reference' => (string) $template->reference,
                'delete_action' => $this->stock->path('cattophp_marketing_landing_template_delete', ['template' => (string) $template->reference])], $this->templates->saved()) : [],
        ]);
    }

    public function new(Request $request): Response
    {
        $this->stock->deny(MarketingPermissions::MANAGE);
        $action = $this->stock->path('cattophp_marketing_landing_make');
        $errors = $this->stock->errors($request);
        $typed = TypedForm::take($request, $action);
        $campaigns = array_values(array_filter($this->campaigns->list(null, '', 'name', 'asc', 1000), fn (CampaignSummary $summary): bool => $summary->campaign->status !== 'archived'
            && $this->pages->forCampaign($summary->campaign) === null));
        $offers = array_values(array_filter($this->project->offers(), fn (Offer $offer): bool => $this->pages->forOffer($offer->type, $offer->reference) === null));
        $campaign = MarketingPages::text($typed['campaign'] ?? $request->query->get('campaign', ''));
        $offer = MarketingPages::text($typed['offer'] ?? $request->query->get('offer', ''));
        $for = MarketingPages::text($typed['for'] ?? ($offer !== '' && $campaign === '' ? 'offer' : 'campaign'));
        $kinds = $this->project->offerKinds();
        $heading = $this->project->offersHeading();
        $forCards = [['value' => 'campaign', 'label' => 'A campaign\'s page', 'icon' => $this->marketing->icon('megaphone'), 'note' => 'At its tracking link\'s address, with several offers']];
        if ($offers !== []) {
            $forCards[] = ['value' => 'offer', 'label' => 'A page of one of the ' . mb_strtolower($heading), 'icon' => $this->marketing->icon('tag'), 'note' => 'At an address of its own, selling it alone'];
        }
        $templates = [];
        foreach ([LandingContext::CAMPAIGN => 'campaign pages', LandingContext::OFFER => 'pages of ' . mb_strtolower($heading)] as $kind => $words) {
            foreach ($this->templates->builtIn() as $template) {
                if ($template->suits === 'both' || $template->suits === $kind) {
                    $templates[$template->key] = $template;
                }
            }
        }
        $cards = [];
        foreach ([...array_values($templates), ...$this->templates->saved()] as $template) {
            $suits = match ($template->suits) { 'campaign' => 'For campaign pages', 'offer' => 'For pages of ' . mb_strtolower($heading), default => '' };
            $cards[] = ['value' => $template->key, 'label' => $template->name, 'icon' => $this->marketing->icon($template->builtIn ? 'layout-template' : 'bookmark'),
                'note' => trim(($template->note !== '' ? $template->note : 'Saved by someone here') . ($suits !== '' ? '. ' . $suits : ''))];
        }

        return $this->stock->page($request, 'marketing-landing-new', '@CattoPHP/marketing/landing-new.html.twig', [
            'title' => 'New landing page',
            'lead' => 'It is made as a draft from the template you choose, and seen only in its preview until you publish it.',
            'kicker' => 'Campaign Marketing Manager',
            'action' => $action,
            'for' => $for,
            'for_cards' => $forCards,
            'campaigns' => array_map(static fn (CampaignSummary $summary): array => ['value' => $summary->campaign->code, 'label' => $summary->campaign->name], $campaigns),
            'campaign' => $campaign,
            'offers' => array_map(static fn (Offer $one): array => ['value' => $one->key(), 'label' => $one->label . (isset($kinds[$one->type]) ? ' (' . $kinds[$one->type] . ')' : '')], $offers),
            'offer' => $offer,
            'offers_heading' => $heading,
            'template_cards' => $cards,
            'template' => MarketingPages::text($typed['template'] ?? ($cards[0]['value'] ?? '')),
            'errors' => $errors + ['for' => '', 'campaign' => '', 'offer' => '', 'template' => ''],
            'back_href' => $this->stock->path('cattophp_marketing_landing_pages'),
        ]);
    }

    public function make(Request $request): RedirectResponse
    {
        $this->stock->deny(MarketingPermissions::MANAGE);
        $back = MarketingPages::text($request->request->get('back', '')) === 'campaign' ? 'campaign' : 'new';
        $campaign = MarketingPages::text($request->request->get('campaign', ''));
        [$route, $parameters, $fragment] = $back === 'campaign' && $this->campaigns->get($campaign) !== null
            ? ['cattophp_marketing_campaign', ['code' => strtolower($campaign)], 'landing-page'] : ['cattophp_marketing_landing_new', [], ''];
        if (!$this->stock->tokenIsValid($request)) {
            return $this->stock->expired($request, $route, $parameters, $fragment);
        }
        $template = MarketingPages::text($request->request->get('template', ''));
        try {
            if (MarketingPages::text($request->request->get('for', 'campaign')) === 'offer') {
                $parts = explode(':', MarketingPages::text($request->request->get('offer', '')), 2);
                if (count($parts) !== 2 || $this->project->offer($parts[0], $parts[1]) === null) {
                    throw new MarketingRefused(['offer' => 'Choose one of the ' . mb_strtolower($this->project->offersHeading()) . '.']);
                }
                $result = $this->pages->createForOffer($parts[0], $parts[1], $template, $this->stock->actor());
            } else {
                if ($this->campaigns->get($campaign) === null) {
                    throw new MarketingRefused(['campaign' => 'Choose one of the campaigns.']);
                }
                $result = $this->pages->createForCampaign($campaign, $template, $this->stock->actor());
            }
        } catch (MarketingRefused $refused) {
            return $this->stock->refused($request, CampaignsController::why($refused, 'The landing page was not made'), $route, $parameters, $fragment, errors: $refused);
        }
        $skipped = $result->skipped === [] ? '' : ' Left out, since they do not belong on this page: ' . implode(', ', $result->skipped) . '.';

        return $this->stock->done($request, 'The landing page is made as a draft at ' . $this->pages->address($result->page) . '. Write its copy, then publish it.' . $skipped,
            'cattophp_marketing_landing_edit', ['reference' => $result->page->reference]);
    }

    /** Deletes a saved template: pages made from it keep what they took. */
    public function deleteTemplate(Request $request, string $template): RedirectResponse
    {
        $this->stock->deny(MarketingPermissions::MANAGE);
        if (!$this->stock->tokenIsValid($request)) {
            return $this->stock->expired($request, 'cattophp_marketing_landing_pages', [], 'templates');
        }
        $saved = $this->templates->get(LandingTemplates::SAVED . $template);
        if ($saved === null) {
            return $this->stock->refused($request, 'That template is not saved here now, so nothing was deleted.', 'cattophp_marketing_landing_pages', [], 'templates');
        }
        $this->templates->delete((string) $saved->reference, $this->stock->actor());

        return $this->stock->done($request, 'The template ' . $saved->name . ' is deleted. Pages made from it keep what they took.', 'cattophp_marketing_landing_pages', [], 'templates');
    }

    /** @return array<string,mixed> a page as a row of the list */
    private function row(LandingPageSummary $summary): array
    {
        $page = $summary->page;
        $offer = $page->isOfferPage() ? $this->project->offer((string) $page->offerType, (string) $page->offerReference) : null;
        $mayOpen = $page->isCampaignPage() || $this->landing->access($page, $this->stock->reader())->see;

        return [
            'reference' => $page->reference,
            'title' => $summary->title,
            'href' => $mayOpen ? $this->stock->path('cattophp_marketing_landing_edit', ['reference' => $page->reference]) : '',
            'kind' => $page->isCampaignPage() ? 'Campaign' : ($this->project->offerKinds()[(string) $page->offerType] ?? 'Offer'),
            'about' => $summary->about,
            'about_href' => $page->isCampaignPage() ? $this->stock->path('cattophp_marketing_campaign', ['code' => (string) $summary->campaignCode]) : (string) ($offer->href ?? ''),
            'address' => $summary->address,
            'published' => $page->isPublished(),
            'status' => $page->isPublished() ? 'Published' : 'Draft',
            'tone' => $page->isPublished() ? 'success' : 'neutral',
            'sections' => number_format($summary->sections),
            'changed' => $this->marketing->day($page->updatedAt),
            'visits' => number_format($summary->visits),
        ];
    }
}
