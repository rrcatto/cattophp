<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Country\Countries;
use CattoPHP\Http\StockPages;
use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Publication;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Sales\SaleTotals;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the Visitors, Sales, campaign and overview pages say of a sale the same way: its state as a badge, its money in
 * its own currency (the site's as the project writes it, another as its code and amount), who bought it (named only to a
 * reader who may see who customers are, else "A customer"), what it held ("Gift box and 2 more"), the campaign it counts
 * for under the first or the last touch, what that came through (a channel and place, "Promotion SPRING10", "From
 * <site>: em1", or "No campaign"), "Sold on <site>" for a connected site's sale, and the addresses of its page and its
 * customer's journey. Also the "A sale counts for" choice (counts=first or last in the address, else the setting) as
 * feature cards.
 */
final class SaleWords
{
    /** The tone of each state a sale is shown in, for its badge. */
    public const TONES = ['paid' => 'success', 'waiting' => 'warning', 'cancelled' => 'neutral', 'partly_refunded' => 'warning', 'refunded' => 'danger', 'charged_back' => 'danger'];

    /** The two ways a sale is counted for a campaign, in words, with the icon of each card. */
    public const MODELS = ['last' => ['Last touch', 'arrow-right-to-line', 'The campaign of the last link the customer followed'],
        'first' => ['First touch', 'arrow-left-to-line', 'The campaign of the first link the customer followed']];

    /** @var array<int,?Campaign> */
    private array $campaignCache = [];
    /** @var array<int,?Publication> */
    private array $publicationCache = [];
    /** @var array<int,?Touch> */
    private array $touchCache = [];
    /** @var array<string,?string> */
    private array $journeyCache = [];
    /** @var array<string,?array{label:string,reference:string,href:?string}> */
    private array $accountCache = [];
    private ?string $currency = null;
    private bool $currencyRead = false;

    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly MarketingProject $project,
        private readonly MarketingSettings $settings,
        private readonly Campaigns $campaigns,
        private readonly Publications $publications,
        private readonly Channels $channels,
        private readonly Touches $touches,
        private readonly Visitors $visitors,
        private readonly Sales $sales,
        private readonly Sites $sites,
    ) {
    }

    /** Whether the reader may see who visitors and customers are. */
    public function maySeePeople(): bool
    {
        return $this->pages->may(MarketingPermissions::PEOPLE);
    }

    /** The model the address asks for (counts=first or last), or null: as MARKETING_SALE_COUNTS_FOR says. */
    public static function asked(Request $request): ?string
    {
        $model = MarketingPages::text($request->query->get('counts', ''));

        return isset(self::MODELS[$model]) ? $model : null;
    }

    /** The model a page counts by: the one asked for, else the setting's. */
    public function model(?string $asked): string
    {
        return $asked ?? $this->settings->saleCountsFor();
    }

    /** The setting's model, first or last. */
    public function setting(): string
    {
        return $this->settings->saleCountsFor();
    }

    /** @return list<array{value:string,label:string,icon:string,note:string}> "A sale counts for" as feature cards, the setting's marked */
    public function modelCards(): array
    {
        $cards = [];
        foreach (self::MODELS as $value => [$label, $icon, $note]) {
            $cards[] = ['value' => $value, 'label' => $label, 'icon' => $this->marketing->icon($icon), 'note' => $note . ($value === $this->setting() ? ', as the settings say' : '')];
        }

        return $cards;
    }

    /** The site's own currency (that of its newest sale made here), or null while there is no sale. */
    public function currency(): ?string
    {
        if (!$this->currencyRead) {
            $this->currency = $this->sales->currency();
            $this->currencyRead = true;
        }

        return $this->currency;
    }

    /** An amount of a sale's, in its currency: as the project writes money for the site's own, else "USD 49.00". */
    public function money(Sale $sale, int $minor): string
    {
        $currency = $this->currency();

        return $currency === null || $sale->currency === $currency ? $this->marketing->money($minor) : $sale->currency . ' ' . MarketingPages::decimal($minor);
    }

    /** Whether a sale is in another currency than the site's, so left out of every total. */
    public function otherCurrency(Sale $sale): bool
    {
        $currency = $this->currency();

        return $currency !== null && $sale->currency !== $currency;
    }

    /** @return array{label:string,tone:string,key:string} a sale's state as a badge */
    public function state(Sale $sale): array
    {
        $key = $sale->shownState();

        return ['label' => Sale::SHOWN[$key], 'tone' => self::TONES[$key], 'key' => $key];
    }

    /** "Sold on <site>" for a connected site's sale, else ''. */
    public function soldOn(Sale $sale): string
    {
        return $sale->originSite === null ? '' : 'Sold on ' . $this->sites->name($sale->originSite);
    }

    /** What a sale held, in words: its first line's label, and how many more ("Gift box and 2 more"). */
    public static function bought(Sale $sale): string
    {
        if ($sale->lines === []) {
            return '';
        }
        $more = count($sale->lines) - 1;

        return $sale->lines[0]->label . ($more > 0 ? ' and ' . $more . ' more' : '');
    }

    /**
     * Who bought a sale, as the reader may see it: the company (with the person who bought for it) or the person, linked
     * to their journey where they have one; "A customer" to a reader who may not see who customers are.
     *
     * @return array{name:string,note:string,href:string}
     */
    public function customer(Sale $sale): array
    {
        if (!$this->maySeePeople()) {
            return ['name' => 'A customer', 'note' => '', 'href' => ''];
        }
        $person = $sale->account === null ? null : $this->account($sale->account);
        $company = $sale->organisation === null ? null : $this->project->organisation($sale->organisation);
        $href = $this->journeyHref($sale) ?? '';
        $visitor = $sale->visitorId === null ? null : $this->visitors->byId($sale->visitorId);
        $name = $person['label'] ?? ($visitor !== null && !$visitor->isAnonymous() ? $visitor->name() : 'Not known');
        if ($company !== null) {
            return ['name' => $company['label'], 'note' => 'Bought by ' . $name, 'href' => $href];
        }

        return ['name' => $name, 'note' => '', 'href' => $href];
    }

    /** The address of the journey of a sale's customer (their account's first visitor, else the visitor that placed it), or null. */
    public function journeyHref(Sale $sale): ?string
    {
        if (!$this->maySeePeople()) {
            return null;
        }
        $key = ($sale->account ?? '') . '|' . ($sale->visitorId ?? '');
        if (!array_key_exists($key, $this->journeyCache)) {
            $reference = null;
            foreach ($sale->account === null ? [] : $this->visitors->ofAccount($sale->account) as $visitor) {
                $reference ??= $visitor->isAnonymous() ? null : $visitor->reference;
            }
            if ($reference === null && $sale->visitorId !== null) {
                $visitor = $this->visitors->byId($sale->visitorId);
                $reference = $visitor !== null && !$visitor->isAnonymous() && $visitor->reference !== '' ? $visitor->reference : null;
            }
            $this->journeyCache[$key] = $reference === null ? null : $this->pages->path('cattophp_marketing_visitor', ['reference' => $reference]);
        }

        return $this->journeyCache[$key];
    }

    /** @return array{label:string,reference:string,href:?string}|null a person as the project names them */
    public function account(string $account): ?array
    {
        if (!array_key_exists($account, $this->accountCache)) {
            $this->accountCache[$account] = $this->project->account($account);
        }

        return $this->accountCache[$account];
    }

    /** @return array{name:string,href:string} the campaign a sale counts for under a model, or "No campaign" */
    public function campaign(Sale $sale, string $model): array
    {
        $campaign = $this->campaignById($sale->campaignId($model));

        return $campaign === null ? ['name' => 'No campaign', 'href' => ''] : ['name' => $campaign->name, 'href' => $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code])];
    }

    /**
     * What the sale came through under a model: the channel and place of the publication followed ("Facebook, Tea lovers
     * group"), the campaign's own link, "Promotion SPRING10" when only the code counts, "From <site>: em1" for an arrival
     * from a connected site, or "No campaign".
     */
    public function cameThrough(Sale $sale, string $model): string
    {
        if ($sale->campaignId($model) === null) {
            return 'No campaign';
        }
        if ($sale->by($model) === 'promotion') {
            return 'Promotion ' . ($sale->promotion ?? '');
        }
        $touchId = $sale->touchId($model);
        $touch = $touchId === null ? null : $this->touch($touchId);
        $publicationId = $sale->publicationId($model);

        return $this->through($touch, $publicationId === null ? null : $this->publication($publicationId));
    }

    /** What a touch came through: its publication's channel and place, an arrival's site and publication there, or the campaign's own link. */
    public function through(?Touch $touch, ?Publication $publication): string
    {
        if ($touch?->originSite !== null) {
            return 'From ' . $this->sites->name($touch->originSite) . ($touch->originPublication !== null && $touch->originPublication !== '' ? ': ' . $touch->originPublication : '');
        }
        if ($publication !== null) {
            $channel = $this->channels->get($publication->channelKey);

            return ($channel->label ?? $publication->channelKey) . ($publication->place !== '' ? ', ' . $publication->place : '');
        }
        $source = $touch === null ? '' : $touch->source;

        return 'Its own link' . ($source !== '' ? ' (' . $source . ')' : '');
    }

    /** The channel of the publication a sale counts for under a model, in words, or ''. */
    public function channel(Sale $sale, string $model): string
    {
        $publicationId = $sale->publicationId($model);
        $publication = $publicationId === null ? null : $this->publication($publicationId);

        return $publication === null ? '' : ($this->channels->get($publication->channelKey)->label ?? $publication->channelKey);
    }

    /** The address of a sale's page: by its reference, and a connected site's by that site's key too. */
    public function href(Sale $sale): string
    {
        return $sale->originSite === null ? $this->pages->path('cattophp_marketing_sale', ['reference' => $sale->reference])
            : $this->pages->path('cattophp_marketing_sale_from_site', ['site' => $sale->originSite, 'reference' => $sale->reference]);
    }

    public static function country(string $code): string
    {
        return $code === '' ? 'Not known' : Countries::name($code);
    }

    /**
     * A sale as a row of a list: when, its reference and page, who, what, the campaign and what it came through, the
     * country, its money (gross, refunds and chargebacks, net; what it is to pay while it is not paid), its state and
     * where it was sold.
     *
     * @return array<string,mixed>
     */
    public function row(Sale $sale, string $model): array
    {
        $state = $this->state($sale);
        $campaign = $this->campaign($sale, $model);
        $customer = $this->customer($sale);

        return [
            'when' => $this->marketing->moment($sale->paidAt ?? $sale->placedAt),
            'when_words' => $sale->isPaid() ? 'Paid' : 'Placed',
            'reference' => $sale->reference,
            'href' => $this->href($sale),
            'customer' => $customer['name'],
            'customer_note' => $customer['note'],
            'customer_href' => $customer['href'],
            'bought' => self::bought($sale),
            'campaign' => $campaign['name'],
            'campaign_href' => $campaign['href'],
            'through' => $this->cameThrough($sale, $model),
            'country' => self::country($sale->country),
            'paid' => $sale->isPaid(),
            'gross' => $this->money($sale, $sale->paidMinor),
            'to_pay' => $this->money($sale, $sale->totalMinor),
            'unpaid' => $this->money($sale, $sale->totalMinor) . ($sale->state === Sale::CANCELLED ? ', never paid' : ' to pay'),
            'refunds' => $this->money($sale, $sale->refundedMinor + $sale->chargedBackMinor),
            'charged_back' => $sale->chargedBackMinor > 0 ? $this->money($sale, $sale->chargedBackMinor) . ' charged back' : '',
            'net' => $this->money($sale, $sale->netMinor()),
            'state' => $state['label'],
            'tone' => $state['tone'],
            'sold_on' => $this->soldOn($sale),
            'other_currency' => $this->otherCurrency($sale),
        ];
    }

    /**
     * The totals of a list as figures, in words: sales, paying customers, gross revenue, discounts, wallet credit used,
     * refunds, chargebacks, net revenue and the average order, with what waits for payment and what was cancelled.
     *
     * @return list<array{label:string,value:string,note:string}>
     */
    public function totals(SaleTotals $totals): array
    {
        $money = fn (int $minor): string => $this->marketing->money($minor);
        $apart = [];
        if ($totals->waitingSales > 0) {
            $apart[] = MarketingPages::count($totals->waitingSales, 'waiting for payment', 'waiting for payment');
        }
        if ($totals->cancelledSales > 0) {
            $apart[] = number_format($totals->cancelledSales) . ' cancelled';
        }
        $figures = [
            ['label' => 'Sales', 'value' => number_format($totals->sales), 'note' => MarketingPages::count($totals->paidSales, 'paid', 'paid') . ($apart !== [] ? ', ' . implode(', ', $apart) : '')],
            ['label' => 'Paying customers', 'value' => number_format($totals->payingCustomers), 'note' => 'Each person or company once'],
            ['label' => 'Gross revenue', 'value' => $money($totals->grossMinor), 'note' => 'What the paid sales were paid'],
            ['label' => 'Discounts', 'value' => $money($totals->discountMinor), 'note' => 'Already taken off the gross revenue'],
            ['label' => 'Wallet credit used', 'value' => $money($totals->walletMinor), 'note' => 'Part of the gross revenue'],
            ['label' => 'Refunds', 'value' => $money($totals->refundedMinor), 'note' => 'Of the sales listed'],
            ['label' => 'Chargebacks', 'value' => $money($totals->chargedBackMinor), 'note' => 'Taken back by the bank'],
            ['label' => 'Net revenue', 'value' => $money($totals->netMinor()), 'note' => 'Gross revenue less refunds and chargebacks'],
            ['label' => 'Average order', 'value' => $money($totals->averageMinor()), 'note' => 'Gross revenue for each paid sale'],
        ];
        if ($totals->waitingSales > 0) {
            $figures[] = ['label' => 'Waiting for payment', 'value' => $money($totals->waitingMinor), 'note' => MarketingPages::count($totals->waitingSales, 'sale', 'sales') . ', never in the revenue'];
        }

        return $figures;
    }

    /**
     * The same totals as a download's last rows, each its label and its figure (money as a spreadsheet reads it, 4150.00),
     * so the file can be read back with the page's totals.
     *
     * @return list<array{0:string,1:int|string}>
     */
    public static function totalsRows(SaleTotals $totals): array
    {
        $decimal = MarketingPages::decimal(...);

        return [['Sales', $totals->sales], ['Paid', $totals->paidSales], ['Waiting for payment', $totals->waitingSales], ['Cancelled', $totals->cancelledSales],
            ['Paying customers', $totals->payingCustomers], ['Gross revenue', $decimal($totals->grossMinor)], ['Discounts', $decimal($totals->discountMinor)],
            ['Wallet credit used', $decimal($totals->walletMinor)], ['Refunds', $decimal($totals->refundedMinor)], ['Chargebacks', $decimal($totals->chargedBackMinor)],
            ['Net revenue', $decimal($totals->netMinor())], ['Average order', $decimal($totals->averageMinor())], ['To pay on waiting sales', $decimal($totals->waitingMinor)],
            ['Currency', $totals->currency ?? '']];
    }

    private function campaignById(?int $id): ?Campaign
    {
        if ($id === null) {
            return null;
        }
        if (!array_key_exists($id, $this->campaignCache)) {
            $this->campaignCache[$id] = $this->campaigns->byId($id);
        }

        return $this->campaignCache[$id];
    }

    private function publication(int $id): ?Publication
    {
        if (!array_key_exists($id, $this->publicationCache)) {
            $this->publicationCache[$id] = $this->publications->byId($id);
        }

        return $this->publicationCache[$id];
    }

    public function touch(int $id): ?Touch
    {
        if (!array_key_exists($id, $this->touchCache)) {
            $this->touchCache[$id] = $this->touches->byId($id);
        }

        return $this->touchCache[$id];
    }

    /** A campaign by its id, kept for the page: for the parts that name one. */
    public function campaignOf(?int $id): ?Campaign
    {
        return $this->campaignById($id);
    }

    public function publicationOf(?int $id): ?Publication
    {
        return $id === null ? null : $this->publication($id);
    }
}
