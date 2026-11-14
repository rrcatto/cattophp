<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\EventInput;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Rows;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\TouchAgent;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Network\NetworkLookup;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/*
 * For connected sites (Marketing\Sites\ReportReceiver), the one call it makes, kept stable:
 *
 *     public function reported(Site $from, ReportedSale $report): string
 *
 * - Gives Sales::RECORDED, Sales::UNKNOWN_CLICK (the report's click reference is none of this site's touches; nothing is
 *   written) or Sales::REFUSED (the report cannot be kept, such as another currency for a sale already kept, or more taken
 *   back than was paid; nothing is written, the reason is logged). The receiver keeps that result in reports in.
 * - Writes or updates the other site's sale, keyed by (the site's key, the sale's reference): its state, money and lines
 *   as the report says; its first or last touch (by = site) is this site's touch the click reference names, as the
 *   report's first and last flags say; a sale_paid report fixes it. A movement (paid, or the refund or chargeback by its
 *   key) is kept once. A report older than what is kept (order_placed after sale_paid) changes no state or amount.
 * - Records the journey's event (order_created, order_cancelled, paid, refunded, charged_back) with the touch's visitor,
 *   campaign and publication, origin_site the sender, keyed site:<site key>:<report key>, so the receiver need not.
 * - Runs in its own transaction (a savepoint inside the receiver's), announcing SaleChanged (reported) inside it. A fault
 *   of the database is rolled back to that savepoint, logged and thrown again, so the receiver can answer with an error
 *   and the other site tries again.
 */

/**
 * Every order the project reports, whether or not a campaign brought it, and every sale a connected site reports: what
 * it holds, its money as it happened, and which campaign gets it. The project calls placed() when an order is placed,
 * paid() when it is paid, failed() for a payment that failed, cancelled() for an unpaid order cancelled, refunded() for
 * each refund and chargedBack() for each chargeback, each with the same whole description (SaleReport) or the order's
 * reference. Every call is idempotent: a repeat changes nothing, so the project may report again whatever it is not sure
 * was kept (has(), hasMovement()).
 *
 * A project's report can never stop a purchase: one with problems is refused with the reason in the log; each write runs
 * in its own transaction, a savepoint inside the project's own, and a fault rolls back to that savepoint only, is logged
 * and gives null, the caller's work carrying on. SaleChanged is announced inside the same transaction, so what a listener
 * writes goes in with the sale or not at all.
 *
 * Revenue counts only once paid; refunds and chargebacks are amounts against lines, never states. When a sale is paid
 * its attribution (SaleAttribution) is worked out counted back from the payment with the settings as they are then
 * (MARKETING_SALE_COUNTS_WITHIN, MARKETING_PROMOTION_COUNTS) and fixed; a later change of either counts for sales paid
 * from then on. The pages count a sale for its first or last touch as MARKETING_SALE_COUNTS_FOR says, one at a time, so
 * no sale is counted for two campaigns. The journey's events of a sale are recorded only for a person the module follows
 * (a visitor placed it, its account has a visitor, or a campaign gets it), as every journey event is.
 */
final class Sales
{
    public const TABLE = 'cattophp_marketing_sales';
    public const LINES = 'cattophp_marketing_sale_lines';
    public const MOVEMENTS = 'cattophp_marketing_sale_movements';

    /** What reported() gives, as reports in keep it. */
    public const RECORDED = 'recorded';
    public const UNKNOWN_CLICK = 'unknown_click';
    public const REFUSED = 'refused';

    /** The orders a list may be sorted in. */
    public const SORTS = ['when', 'placed', 'reference', 'amount', 'refunds', 'net', 'country', 'state'];

    /** A connected site's kind of report, and the journey's event it is. */
    private const REPORTED_EVENTS = ['order_placed' => Events::ORDER_CREATED, 'order_cancelled' => Events::ORDER_CANCELLED, 'sale_paid' => Events::PAID,
        'sale_refunded' => Events::REFUNDED, 'sale_charged_back' => Events::CHARGED_BACK];

    private const SORT_SQL = ['when' => 'COALESCE(s.paid_at, s.placed_at)', 'placed' => 's.placed_at', 'reference' => 's.reference',
        'amount' => "CASE WHEN s.state = 'paid' THEN s.paid_minor ELSE s.total_minor END", 'refunds' => '(s.refunded_minor + s.charged_back_minor)',
        'net' => '(s.paid_minor - s.refunded_minor - s.charged_back_minor)', 'country' => 's.country', 'state' => 's.state'];

    private readonly ClockInterface $clock;
    private readonly DateTimeZone $utc;

