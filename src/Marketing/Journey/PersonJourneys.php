<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\JourneyEvent;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Publication;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\SaleMovement;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitor;
use CattoPHP\Marketing\Visitors;
use Doctrine\DBAL\Connection;

/**
 * Reads one person's journey (Journey\PersonJourney) for the Visitor page: the person is the visitor asked for and, when
 * they have an account, every visitor tied to it. Their lines are their clicks on tracking links (counted or not, with
 * why), their events (by any of their visitors, or naming their account; an event a second person made on a browser
 * they share, naming that person's account, is not theirs) and each money movement of their sales (paid, each refund,
 * each chargeback, in place of the sale's own paid, refunded and charged-back events). Each paid sale's fixed first and
 * last touch give those clicks their badges.

 */
final class PersonJourneys
{
    private const SITES = 'cattophp_marketing_sites';

    /** The kinds a money movement stands for in the journey, by the movement's kind. */
    private const MONEY = ['paid' => Events::PAID, 'refund' => Events::REFUNDED, 'chargeback' => Events::CHARGED_BACK];

    /** @var array<int,?Campaign> */
    private array $campaignCache = [];
    /** @var array<int,?Publication> */
    private array $publicationCache = [];
    /** @var array<string,?string> */
    private array $offerCache = [];
    /** @var array<string,string> */
    private array $siteCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Visitors $visitors,
        private readonly Touches $touches,
        private readonly Events $events,
        private readonly Campaigns $campaigns,
        private readonly Publications $publications,
        private readonly Channels $channels,
        private readonly MarketingProject $project,
        private readonly Sales $sales,
    ) {
    }

    /** The journey of the person a visitor is (all their visitors together when they have an account). */
    public function of(Visitor $visitor): PersonJourney
    {
        $visitor = $this->visitors->byId($visitor->id) ?? $visitor;
        $account = $visitor->account;
        $visitors = $account === null ? [$visitor] : $this->visitors->ofAccount($account);
        if (!in_array($visitor->id, array_map(static fn(Visitor $v): int => $v->id, $visitors), true)) {
            $visitors[] = $visitor;
        }
        $ids = array_map(static fn(Visitor $v): int => $v->id, $visitors);
        $touches = $this->touches->forVisitors($ids);
        $events = array_values(array_filter($this->events->forPerson($ids, $account), static fn(JourneyEvent $event): bool => $event->account === null || $event->account === $account));
        $sales = $this->sales->forPerson($ids, $account);
        $references = [];
        $movements = [];
        foreach ($sales as $sale) {
            $references[$sale->id] = $sale->reference;
            foreach ($sale->movements as $movement) {
                $movements[] = [$movement, $sale];
            }
        }

        $lines = [];
        foreach ($touches as $touch) {
            $lines[] = $this->touchLine($touch);
        }
        $moved = [];
        foreach ($movements as [$movement]) {
            $moved[$movement->saleId . ':' . self::MONEY[$movement->kind]] = true;
        }
        foreach ($events as $event) {
            // A movement's line stands for its sale's paid, refunded or charged-back event, with its amount.
            if ($event->saleId !== null && isset($moved[$event->saleId . ':' . $event->kind])) {
                continue;
            }
            $lines[] = $this->eventLine($event, $event->saleId === null ? null : ($references[$event->saleId] ?? null));
        }
        foreach ($movements as [$movement, $sale]) {
            $lines[] = $this->moneyLine($movement, $sale);
        }
        $lines = $this->badged($lines, $sales);
        usort($lines, static fn(JourneyLine $a, JourneyLine $b): int => [$a->at, self::order($a->from), $a->id] <=> [$b->at, self::order($b->from), $b->id]);

        $visits = count(array_filter($touches, static fn(Touch $touch): bool => $touch->counted)) + count(array_filter($events, static fn(JourneyEvent $event): bool => $event->kind === Events::CAME_BACK));
        $campaignIds = [];
        foreach ($touches as $touch) {
            if ($touch->counted && !in_array($touch->campaignId, $campaignIds, true)) {
                $campaignIds[] = $touch->campaignId;
            }
        }
        $state = match (true) {
            array_filter($sales, static fn(Sale $sale): bool => $sale->state === Sale::PAID) !== [] => PersonJourney::PAYING,
            array_filter($sales, static fn(Sale $sale): bool => $sale->state === Sale::WAITING) !== [] => PersonJourney::WAITING,
            $account !== null => PersonJourney::REGISTERED,
            default => PersonJourney::NOT_REGISTERED,
        };
        $devices = [];
        foreach ($visitors as $each) {
            if ($each->device !== 'unknown' && !in_array($each->device, $devices, true)) {
                $devices[] = $each->device;
            }
        }
        $countries = [];
        foreach ([...array_map(static fn(Visitor $v): string => $v->country, $visitors), ...array_map(static fn(Touch $t): string => $t->country, $touches),
            ...array_map(static fn(JourneyEvent $e): string => $e->country, $events)] as $country) {
            if ($country !== '' && !in_array($country, $countries, true)) {
                $countries[] = $country;
            }
        }

        return new PersonJourney($visitor, $account, $visitors, $lines, $visits, $campaignIds, $sales, $state, $devices, $countries);
    }

    /** The journey of the person an account is, through the first of their visitors; null when no visitor is tied to it. */
    public function ofAccount(string $account): ?PersonJourney
    {
        $visitors = $this->visitors->ofAccount($account);

        return $visitors === [] ? null : $this->of($visitors[0]);
    }

    private function touchLine(Touch $touch): JourneyLine
    {
        $publication = $touch->publicationId === null ? null : $this->publication($touch->publicationId);
        $campaign = $this->campaign($touch->campaignId);
        $channelKey = $publication->channelKey ?? ($touch->source !== '' ? $touch->source : null);
        $channel = $channelKey === null ? null : ($this->channels->get($channelKey)->label ?? null);
        if ($touch->originSite !== null) {
            $words = 'Arrived from ' . $this->siteName($touch->originSite);
            $detail = trim(($touch->originPublication ?? '') . ($channel !== null ? ' (' . $channel . ')' : ''));
        } else {
            $words = $touch->counted ? 'Followed a link' : 'Opened a link, not counted';
            $detail = $touch->counted ? trim(($publication->code ?? '') . ($channel !== null ? ' (' . $channel . ')' : '')) : (Touch::NOT_COUNTED[$touch->notCountedBecause ?? ''] ?? '');
        }

        return new JourneyLine($touch->at, JourneyLine::FROM_TOUCH, $touch->id, 'touch', $words, $detail, $touch->campaignId, $campaign?->name, $campaign?->code, $touch->publicationId,
            $publication->code ?? $touch->originPublication, $channel, visitorId: $touch->visitorId, counted: $touch->counted);
    }

    private function eventLine(JourneyEvent $event, ?string $sale): JourneyLine
    {
        $campaign = $event->campaignId === null ? null : $this->campaign($event->campaignId);
        $publication = $event->publicationId === null ? null : $this->publication($event->publicationId);
        $offer = $event->offerType !== null && $event->offerReference !== null ? $this->offerLabel($event->offerType, $event->offerReference) : null;
        $what = $offer ?? 'an offer';
        $text = static fn(string $key): string => is_scalar($event->detail[$key] ?? null) ? (string) $event->detail[$key] : '';
        [$words, $detail] = match ($event->kind) {
            Events::LANDING_OPENED => ['Opened a landing page', ''],
            Events::LANDING_PRESSED => ['Pressed a button on a landing page', $text('action')],
            Events::OFFER_VIEWED => ['Viewed ' . $what, ''],
            Events::FAVOURITED => ['Favourited ' . $what, ''],
            Events::UNFAVOURITED => ['Took ' . $what . ' out of their favourites', ''],
            Events::BASKET_ADDED => ['Put ' . $what . ' in the basket', $text('quantity') !== '' && $text('quantity') !== '1' ? $text('quantity') . ' of them' : ''],
            Events::BASKET_REMOVED => ['Took ' . $what . ' out of the basket', ''],
            Events::TAKEN => ['Took ' . $what . ', free', ''],
            Events::SUBSCRIBED => ['Subscribed to ' . ($text('list') !== '' ? $text('list') : 'a list'), ''],
            Events::REGISTERED => ['Registered', ''],
            Events::SIGNED_IN => ['Signed in', ''],
            Events::CAME_BACK => ['Came back', match ($text('from')) {
                'typed', '' => 'Typed the address or used a bookmark',
                'here' => 'On a page of this site left open',
                default => 'From ' . $text('from'),
            }],
            Events::EMAIL_OPENED => ['Opened an email', $this->source($publication)],
            Events::ORDER_CREATED => ['Placed an order' . ($sale !== null ? ' (' . $sale . ')' : ''), ''],
            Events::PAYMENT_FAILED => ['A payment did not go through' . ($sale !== null ? ' (' . $sale . ')' : ''), $text('why')],
            Events::ORDER_CANCELLED => ['The order was cancelled' . ($sale !== null ? ' (' . $sale . ')' : ''), $text('why')],
            Events::PAID => ['Paid' . ($sale !== null ? ' for ' . $sale : ''), ''],
            Events::REFUNDED => ['Refunded' . ($sale !== null ? ' on ' . $sale : ''), ''],
            Events::CHARGED_BACK => ['Charged back' . ($sale !== null ? ' on ' . $sale : ''), ''],
            default => [ucfirst(str_replace('_', ' ', $event->kind)), ''],
        };
        $badges = match ($event->kind) {
            Events::REGISTERED => [JourneyLine::REGISTRATION],
            Events::PAYMENT_FAILED => [JourneyLine::FAILED],
            Events::PAID => [JourneyLine::SALE],
            default => [],
        };
        $channel = $publication === null ? null : ($this->channels->get($publication->channelKey)->label ?? null);

        return new JourneyLine($event->at, JourneyLine::FROM_EVENT, $event->id, $event->kind, $words, $detail, $event->campaignId, $campaign?->name, $campaign?->code, $event->publicationId,
            $publication?->code, $channel, $event->offerType, $event->offerReference, $offer, $sale, visitorId: $event->visitorId, badges: $badges);
    }

    private function moneyLine(SaleMovement $movement, Sale $sale): JourneyLine
    {
        $amount = $this->project->money($movement->amountMinor);
        $words = match ($movement->kind) {
            'paid' => 'Paid ' . $amount . ' for ' . $sale->reference,
            'refund' => 'Refunded ' . $amount . ' on ' . $sale->reference,
            default => 'Charged back ' . $amount . ' on ' . $sale->reference,
        };
        $campaignId = $sale->lastCampaignId ?? $sale->firstCampaignId;
        $campaign = $campaignId === null ? null : $this->campaign($campaignId);

        return new JourneyLine($movement->at, JourneyLine::FROM_MONEY, $movement->id, self::MONEY[$movement->kind], $words, $movement->how, $campaignId, $campaign?->name, $campaign?->code,
            saleReference: $sale->reference, amountMinor: $movement->amountMinor, badges: $movement->kind === 'paid' ? [JourneyLine::SALE] : []);
    }

    /**
     * Each paid sale's fixed first and last touch, as badges on those clicks.
     *
     * @param list<JourneyLine> $lines
     * @param list<Sale> $sales
     * @return list<JourneyLine>
     */
    private function badged(array $lines, array $sales): array
    {
        $badges = [];
        foreach ($sales as $sale) {
            if (!$sale->fixed) {
                continue;
            }
            if ($sale->firstTouchId !== null) {
                $badges[$sale->firstTouchId][] = JourneyLine::FIRST_TOUCH;
            }
            if ($sale->lastTouchId !== null) {
                $badges[$sale->lastTouchId][] = JourneyLine::LAST_TOUCH;
            }
        }

        return array_map(static function (JourneyLine $line) use ($badges): JourneyLine {
            if ($line->from === JourneyLine::FROM_TOUCH) {
                foreach ($badges[$line->id] ?? [] as $badge) {
                    $line = $line->withBadge($badge);
                }
            }

            return $line;
        }, $lines);
    }

    private function campaign(int $id): ?Campaign
    {
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

    private function offerLabel(string $type, string $reference): ?string
    {
        $key = $type . ':' . $reference;
        if (!array_key_exists($key, $this->offerCache)) {
            $this->offerCache[$key] = $this->project->offer($type, $reference)?->label;
        }

        return $this->offerCache[$key];
    }

    /** What a publication was written in (a mailing message's name), as the project names it, else its place. */
    private function source(?Publication $publication): string
    {
        if ($publication === null) {
            return '';
        }
        $source = $publication->sourceType !== null && $publication->sourceReference !== null ? $this->project->publicationSource($publication->sourceType, $publication->sourceReference) : null;

        return $source['label'] ?? $publication->place;
    }

    /** A connected site's name, kept on its record after it is removed; its key when it is not known. */
    private function siteName(string $key): string
    {
        if (!array_key_exists($key, $this->siteCache)) {
            $name = $this->connection->fetchOne('SELECT name FROM ' . self::SITES . ' WHERE key = :key', ['key' => $key]);
            $this->siteCache[$key] = is_string($name) && $name !== '' ? $name : $key;
        }

        return $this->siteCache[$key];
    }

    /** At the same moment a click comes before an event, and an event before its money. */
    private static function order(string $from): int
    {
        return match ($from) {
            JourneyLine::FROM_TOUCH => 0,
            JourneyLine::FROM_EVENT => 1,
            default => 2,
        };
    }
}