    public function __construct(
        private readonly Connection $connection,
        private readonly Events $events,
        private readonly Visitors $visitors,
        private readonly Touches $touches,
        private readonly SaleAttribution $attribution,
        private readonly MarketingSettings $settings = new MarketingSettings(),
        private readonly ?NetworkLookup $network = null,
        private readonly ?RequestStack $requests = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * An order placed: written as waiting, with its lines, the placing browser's visitor and country when it is followed
     * (the request given, else the current one), and a provisional first and last touch counted back from now. Records
     * order_created (key order_created:<reference>). A repeat changes nothing while the description is the same; one that
     * differs updates a waiting sale; a paid sale never changes; a cancelled sale placed again goes back to waiting.
     * Gives the sale as kept, or null when the report was refused or could not be kept (logged).
     */
    public function placed(SaleReport $sale, ?Request $request = null): ?Sale
    {
        if (!$this->acceptable($sale, 'placed')) {
            return null;
        }

        return $this->guarded('an order placed', $sale->reference, function () use ($sale, $request): Sale {
            [$visitorId, $country] = $this->reader($request ?? $this->requests?->getMainRequest());
            [$row, $inserted] = $this->lockedOrInserted($sale, $visitorId, $country);
            $id = Rows::int($row['id']);
            $kept = $this->read($id);
            if ($kept->isPaid() || (!$inserted && $kept->state === Sale::WAITING && $this->sameAs($kept, $sale))) {
                return $kept;
            }
            if (!$inserted) {
                $this->rewrite($id, $sale, $kept->visitorId ?? $visitorId, $kept->country !== '' ? $kept->country : $country);
            }
            $this->writeLines($id, $sale->lines);
            $now = $this->clock->now();
            $this->attribute($id, $this->attribution->for($sale->account, $kept->visitorId ?? $visitorId, $now, $this->settings->saleCountsWithin(), $sale->promotion,
                $this->settings->promotionCounts()), false);
            $placed = $this->read($id);
            $this->event($placed, Events::ORDER_CREATED, $sale->placedAt, 'order_created:' . $sale->reference, ['amount_minor' => $sale->totalMinor]);
            $this->announce(new SaleChanged($placed, SaleChanged::PLACED));

            return $placed;
        });
    }

    /**
     * An order paid: the sale written if it was missing (so a lost placed() is mended) or brought up to the description,
     * set paid with what was paid (its total, wallet credit included), its attribution worked out counted back from $at
     * and fixed, the paid movement added (key paid) and paid recorded (key paid:<reference>). A sale already paid changes
     * nothing. $how is a few words from the project, such as Card or EFT. Gives the sale, or null (logged).
     */
    public function paid(SaleReport $sale, DateTimeImmutable $at, string $how = ''): ?Sale
    {
        if (!$this->acceptable($sale, 'paid')) {
            return null;
        }

        return $this->guarded('an order paid', $sale->reference, function () use ($sale, $at, $how): Sale {
            [$row, $inserted] = $this->lockedOrInserted($sale, null, '');
            $id = Rows::int($row['id']);
            $kept = $this->read($id);
            if ($kept->isPaid()) {
                return $kept;
            }
            if (!$inserted) {
                $this->rewrite($id, $sale, $kept->visitorId, $kept->country);
            }
            $this->writeLines($id, $sale->lines);
            $attribution = $this->attribution->for($sale->account, $kept->visitorId, $at, $this->settings->saleCountsWithin(), $sale->promotion, $this->settings->promotionCounts());
            $this->connection->executeStatement("UPDATE " . self::TABLE . " SET state = 'paid', paid_at = :at, paid_minor = total_minor, cancelled_at = NULL, updated_at = :now WHERE id = :id",
                ['at' => Rows::sql($at), 'now' => Rows::sql($this->clock->now()), 'id' => $id]);
            $this->attribute($id, $attribution, true);
            $lines = [];
            foreach ($sale->lines as $line) {
                $lines[$line->key] = $line->paidMinor();
            }
            $movement = $this->addMovement($id, SaleMovement::PAID, SaleMovement::PAID, $sale->totalMinor, $at, $lines, $how);
            $paid = $this->read($id);
            if ($inserted) {
                $this->event($paid, Events::ORDER_CREATED, $sale->placedAt, 'order_created:' . $sale->reference, ['amount_minor' => $sale->totalMinor]);
            }
            $this->event($paid, Events::PAID, $at, 'paid:' . $sale->reference, ['amount_minor' => $sale->totalMinor, 'how' => $how]);
            $this->announce(new SaleChanged($paid, SaleChanged::PAID, $movement));

            return $paid;
        });
    }

    /**
     * A payment that failed or was given up, with the reason in words ("The card was declined by the bank."): recorded as
     * payment_failed (key payment_failed:<reference>:<attempt>, the project's name for the attempt). The sale stays as it
     * is. Gives whether it is recorded now or was before; false for a sale not known, or a fault (logged).
     */
    public function failed(string $reference, string $attempt, string $why, DateTimeImmutable $at): bool
    {
        return $this->guarded('a payment that failed', $reference, function () use ($reference, $attempt, $why, $at): bool {
            $sale = $this->get($reference);
            if ($sale === null) {
                $this->logger->warning('The Campaign Marketing Manager was told a payment failed for sale {reference}, which it does not know; nothing was recorded.', ['reference' => $reference]);

                return false;
            }
            $this->event($sale, Events::PAYMENT_FAILED, $at, 'payment_failed:' . $reference . ':' . $attempt, ['why' => $why, 'attempt' => mb_substr($attempt, 0, 40)]);

            return true;
        }) ?? false;
    }

    /**
     * An unpaid order cancelled (at its deadline, or by the customer): a waiting sale becomes cancelled and
     * order_cancelled is recorded (key order_cancelled:<reference>:<n>, n counting its cancellations, as a sale placed
     * again may be cancelled again). A sale paid or already cancelled changes nothing. Gives the sale, or null for a sale
     * not known or a fault (logged).
     */
    public function cancelled(string $reference, DateTimeImmutable $at, string $why = ''): ?Sale
    {
        return $this->guarded('an order cancelled', $reference, function () use ($reference, $at, $why): ?Sale {
            $row = $this->locked(null, $reference);
            if ($row === null) {
                $this->logger->warning('The Campaign Marketing Manager was told sale {reference} was cancelled, which it does not know; nothing was recorded.', ['reference' => $reference]);

                return null;
            }
            $id = Rows::int($row['id']);
            if (Rows::text($row['state']) !== Sale::WAITING) {
                return $this->byId($id);
            }
            $this->connection->executeStatement("UPDATE " . self::TABLE . " SET state = 'cancelled', cancelled_at = :at, updated_at = :now WHERE id = :id",
                ['at' => Rows::sql($at), 'now' => Rows::sql($this->clock->now()), 'id' => $id]);
            $times = Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM ' . Events::TABLE . ' WHERE sale_id = :id AND kind = :kind', ['id' => $id, 'kind' => Events::ORDER_CANCELLED]));
            $sale = $this->read($id);
            $this->event($sale, Events::ORDER_CANCELLED, $at, 'order_cancelled:' . $reference . ':' . ($times + 1), ['why' => $why]);
            $this->announce(new SaleChanged($sale, SaleChanged::CANCELLED));

            return $sale;
        });
    }

    /**
     * A refund of a paid sale, by the project's key for it (refund:<its id>): $lines gives each line's part by the line's
     * key. It adds the refund movement, raises the lines' and the sale's refunds and records refunded (key
     * refunded:<reference>:<key>). It refuses more than a line was paid, after what was taken back before. A key kept
     * before changes nothing. $how is a few words, such as Money back or To the wallet. Gives the sale, or null (logged).
     *
     * @param array<string,int> $lines
     */
    public function refunded(string $reference, string $key, array $lines, DateTimeImmutable $at, string $how = ''): ?Sale
    {
        return $this->takeBack(SaleMovement::REFUND, $reference, $key, $lines, null, $at, $how);
    }

    /**
     * A chargeback (money the bank took back) of a paid sale, as a refund, kind chargeback, by the project's key for it.
     * Without lines, the amount is shared over the lines by what each has left after earlier refunds and chargebacks
     * (largest remainder, so the parts add up exactly). Records charged_back (key charged_back:<reference>:<key>).
     *
     * @param array<string,int> $lines
     */
    public function chargedBack(string $reference, string $key, int $amount, DateTimeImmutable $at, array $lines = [], string $how = ''): ?Sale
    {
        return $this->takeBack(SaleMovement::CHARGEBACK, $reference, $key, $lines, $amount, $at, $how);
    }

    /** See the comment at the top of this file: kept stable for connected sites. */
    public function reported(Site $from, ReportedSale $report): string
    {
        $touch = $this->touches->byReference($report->clickReference);
        if ($touch === null) {
            $this->logger->notice('A report from {site} about sale {reference} names a click this site does not know; it was kept as an unknown click.',
                ['site' => $from->key, 'reference' => $report->reference]);

            return self::UNKNOWN_CLICK;
        }
        try {
            return $this->connection->transactional(fn(): string => $this->writeReported($from, $report, $touch));
        } catch (\Throwable $failure) {
            $this->logger->error('A report from {site} about sale {reference} could not be kept; the other site will send it again.',
                ['site' => $from->key, 'reference' => $report->reference, 'exception' => $failure]);

            throw $failure;
        }
    }

    /** A sale by its reference (a connected site's, by that site's key), with its lines and movements, or null. */
    public function get(string $reference, ?string $site = null): ?Sale
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE ' . self::named($site) . ' AND reference = :reference',
            ['reference' => $reference] + ($site === null ? [] : ['site' => $site]));

        return $row === false ? null : $this->whole($row);
    }

    /** A sale by its id, with its lines and movements, or null. */
    public function byId(int $id): ?Sale
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : $this->whole($row);
    }

    /**
     * Whether a sale is kept, in a state (waiting, paid or cancelled; any for any): how a project's own check finds the
     * orders whose report was lost, and reports them again.
     */
    public function has(string $reference, string $state = Sale::PAID, ?string $site = null): bool
    {
        if ($state !== 'any' && !in_array($state, Sale::STATES, true)) {
            throw new \InvalidArgumentException(sprintf('A sale is waiting, paid, cancelled or any: "%s" is none of them.', $state));
        }

        return $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE ' . self::named($site) . ' AND reference = :reference' . ($state === 'any' ? '' : ' AND state = :state'),
            ['reference' => $reference] + ($site === null ? [] : ['site' => $site]) + ($state === 'any' ? [] : ['state' => $state])) !== false;
    }

    /** Whether a sale of this site's has a movement by its key (paid, refund:<id>, chargeback:<id>). */
    public function hasMovement(string $reference, string $key): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . self::MOVEMENTS . ' m JOIN ' . self::TABLE . ' s ON s.id = m.sale_id WHERE s.origin_site IS NULL AND s.reference = :reference AND m.key = :key',
            ['reference' => $reference, 'key' => $key]) !== false;
    }

    /**
     * The sales a filter holds, with their lines (not their movements), in an order (SORTS), the newest first by default.
     *
     * @return list<Sale>
     */
    public function list(SaleFilter $filter, int $limit = 50, int $offset = 0, string $sort = 'when', string $direction = 'desc'): array
    {
        if (!isset(self::SORT_SQL[$sort])) {
            throw new \InvalidArgumentException('Sales are sorted by ' . implode(', ', self::SORTS) . '.');
        }
        [$from, $where, $params, $types] = $this->filtered($filter);
        $order = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->connection->fetchAllAssociative('SELECT s.* FROM ' . $from . ' WHERE ' . $where . ' ORDER BY ' . self::SORT_SQL[$sort] . ' ' . $order . ', s.id ' . $order
            . ' LIMIT :limit OFFSET :offset', $params + ['limit' => max(1, $limit), 'offset' => max(0, $offset)], $types);

        return $this->withLines($rows);
    }

    /** How many sales a filter holds. */
    public function count(SaleFilter $filter): int
    {
        [$from, $where, $params, $types] = $this->filtered($filter);

        return Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, $params, $types));
    }

    /**
     * How many of the sales a filter holds are in each state as shown (paid, waiting, cancelled, partly_refunded,
     * refunded, charged_back; each sale once), whatever the filter's own state: "45 paid, 9 waiting for payment...".
     *
     * @return array<string,int>
     */
    public function stateCounts(SaleFilter $filter): array
    {
        [$from, $where, $params, $types] = $this->filtered($filter->withState('all'));
        $row = $this->connection->fetchAssociative("SELECT
                COUNT(*) FILTER (WHERE s.state = 'waiting') AS waiting, COUNT(*) FILTER (WHERE s.state = 'cancelled') AS cancelled,
                COUNT(*) FILTER (WHERE s.state = 'paid' AND s.charged_back_minor > 0) AS charged_back,
                COUNT(*) FILTER (WHERE s.state = 'paid' AND s.charged_back_minor = 0 AND s.refunded_minor > 0 AND s.refunded_minor >= s.paid_minor) AS refunded,
                COUNT(*) FILTER (WHERE s.state = 'paid' AND s.charged_back_minor = 0 AND s.refunded_minor > 0 AND s.refunded_minor < s.paid_minor) AS partly_refunded,
                COUNT(*) FILTER (WHERE s.state = 'paid' AND s.charged_back_minor = 0 AND s.refunded_minor = 0) AS paid
            FROM " . $from . ' WHERE ' . $where, $params, $types) ?: [];
        $counts = [];
        foreach (array_keys(Sale::SHOWN) as $state) {
            $counts[$state] = Rows::int($row[$state] ?? 0);
        }

        return $counts;
    }

    /** The totals of the sales a filter holds, in the site's currency (SaleTotals; other currencies left out and counted). */
    public function totals(SaleFilter $filter): SaleTotals
    {
        [$from, $where, $params, $types] = $this->filtered($filter);
        $groups = [];
        foreach ($this->connection->fetchAllAssociative("SELECT s.currency, COUNT(*) AS sales, COUNT(*) FILTER (WHERE s.state = 'paid') AS paid_sales,
                COUNT(DISTINCT COALESCE('a:' || s.account, 'f:' || s.forgotten_customer, 'o:' || s.organisation, 's:' || s.id)) FILTER (WHERE s.state = 'paid') AS customers,
                COALESCE(SUM(s.paid_minor) FILTER (WHERE s.state = 'paid'), 0) AS gross, COALESCE(SUM(s.discount_minor) FILTER (WHERE s.state = 'paid'), 0) AS discount,
                COALESCE(SUM(s.wallet_minor) FILTER (WHERE s.state = 'paid'), 0) AS wallet, COALESCE(SUM(s.refunded_minor), 0) AS refunded,
                COALESCE(SUM(s.charged_back_minor), 0) AS charged_back, COUNT(*) FILTER (WHERE s.state = 'waiting') AS waiting,
                COALESCE(SUM(s.total_minor) FILTER (WHERE s.state = 'waiting'), 0) AS waiting_minor, COUNT(*) FILTER (WHERE s.state = 'cancelled') AS cancelled
            FROM " . $from . ' WHERE ' . $where . ' GROUP BY s.currency ORDER BY COUNT(*) DESC, s.currency', $params, $types) as $row) {
            $groups[Rows::text($row['currency'])] = $row;
        }
        // The site's own currency, even when none of these sales is in it: the others are left out, never added to it.
        $currency = $this->currency() ?? array_key_first($groups);
        $row = $currency === null ? [] : ($groups[$currency] ?? []);
        $leftOut = [];
        foreach ($groups as $code => $group) {
            if ($code !== $currency) {
                $leftOut[$code] = Rows::int($group['sales']);
            }
        }

        return new SaleTotals($currency, Rows::int($row['sales'] ?? 0), Rows::int($row['paid_sales'] ?? 0), Rows::int($row['customers'] ?? 0), Rows::int($row['gross'] ?? 0),
            Rows::int($row['discount'] ?? 0), Rows::int($row['wallet'] ?? 0), Rows::int($row['refunded'] ?? 0), Rows::int($row['charged_back'] ?? 0), Rows::int($row['waiting'] ?? 0),
            Rows::int($row['waiting_minor'] ?? 0), Rows::int($row['cancelled'] ?? 0), $leftOut);
    }

    /**
     * Each offer's paid sales among those a filter holds (its state aside: only paid sales are sold), in the site's
     * currency: orders, units and money, the most net revenue first. A bundle counts as itself, never its offers.
     *
     * @return list<OfferSales>
     */
    public function offers(SaleFilter $filter): array
    {
        $currency = $this->currency();
        if ($currency === null) {
            return [];
        }
        [$from, $where, $params, $types] = $this->filtered($filter->withState('paid'));
        $offers = [];
        foreach ($this->connection->fetchAllAssociative('SELECT l.offer_type, l.offer_reference, (array_agg(l.label ORDER BY s.placed_at DESC, l.id DESC))[1] AS label,
                (array_agg(l.kind_label ORDER BY s.placed_at DESC, l.id DESC))[1] AS kind_label, COUNT(DISTINCT s.id) AS orders, SUM(l.quantity) AS units,
                SUM(l.paid_minor) AS gross, SUM(l.refunded_minor) AS refunded, SUM(l.charged_back_minor) AS charged_back
            FROM ' . self::LINES . ' l JOIN ' . $from . ' ON s.id = l.sale_id WHERE ' . $where . ' AND s.currency = :currency
            GROUP BY l.offer_type, l.offer_reference
            ORDER BY SUM(l.paid_minor - l.refunded_minor - l.charged_back_minor) DESC, SUM(l.quantity) DESC, l.offer_type, l.offer_reference', $params + ['currency' => $currency], $types) as $row) {
            $offers[] = new OfferSales(Rows::text($row['offer_type']), Rows::text($row['offer_reference']), Rows::text($row['label']), Rows::text($row['kind_label']), Rows::int($row['orders']),
                Rows::int($row['units']), Rows::int($row['gross']), Rows::int($row['refunded']), Rows::int($row['charged_back']));
        }

        return $offers;
    }

    /**
     * The sales paid since a moment (MARKETING_LATEST_SALES before now, for the overview), the newest first, with their
     * lines; narrowed to a campaign (an id, any or none) when asked, counted as the setting says.
     *
     * @return list<Sale>
     */
    public function latest(DateTimeImmutable $since, int $limit = 10, int|string|null $campaign = null): array
    {
        return $this->list(new SaleFilter(from: $since, campaign: $campaign), $limit);
    }

    /**
     * A campaign's sales in every state, counted for its first or last touch (null: as the setting says), the newest
     * first, with their lines: "Customers and their orders".
     *
     * @return list<Sale>
     */
    public function forCampaign(int $campaignId, ?string $model = null, int $limit = 50, int $offset = 0, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array
    {
        return $this->list(new SaleFilter(from: $from, until: $until, state: 'all', campaign: $campaignId, model: $model), $limit, $offset);
    }

    /**
     * The sales of some accounts (customers), in every state, the newest first, with their lines.
     *
     * @param list<string> $accounts
     * @return list<Sale>
     */
    public function forAccounts(array $accounts, int $limit = 100): array
    {
        if ($accounts === []) {
            return [];
        }

        return $this->withLines($this->connection->fetchAllAssociative('SELECT s.* FROM ' . self::TABLE . ' s WHERE s.account IN (:accounts) ORDER BY COALESCE(s.paid_at, s.placed_at) DESC, s.id DESC LIMIT :limit',
            ['accounts' => $accounts, 'limit' => max(1, $limit)], ['accounts' => ArrayParameterType::STRING]));
    }

    /**
     * One person's sales (those their visitors placed and those of their account), the oldest first, each with its lines
     * and movements: what a journey shows of their money.
     *
     * @param list<int> $visitorIds
     * @return list<Sale>
     */
    public function forPerson(array $visitorIds, ?string $account): array
    {
        $who = [];
        $params = [];
        $types = [];
        if ($visitorIds !== []) {
            $who[] = 's.visitor_id IN (:visitors)';
            $params['visitors'] = $visitorIds;
            $types['visitors'] = ArrayParameterType::INTEGER;
        }
        if ($account !== null) {
            $who[] = 's.account = :account';
            $params['account'] = $account;
        }
        if ($who === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative('SELECT s.* FROM ' . self::TABLE . ' s WHERE ' . implode(' OR ', $who) . ' ORDER BY s.placed_at, s.id', $params, $types);

        return array_map(fn(array $row): Sale => $this->whole($row), $rows);
    }

    /**
     * The site's own currency: that of its newest sale made here, else the one most of its sales are in (all from
     * connected sites), else null while there is no sale.
     */
    public function currency(): ?string
    {
        $currency = $this->connection->fetchOne('SELECT currency FROM ' . self::TABLE . ' WHERE origin_site IS NULL ORDER BY placed_at DESC, id DESC LIMIT 1');
        if ($currency === false) {
            $currency = $this->connection->fetchOne('SELECT currency FROM ' . self::TABLE . ' GROUP BY currency ORDER BY COUNT(*) DESC, currency LIMIT 1');
        }

        return $currency === false ? null : Rows::text($currency);
    }

    /** Forgets an account: its sales stay, with their money and campaigns, naming nobody. Gives how many did. */
    public function forgetAccount(string $account): int
    {
        return self::unname($this->connection, $account);
    }

    /** The sales placed by visitors made anonymous name nobody: their account removed. Gives how many. */
    public function forgetAccountsOfAnonymousVisitors(): int
    {
        $forgotten = 0;
        foreach ($this->connection->fetchFirstColumn('SELECT DISTINCT s.account FROM ' . self::TABLE . ' s JOIN ' . Visitors::TABLE . ' v ON v.id = s.visitor_id
                WHERE v.anonymised_at IS NOT NULL AND s.account IS NOT NULL ORDER BY s.account') as $account) {
            $forgotten += self::unname($this->connection, Rows::text($account), 'visitor_id IN (SELECT id FROM ' . Visitors::TABLE . ' WHERE anonymised_at IS NOT NULL)');
        }

        return $forgotten;
    }

    /**
     * Takes an account off its sales (those a condition picks, else all of them), giving them one random mark together
     * instead, so that the totals still count them as one paying customer. The mark is made now, from nothing about the
     * person, and kept only for that count: it names nobody and leads to no account. Gives how many sales it took it off.
     */
    public static function unname(Connection $connection, string $account, ?string $condition = null): int
    {
        return (int) $connection->executeStatement('UPDATE ' . self::TABLE . ' SET account = NULL, forgotten_customer = :mark WHERE account = :account'
            . ($condition === null ? '' : ' AND ' . $condition), ['account' => $account, 'mark' => bin2hex(random_bytes(16))]);
    }

    /** Whether a report may be kept: when it has problems, they are logged and it is refused. */
    private function acceptable(SaleReport $sale, string $call): bool
    {
        $problems = $sale->problems();
        if ($problems === []) {
            return true;
        }
        $this->logger->error('The Campaign Marketing Manager refused the report of sale {reference} ({call}): {problems} The purchase went on; the sale is not kept until it is reported right.',
            ['reference' => mb_substr($sale->reference, 0, 60), 'call' => $call, 'problems' => implode(' ', $problems)]);

        return false;
    }

    /**
     * Runs a write in its own transaction (a savepoint inside the caller's): a fault rolls back to it, is logged and gives
     * null, so the caller's work carries on.
     *
     * @template T
     * @param \Closure():T $work
     * @return T|null
     */
    private function guarded(string $what, string $reference, \Closure $work): mixed
    {
        try {
            return $this->connection->transactional($work);
        } catch (\Throwable $failure) {
            $this->logger->error('The Campaign Marketing Manager could not keep {what} for sale {reference}; the purchase went on, and the project\'s own check will report it again.',
                ['what' => $what, 'reference' => mb_substr($reference, 0, 60), 'exception' => $failure]);

            return null;
        }
    }

    /** @return array{0:?int,1:string} the visitor of a followed person's browser (none when not tracked) and its country */
    private function reader(?Request $request): array
    {
        if ($request === null) {
            return [null, ''];
        }
        $ip = $request->getClientIp();
        $country = $this->network !== null && $ip !== null && $ip !== '' ? $this->network->facts($ip)->countryCode : '';
        $agent = TouchAgent::read((string) $request->headers->get('User-Agent', ''));
        $doNotTrack = $this->settings->honoursDoNotTrack() && ($request->headers->get('DNT') === '1' || $request->headers->get('Sec-GPC') === '1');
        $token = $request->cookies->get(Visitors::COOKIE);
        $visitor = $agent->isPerson() && !$doNotTrack && is_string($token) ? $this->visitors->byToken($token)?->id : null;

        return [$visitor, strtoupper(substr($country, 0, 2))];
    }

    /**
     * The sale of this site's report, locked: made as waiting from the report when there was none.
     *
     * @return array{0:array<string,mixed>,1:bool} its row and whether it was made now
     */
    private function lockedOrInserted(SaleReport $sale, ?int $visitorId, string $country): array
    {
        $now = Rows::sql($this->clock->now());
        $made = $this->connection->fetchOne('INSERT INTO ' . self::TABLE . ' (reference, account, organisation, visitor_id, country, state, currency, list_minor, discount_minor, total_minor,
                wallet_minor, promotion, placed_at, updated_at)
            VALUES (:reference, :account, :organisation, :visitor, :country, \'waiting\', :currency, :list, :discount, :total, :wallet, :promotion, :placed, :now)
            ON CONFLICT ON CONSTRAINT cattophp_marketing_sales_reference_key DO NOTHING RETURNING id', $this->contents($sale) + ['visitor' => $visitorId, 'country' => $country, 'now' => $now]);
        $row = $this->locked(null, $sale->reference) ?? throw new \RuntimeException('A sale could not be read back.');

        return [$row, $made !== false];
    }

    /** @return array<string,mixed>|null a sale's row, locked until the transaction ends */
    private function locked(?string $site, string $reference): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE ' . self::named($site) . ' AND reference = :reference FOR UPDATE',
            ['reference' => $reference] + ($site === null ? [] : ['site' => $site]));

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> what a report gives a sale's row */
    private function contents(SaleReport $sale): array
    {
        return ['reference' => $sale->reference, 'account' => $sale->account, 'organisation' => $sale->organisation, 'currency' => $sale->currency, 'list' => $sale->listMinor(),
            'discount' => $sale->discountMinor(), 'total' => $sale->totalMinor, 'wallet' => $sale->walletMinor, 'promotion' => $sale->promotion === null ? null : trim($sale->promotion),
            'placed' => Rows::sql($sale->placedAt)];
    }

    /** A waiting or cancelled sale brought up to its report: waiting again, its contents as described. */
    private function rewrite(int $id, SaleReport $sale, ?int $visitorId, string $country): void
    {
        $values = $this->contents($sale);
        unset($values['reference']);
        $this->connection->executeStatement("UPDATE " . self::TABLE . " SET account = :account, organisation = :organisation, visitor_id = :visitor, country = :country, state = 'waiting',
                currency = :currency, list_minor = :list, discount_minor = :discount, total_minor = :total, wallet_minor = :wallet, promotion = :promotion, placed_at = :placed,
                cancelled_at = NULL, updated_at = :now WHERE id = :id",
            $values + ['visitor' => $visitorId, 'country' => $country, 'now' => Rows::sql($this->clock->now()), 'id' => $id]);
    }

    /** Whether a kept sale is already as a report describes it. */
    private function sameAs(Sale $kept, SaleReport $sale): bool
    {
        $line = static fn(SaleLine $line): array => [$line->key, $line->offerType, $line->offerReference, $line->label, $line->kindLabel, $line->quantity, $line->priceMinor, $line->wasMinor,
            $line->discountMinor, $line->walletMinor, $line->contains];

        return [$kept->account, $kept->organisation, $kept->currency, $kept->totalMinor, $kept->walletMinor, $kept->promotion, $kept->placedAt->getTimestamp(), array_map($line, $kept->lines)]
            === [$sale->account, $sale->organisation, $sale->currency, $sale->totalMinor, $sale->walletMinor, $sale->promotion === null ? null : trim($sale->promotion),
                $sale->placedAt->getTimestamp(), array_map($line, $sale->lines)];
    }

    /**
     * A sale's lines as described, in place of those it had (only ever while nothing was paid or taken back on them).
     *
     * @param list<SaleLine> $lines
     */
    private function writeLines(int $id, array $lines): void
    {
        $this->connection->executeStatement('DELETE FROM ' . self::LINES . ' WHERE sale_id = :id', ['id' => $id]);
        foreach ($lines as $position => $line) {
            $this->connection->executeStatement('INSERT INTO ' . self::LINES . ' (sale_id, line_key, offer_type, offer_reference, label, kind_label, quantity, price_minor, was_minor,
                    discount_minor, paid_minor, wallet_minor, refunded_minor, charged_back_minor, contains, position)
                VALUES (:sale, :key, :type, :reference, :label, :kind, :quantity, :price, :was, :discount, :paid, :wallet, :refunded, :charged_back, CAST(:contains AS jsonb), :position)',
                ['sale' => $id, 'key' => $line->key, 'type' => $line->offerType, 'reference' => $line->offerReference, 'label' => $line->label, 'kind' => $line->kindLabel,
                    'quantity' => $line->quantity, 'price' => $line->priceMinor, 'was' => $line->wasMinor, 'discount' => $line->discountMinor, 'paid' => $line->paidMinor(),
                    'wallet' => $line->walletMinor, 'refunded' => $line->refundedMinor, 'charged_back' => $line->chargedBackMinor,
                    'contains' => json_encode($line->contains, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'position' => $position]);
        }
    }

    /** Keeps an attribution with a sale; fixed once paid. */
    private function attribute(int $id, Attribution $attribution, bool $fixed): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET first_touch_id = :first_touch, last_touch_id = :last_touch, first_campaign_id = :first_campaign,
                last_campaign_id = :last_campaign, first_publication_id = :first_publication, last_publication_id = :last_publication, first_by = :first_by, last_by = :last_by,
                promotion_campaign_id = :promotion_campaign, promotion_counts = :promotion_counts, counted_within = :within, attributed_at = :now, fixed = :fixed WHERE id = :id',
            ['first_touch' => $attribution->firstTouchId, 'last_touch' => $attribution->lastTouchId, 'first_campaign' => $attribution->firstCampaignId,
                'last_campaign' => $attribution->lastCampaignId, 'first_publication' => $attribution->firstPublicationId, 'last_publication' => $attribution->lastPublicationId,
                'first_by' => $attribution->firstBy, 'last_by' => $attribution->lastBy, 'promotion_campaign' => $attribution->promotionCampaignId,
                'promotion_counts' => $attribution->promotionCounts, 'within' => $attribution->countedWithin, 'now' => Rows::sql($this->clock->now()), 'fixed' => $fixed ? 'true' : 'false',
                'id' => $id]);
    }

    /**
     * A refund or chargeback against a paid sale's lines.
     *
     * @param array<string,int> $lines
     */
    private function takeBack(string $kind, string $reference, string $key, array $lines, ?int $amount, DateTimeImmutable $at, string $how): ?Sale
    {
        $what = $kind === SaleMovement::REFUND ? 'a refund' : 'a chargeback';

        return $this->guarded($what, $reference, function () use ($kind, $reference, $key, $lines, $amount, $at, $how, $what): ?Sale {
            $row = $this->locked(null, $reference);
            if ($row === null) {
                return $this->refuse('The Campaign Marketing Manager was told of ' . $what . ' of sale {reference}, which it does not know; nothing was recorded.', $reference);
            }
            $id = Rows::int($row['id']);
            if ($this->connection->fetchOne('SELECT 1 FROM ' . self::MOVEMENTS . ' WHERE sale_id = :id AND key = :key', ['id' => $id, 'key' => $key]) !== false) {
                return $this->byId($id);
            }
            if ($key === '' || mb_strlen($key) > 120) {
                return $this->refuse('The Campaign Marketing Manager refused ' . $what . ' of sale {reference}: its key is 1 to 120 characters.', $reference);
            }
            $sale = $this->read($id);
            if (!$sale->isPaid()) {
                return $this->refuse('The Campaign Marketing Manager refused ' . $what . ' of sale {reference}: only a paid sale has money taken back.', $reference);
            }
            $left = [];
            foreach ($sale->lines as $line) {
                $left[$line->key] = $line->netMinor();
            }
            if ($lines === [] && $amount !== null) {
                if ($amount < 1 || $amount > array_sum($left)) {
                    return $this->refuse('The Campaign Marketing Manager refused ' . $what . ' of sale {reference}: it is more than is left of what was paid, or nothing.', $reference);
                }
                $lines = self::shared($amount, $left);
            }
            foreach ($lines as $lineKey => $part) {
                if (!array_key_exists((string) $lineKey, $left) || $part < 0 || $part > $left[(string) $lineKey]) {
                    return $this->refuse(sprintf('The Campaign Marketing Manager refused %s of sale {reference}: line "%s" has no such line, or the part is more than is left of what it was paid.',
                        $what, mb_substr((string) $lineKey, 0, 40)), $reference);
                }
            }
            $total = array_sum($lines);
            if ($total < 1 || ($amount !== null && $total !== $amount)) {
                return $this->refuse('The Campaign Marketing Manager refused ' . $what . ' of sale {reference}: its lines come to nothing, or not to its amount.', $reference);
            }
            $column = $kind === SaleMovement::REFUND ? 'refunded_minor' : 'charged_back_minor';
            foreach ($lines as $lineKey => $part) {
                if ($part > 0) {
                    $this->connection->executeStatement('UPDATE ' . self::LINES . ' SET ' . $column . ' = ' . $column . ' + :part WHERE sale_id = :id AND line_key = :key',
                        ['part' => $part, 'id' => $id, 'key' => (string) $lineKey]);
                }
            }
            $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET ' . $column . ' = ' . $column . ' + :amount, updated_at = :now WHERE id = :id',
                ['amount' => $total, 'now' => Rows::sql($this->clock->now()), 'id' => $id]);
            $movement = $this->addMovement($id, $kind, $key, $total, $at, $lines, $how);
            $changed = $this->read($id);
            $refund = $kind === SaleMovement::REFUND;
            $this->event($changed, $refund ? Events::REFUNDED : Events::CHARGED_BACK, $at, ($refund ? 'refunded:' : 'charged_back:') . $reference . ':' . $key,
                ['amount_minor' => $total, 'how' => $how]);
            $this->announce(new SaleChanged($changed, $refund ? SaleChanged::REFUNDED : SaleChanged::CHARGED_BACK, $movement));

            return $changed;
        });
    }

    /** Logs why a call was refused and gives null: nothing was written. */
    private function refuse(string $message, string $reference): null
    {
        $this->logger->error($message, ['reference' => mb_substr($reference, 0, 60)]);

        return null;
    }

    /**
     * An amount shared over lines by what each has left, by largest remainder, so the parts add up to it exactly.
     *
     * @param array<string,int> $left
     * @return array<string,int>
     */
    private static function shared(int $amount, array $left): array
    {
        $whole = array_sum($left);
        $parts = [];
        $remainders = [];
        foreach ($left as $key => $value) {
            $exact = $amount * $value;
            $parts[$key] = $whole === 0 ? 0 : intdiv($exact, $whole);
            $remainders[$key] = $whole === 0 ? 0 : $exact % $whole;
        }
        $short = $amount - array_sum($parts);
        // The largest remainders take one more each, the earlier line first among equals.
        $order = array_keys($left);
        $position = array_flip($order);
        usort($order, static fn(int|string $a, int|string $b): int => [$remainders[$b], $position[$a]] <=> [$remainders[$a], $position[$b]]);
        foreach ($order as $key) {
            if ($short <= 0) {
                break;
            }
            if ($parts[$key] < $left[$key]) {
                $parts[$key]++;
                $short--;
            }
        }

        return $parts;
    }

    /** @param array<string,int> $lines */
    private function addMovement(int $saleId, string $kind, string $key, int $amount, DateTimeImmutable $at, array $lines, string $how): ?SaleMovement
    {
        $how = mb_substr(trim($how), 0, 80);
        $id = $this->connection->fetchOne('INSERT INTO ' . self::MOVEMENTS . ' (sale_id, kind, key, amount_minor, at, lines, how) VALUES (:sale, :kind, :key, :amount, :at, CAST(:lines AS jsonb), :how)
            ON CONFLICT ON CONSTRAINT cattophp_marketing_sale_movements_key DO NOTHING RETURNING id',
            ['sale' => $saleId, 'kind' => $kind, 'key' => $key, 'amount' => $amount, 'at' => Rows::sql($at),
                'lines' => json_encode((object) $lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'how' => $how]);

        return $id === false ? null : new SaleMovement(Rows::int($id), $saleId, $kind, $key, $amount, $at, $lines, $how);
    }

    /**
     * Records a sale's event in the journey, for a person the module follows only: its visitor and account, the campaign,
     * publication and touch it counts for as the setting says (no touch when a promotion code counted), its country.
     *
     * @param array<string,mixed> $detail
     */
    private function event(Sale $sale, string $kind, DateTimeImmutable $at, string $key, array $detail = []): void
    {
        $model = $this->settings->saleCountsFor();
        $followed = $sale->visitorId !== null || $sale->firstCampaignId !== null || $sale->lastCampaignId !== null
            || ($sale->account !== null && $this->connection->fetchOne('SELECT 1 FROM ' . Visitors::TABLE . ' WHERE account = :account LIMIT 1', ['account' => $sale->account]) !== false);
        if (!$followed) {
            return;
        }
        $this->events->record(new EventInput($kind, $at, $sale->visitorId, $sale->account, $sale->campaignId($model), $sale->publicationId($model),
            $sale->by($model) === 'promotion' ? null : $sale->touchId($model), detail: ['reference' => $sale->reference, ...$detail], country: $sale->country, idempotencyKey: $key,
            originSite: $sale->originSite, saleId: $sale->id));
    }

    private function announce(SaleChanged $change): void
    {
        // Not caught: a listener's writes (a connected site's report) go in with the sale or not at all.
        $this->dispatcher?->dispatch($change);
    }

    private function writeReported(Site $from, ReportedSale $report, Touch $touch): string
    {
        $row = $this->locked($from->key, $report->reference);
        if ($row === null) {
            $this->connection->executeStatement('INSERT INTO ' . self::TABLE . ' (reference, origin_site, visitor_id, country, currency, placed_at, updated_at)
                VALUES (:reference, :site, :visitor, :country, :currency, :placed, :now) ON CONFLICT ON CONSTRAINT cattophp_marketing_sales_reference_key DO NOTHING',
                ['reference' => $report->reference, 'site' => $from->key, 'visitor' => $touch->visitorId, 'country' => $touch->country, 'currency' => $report->currency,
                    'placed' => Rows::sql($report->placedAt), 'now' => Rows::sql($this->clock->now())]);
            $row = $this->locked($from->key, $report->reference) ?? throw new \RuntimeException('A sale could not be read back.');
        }
        $id = Rows::int($row['id']);
        $kept = $this->read($id);
        if ($kept->currency !== $report->currency) {
            return $this->refusedReport($from, $report, 'its currency is not the one the sale was kept in');
        }
        // A report older than what is kept (placed or cancelled after paid) changes no state or money.
        $older = $kept->isPaid() && $report->state !== Sale::PAID;
        $state = $older ? $kept->state : $report->state;
        $paid = $state === Sale::PAID ? ($older ? $kept->paidMinor : $report->paidMinor) : 0;
        $refunded = $state === Sale::PAID ? max($kept->refundedMinor, $report->refundedMinor) : 0;
        $chargedBack = $state === Sale::PAID ? max($kept->chargedBackMinor, $report->chargedBackMinor) : 0;
        $described = $older ? array_map(static fn(SaleLine $line): SaleLine => new SaleLine($line->key, $line->offerType, $line->offerReference, $line->label, $line->kindLabel,
            $line->quantity, $line->priceMinor, $line->wasMinor, $line->discountMinor), $kept->lines) : $report->lines;
        $total = array_sum(array_map(static fn(SaleLine $line): int => $line->paidMinor(), $described));
        if ($refunded + $chargedBack > $paid || ($state === Sale::PAID && $paid > $total)) {
            return $this->refusedReport($from, $report, 'more is taken back than was paid, or more paid than its lines come to');
        }
        $lines = self::reportedLines($described, $refunded, $chargedBack);
        $values = ['state' => $state, 'list' => array_sum(array_map(static fn(SaleLine $line): int => $line->priceMinor, $described)),
            'discount' => array_sum(array_map(static fn(SaleLine $line): int => $line->discountMinor, $described)), 'total' => $total, 'paid' => $paid, 'refunded' => $refunded,
            'charged_back' => $chargedBack, 'placed' => $older ? $kept->placedAt : $report->placedAt, 'paid_at' => $state === Sale::PAID ? ($older ? $kept->paidAt : $report->paidAt) : null,
            'cancelled_at' => $state === Sale::CANCELLED ? ($kept->cancelledAt ?? $report->at) : null];
        $stamp = static fn(?DateTimeImmutable $at): ?int => $at?->getTimestamp();
        $changed = [$kept->state, $kept->listMinor, $kept->discountMinor, $kept->totalMinor, $kept->paidMinor, $kept->refundedMinor, $kept->chargedBackMinor, $stamp($kept->placedAt),
            $stamp($kept->paidAt), $stamp($kept->cancelledAt), self::lineFacts($kept->lines)]
            !== [$values['state'], $values['list'], $values['discount'], $values['total'], $values['paid'], $values['refunded'], $values['charged_back'], $stamp($values['placed']),
                $stamp($values['paid_at']), $stamp($values['cancelled_at']), self::lineFacts($lines)];
        if ($changed) {
            $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET state = :state, list_minor = :list, discount_minor = :discount, total_minor = :total, paid_minor = :paid,
                    refunded_minor = :refunded, charged_back_minor = :charged_back, placed_at = :placed, paid_at = :paid_at, cancelled_at = :cancelled_at, updated_at = :now WHERE id = :id',
                ['placed' => Rows::sql($values['placed']), 'paid_at' => Rows::sql($values['paid_at']), 'cancelled_at' => Rows::sql($values['cancelled_at']),
                    'now' => Rows::sql($this->clock->now()), 'id' => $id] + $values);
            $this->writeLines($id, $lines);
        }
        $attributed = $this->attributeReported($kept, $report, $touch, $state === Sale::PAID);
        $movement = match ($report->kind) {
            'sale_paid' => $this->addMovement($id, SaleMovement::PAID, SaleMovement::PAID, $paid, $report->paidAt ?? $report->at, [], ''),
            'sale_refunded' => $this->addMovement($id, SaleMovement::REFUND, (string) $report->movementKey, (int) $report->movementMinor, $report->at, [], ''),
            'sale_charged_back' => $this->addMovement($id, SaleMovement::CHARGEBACK, (string) $report->movementKey, (int) $report->movementMinor, $report->at, [], ''),
            default => null,
        };
        $visitor = $touch->visitorId === null ? null : $this->visitors->byId($touch->visitorId);
        $recorded = $this->events->record(new EventInput(self::REPORTED_EVENTS[$report->kind], $report->at, $touch->visitorId, $visitor?->account, $touch->campaignId,
            $touch->publicationId, $touch->id, detail: ['reference' => $report->reference, 'amount_minor' => $report->movementMinor ?? ($state === Sale::PAID ? $paid : $total)],
            country: $touch->country, idempotencyKey: 'site:' . $from->key . ':' . $report->key, originSite: $from->key, saleId: $id)) !== null;
        // A repeat writes nothing and announces nothing.
        if ($changed || $attributed || $movement !== null || $recorded) {
            $this->announce(new SaleChanged($this->read($id), SaleChanged::REPORTED, $movement));
        }

        return self::RECORDED;
    }

    /**
     * What a sale's lines hold, to tell whether a report changes them.
     *
     * @param list<SaleLine> $lines
     * @return list<list<mixed>>
     */
    private static function lineFacts(array $lines): array
    {
        return array_map(static fn(SaleLine $line): array => [$line->key, $line->offerType, $line->offerReference, $line->label, $line->kindLabel, $line->quantity, $line->priceMinor,
            $line->wasMinor, $line->discountMinor, $line->walletMinor, $line->contains, $line->refundedMinor, $line->chargedBackMinor], $lines);
    }

    /**
     * A connected site's sale's first or last touch is this site's touch, as the report's flags say (by site). Until it is
     * paid a report also clears a side it no longer names that touch for; once fixed, a side is only ever filled in. Gives
     * whether anything changed.
     */
    private function attributeReported(Sale $kept, ReportedSale $report, Touch $touch, bool $paid): bool
    {
        $sides = [];
        foreach (['first' => $report->first, 'last' => $report->last] as $side => $named) {
            $current = $side === 'first' ? [$kept->firstTouchId, $kept->firstCampaignId, $kept->firstPublicationId, $kept->firstBy]
                : [$kept->lastTouchId, $kept->lastCampaignId, $kept->lastPublicationId, $kept->lastBy];
            $wanted = $current;
            if ($named && (!$kept->fixed || $current[0] === null)) {
                $wanted = [$touch->id, $touch->campaignId, $touch->publicationId, 'site'];
            } elseif (!$named && !$kept->fixed && $current[0] === $touch->id) {
                $wanted = [null, null, null, null];
            }
            if ($wanted !== $current) {
                $sides[$side] = $wanted;
            }
        }
        $fixed = $paid || $kept->fixed;
        if ($sides === [] && $fixed === $kept->fixed) {
            return false;
        }
        $set = [];
        $params = ['id' => $kept->id, 'now' => Rows::sql($this->clock->now()), 'fixed' => $fixed ? 'true' : 'false'];
        foreach ($sides as $side => [$touchId, $campaignId, $publicationId, $by]) {
            $set[] = "{$side}_touch_id = :{$side}_touch, {$side}_campaign_id = :{$side}_campaign, {$side}_publication_id = :{$side}_publication, {$side}_by = :{$side}_by";
            $params += [$side . '_touch' => $touchId, $side . '_campaign' => $campaignId, $side . '_publication' => $publicationId, $side . '_by' => $by];
        }
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET ' . implode(', ', [...$set, 'attributed_at = :now', 'fixed = :fixed']) . ' WHERE id = :id', $params);

        return true;
    }

    /**
     * A connected site's lines, with its sale's refunds and chargebacks shared over them by what each was paid.
     *
     * @param list<SaleLine> $lines
     * @return list<SaleLine>
     */
    private static function reportedLines(array $lines, int $refunded, int $chargedBack): array
    {
        $paid = [];
        foreach ($lines as $line) {
            $paid[$line->key] = $line->paidMinor();
        }
        $refunds = $refunded > 0 ? self::shared($refunded, $paid) : [];
        $left = [];
        foreach ($paid as $key => $value) {
            $left[$key] = $value - ($refunds[$key] ?? 0);
        }
        $chargebacks = $chargedBack > 0 ? self::shared($chargedBack, $left) : [];

        return array_map(static fn(SaleLine $line): SaleLine => new SaleLine($line->key, $line->offerType, $line->offerReference, $line->label, $line->kindLabel, $line->quantity,
            $line->priceMinor, $line->wasMinor, $line->discountMinor, 0, $line->contains, $refunds[$line->key] ?? 0, $chargebacks[$line->key] ?? 0), $lines);
    }

    private function refusedReport(Site $from, ReportedSale $report, string $why): string
    {
        $this->logger->warning('A report from {site} about sale {reference} was refused: ' . $why . '.', ['site' => $from->key, 'reference' => $report->reference]);

        return self::REFUSED;
    }

    /**
     * Where a sale is named: here (no site) or on a connected site, by its key.
     */
    private static function named(?string $site): string
    {
        return $site === null ? 'origin_site IS NULL' : 'origin_site = :site';
    }

    /**
     * The FROM, WHERE, parameters and types of the sales a filter holds; the sales are s.
     *
     * @return array{0:string,1:string,2:array<string,mixed>,3:array<string,ArrayParameterType>}
     */
    private function filtered(SaleFilter $filter): array
    {
        $model = $filter->model($this->settings->saleCountsFor());
        $from = self::TABLE . ' s';
        $where = ['TRUE'];
        $params = [];
        $types = [];
        if ($filter->narrowsByTouch()) {
            $from .= ' LEFT JOIN ' . Publications::TABLE . " p ON p.id = s.{$model}_publication_id LEFT JOIN " . Touches::TABLE . " t ON t.id = s.{$model}_touch_id AND s.{$model}_by IS DISTINCT FROM 'promotion'";
        }
        if ($filter->state !== 'all') {
            $where[] = 's.state = :state';
            $params['state'] = $filter->state;
        }
        $when = $filter->state === 'paid' ? 's.paid_at' : 'COALESCE(s.paid_at, s.placed_at)';
        if ($filter->from !== null) {
            $where[] = $when . ' >= :from';
            $params['from'] = Rows::sql($filter->from);
        }
        if ($filter->until !== null) {
            $where[] = $when . ' < :until';
            $params['until'] = Rows::sql($filter->until);
        }
        if ($filter->campaign === SaleFilter::ANY_CAMPAIGN) {
            $where[] = "s.{$model}_campaign_id IS NOT NULL";
        } elseif ($filter->campaign === SaleFilter::NO_CAMPAIGN) {
            $where[] = "s.{$model}_campaign_id IS NULL";
        } elseif ($filter->campaignId() !== null) {
            $where[] = "s.{$model}_campaign_id = :campaign";
            $params['campaign'] = $filter->campaignId();
        }
        if ($filter->channel !== null) {
            $where[] = 'COALESCE(p.channel_key, t.source) = :channel';
            $params['channel'] = $filter->channel;
        }
        if ($filter->publicationId !== null) {
            $where[] = "s.{$model}_publication_id = :publication";
            $params['publication'] = $filter->publicationId;
        }
        if ($filter->advertId !== null) {
            $where[] = 'p.advert_id = :advert';
            $params['advert'] = $filter->advertId;
        }
        if ($filter->place !== null) {
            $where[] = 'lower(trim(p.place)) = lower(trim(:place))';
            $params['place'] = $filter->place;
        }
        if ($filter->country !== null) {
            $where[] = 's.country = :country';
            $params['country'] = $filter->country;
        }
        if ($filter->offerType !== null && $filter->offerReference !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . self::LINES . ' fl WHERE fl.sale_id = s.id AND fl.offer_type = :offer_type AND fl.offer_reference = :offer_reference)';
            $params += ['offer_type' => $filter->offerType, 'offer_reference' => $filter->offerReference];
        }
        if ($filter->where === SaleFilter::HERE) {
            $where[] = 's.origin_site IS NULL';
        } elseif ($filter->where !== SaleFilter::EVERYWHERE) {
            $where[] = 's.origin_site = :where';
            $params['where'] = $filter->where;
        }
        if ($filter->account !== null) {
            $where[] = 's.account = :account';
            $params['account'] = $filter->account;
        }
        if ($filter->organisation !== null) {
            $where[] = 's.organisation = :organisation';
            $params['organisation'] = $filter->organisation;
        }
        if (trim($filter->search) !== '') {
            $where[] = 's.reference ILIKE :search';
            $params['search'] = Rows::like(trim($filter->search));
        }

        return [$from, implode(' AND ', $where), $params, $types];
    }

    /**
     * Sales with their lines, read together.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<Sale>
     */
    private function withLines(array $rows): array
    {
        $lines = $this->lines(array_map(static fn(array $row): int => Rows::int($row['id']), $rows));

        return array_map(fn(array $row): Sale => $this->sale($row, $lines[Rows::int($row['id'])] ?? []), $rows);
    }

    /** A sale just written, read back within the same transaction. */
    private function read(int $id): Sale
    {
        return $this->byId($id) ?? throw new \RuntimeException('A sale could not be read back.');
    }

    /** @param array<string,mixed> $row */
    private function whole(array $row): Sale
    {
        $id = Rows::int($row['id']);

        return $this->sale($row, $this->lines([$id])[$id] ?? [], $this->movements($id));
    }

    /**
     * @param list<int> $saleIds
     * @return array<int,list<SaleLine>> each sale's lines in their order, by its id
     */
    private function lines(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }
        $lines = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM ' . self::LINES . ' WHERE sale_id IN (:ids) ORDER BY sale_id, position, id', ['ids' => $saleIds],
            ['ids' => ArrayParameterType::INTEGER]) as $row) {
            $contains = json_decode(Rows::text($row['contains']), true);
            $lines[Rows::int($row['sale_id'])][] = new SaleLine(Rows::text($row['line_key']), Rows::text($row['offer_type']), Rows::text($row['offer_reference']), Rows::text($row['label']),
                Rows::text($row['kind_label']), Rows::int($row['quantity']), Rows::int($row['price_minor']), Rows::nullableInt($row['was_minor']), Rows::int($row['discount_minor']),
                Rows::int($row['wallet_minor']), is_array($contains) ? array_values(array_map(Rows::text(...), $contains)) : [], Rows::int($row['refunded_minor']),
                Rows::int($row['charged_back_minor']));
        }

        return $lines;
    }

    /** @return list<SaleMovement> a sale's movements, the oldest first */
    private function movements(int $saleId): array
    {
        $movements = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM ' . self::MOVEMENTS . ' WHERE sale_id = :id ORDER BY at, id', ['id' => $saleId]) as $row) {
            $lines = json_decode(Rows::text($row['lines']), true);
            $parts = [];
            foreach (is_array($lines) ? $lines : [] as $key => $part) {
                $parts[(string) $key] = Rows::int($part);
            }
            $movements[] = new SaleMovement(Rows::int($row['id']), $saleId, Rows::text($row['kind']), Rows::text($row['key']), Rows::int($row['amount_minor']),
                Rows::instant($row['at'], $this->utc) ?? new DateTimeImmutable(), $parts, Rows::text($row['how']));
        }

        return $movements;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<SaleLine> $lines
     * @param list<SaleMovement> $movements
     */
    private function sale(array $row, array $lines = [], array $movements = []): Sale
    {
        $utc = $this->utc;

        return new Sale(Rows::int($row['id']), Rows::text($row['reference']), Rows::nullableText($row['origin_site']), Rows::nullableText($row['account']),
            Rows::nullableText($row['organisation']), Rows::nullableInt($row['visitor_id']), Rows::text($row['country']), Rows::text($row['state']), Rows::text($row['currency']),
            Rows::int($row['list_minor']), Rows::int($row['discount_minor']), Rows::int($row['total_minor']), Rows::int($row['wallet_minor']), Rows::int($row['paid_minor']),
            Rows::int($row['refunded_minor']), Rows::int($row['charged_back_minor']), Rows::nullableText($row['promotion']),
            Rows::instant($row['placed_at'], $utc) ?? new DateTimeImmutable(), Rows::instant($row['paid_at'], $utc), Rows::instant($row['cancelled_at'], $utc),
            Rows::instant($row['updated_at'], $utc) ?? new DateTimeImmutable(), Rows::nullableInt($row['first_touch_id']), Rows::nullableInt($row['last_touch_id']),
            Rows::nullableInt($row['first_campaign_id']), Rows::nullableInt($row['last_campaign_id']), Rows::nullableInt($row['first_publication_id']),
            Rows::nullableInt($row['last_publication_id']), Rows::nullableText($row['first_by']), Rows::nullableText($row['last_by']), Rows::nullableInt($row['promotion_campaign_id']),
            Rows::nullableText($row['promotion_counts']), Rows::nullableText($row['counted_within']), Rows::instant($row['attributed_at'], $utc), Rows::bool($row['fixed']), $lines,
            $movements);
    }
}
