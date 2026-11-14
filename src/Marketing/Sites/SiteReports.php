<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use CattoPHP\Activity\Actor;
use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\JourneyRecorded;
use CattoPHP\Marketing\JourneyEvent;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Rows;
use CattoPHP\Marketing\Sales\ReportedSale;
use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Sales\SaleChanged;
use CattoPHP\Marketing\Sales\SaleMovement;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The reports this site owes the sites its visitors came from, and their delivery.
 *
 * Queued: on a JourneyRecorded for a registration or a subscription, when the reader's current campaign's touch (the
 * event's own, else their latest counted touch within MARKETING_SALE_COUNTS_WITHIN) came from a connected site; on a
 * SaleChanged (placed, cancelled, paid, refunded, charged back), when the sale's first or last touch came from one, one
 * report to each such site for each such click. Each is written in the same transaction as what it reports, under a
 * savepoint so a failure here never undoes it, and is kept once by its key (sale_paid:<reference>,
 * sale_refunded:<reference>:<movement key>, registered:<event>), so a repeat queues nothing. A report holds codes,
 * amounts and lines, never a name, an email address, an account or an IP address.
 *
 * Delivered every minute (MarketingSiteReportsTask): each site's due reports, oldest first, up to 50 in one POST to
 * <address>/webhooks/marketing, signed (X-CattoPHP-Signature, both secrets while one is being changed) and naming this
 * site (X-CattoPHP-From). A 200 marks them delivered; anything else, or no answer, tries them again after
 * MARKETING_SITE_RETRY_FIRST, then twice as long each time, up to MARKETING_SITE_RETRY_LONGEST apart, and names the
 * problem on the site's card. A report not delivered MARKETING_SITE_REPORTS_KEPT after it was made is given up (Send them
 * again offers it once more); reports delivered and received are removed that long after. Without symfony/http-client
 * nothing is sent and the card says so.
 */
final class SiteReports
{
    public const OUT = 'cattophp_marketing_reports_out';
    public const IN = 'cattophp_marketing_reports_in';

    /** Where a connected site takes reports, and where it answers a check of the connection. */
    public const PATH = '/webhooks/marketing';
    public const CHECK_PATH = '/webhooks/marketing/check';

    public const SIGNATURE_HEADER = 'X-CattoPHP-Signature';
    public const FROM_HEADER = 'X-CattoPHP-From';

    /** The most reports sent in one message. */
    public const BATCH = 50;

    /** The journey's events a connected site is told of. */
    public const EVENT_KINDS = [Events::REGISTERED, Events::SUBSCRIBED];

    /** What each change to a sale is reported as. */
    private const SALE_KINDS = [SaleChanged::PLACED => 'order_placed', SaleChanged::CANCELLED => 'order_cancelled', SaleChanged::PAID => 'sale_paid',
        SaleChanged::REFUNDED => 'sale_refunded', SaleChanged::CHARGED_BACK => 'sale_charged_back'];

    public function __construct(
        private readonly Connection $connection,
        private readonly Sites $sites,
        private readonly Touches $touches,
        private readonly Visitors $visitors,
        private readonly MarketingSettings $settings,
        private readonly ClockInterface $clock,
        private readonly ?Installation $installation = null,
        private readonly ?HttpClientInterface $http = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** Whether reports can be sent: symfony/http-client is there. */
    public function canSend(): bool
    {
        return $this->http !== null;
    }

    /** A registration or subscription by a reader whose current campaign came from a connected site: a report to that site. */
    public function onJourneyRecorded(JourneyRecorded $recorded): void
    {
        $event = $recorded->event;
        if (!in_array($event->kind, self::EVENT_KINDS, true) || $event->originSite !== null) {
            return;
        }
        $this->safely('a journey event', function () use ($event, $recorded): void {
            $touch = $this->currentTouch($event);
            $site = $touch?->originSite === null ? null : $this->sites->get($touch->originSite);
            if ($touch === null || $site === null || $touch->originReference === null) {
                return;
            }
            $key = $event->kind . ':' . $event->id;
            $offer = $recorded->offer;
            $body = ['version' => ReportedSale::VERSION, 'key' => $key, 'kind' => $event->kind, 'at' => self::utc($event->at), 'click' => $touch->originReference];
            if ($offer !== null || $event->offerType !== null) {
                $body['offer'] = ['type' => $offer->type ?? $event->offerType, 'reference' => $offer->reference ?? $event->offerReference, 'label' => $offer?->label];
            }
            $this->queue($site, $key, $event->kind, $body, $event->at);
        });
    }

    /** A sale placed, cancelled, paid, refunded or charged back whose first or last touch came from a connected site: a report to each such site. */
    public function onSaleChanged(SaleChanged $changed): void
    {
        $kind = self::SALE_KINDS[$changed->change] ?? null;
        $sale = $changed->sale;
        if ($kind === null || $sale->originSite !== null) {
            return;
        }
        $this->safely('a sale', function () use ($changed, $kind, $sale): void {
            $clicks = $this->clicksBySite($sale);
            $movement = $changed->movement;
            $at = match ($changed->change) {
                SaleChanged::PLACED => $sale->placedAt,
                SaleChanged::CANCELLED => $sale->cancelledAt ?? $this->clock->now(),
                SaleChanged::PAID => $sale->paidAt ?? $this->clock->now(),
                default => $movement->at ?? $this->clock->now(),
            };
            foreach ($clicks as $siteKey => $siteClicks) {
                $site = $this->sites->get($siteKey);
                if ($site === null) {
                    continue;
                }
                foreach ($siteClicks as $click => [$first, $last]) {
                    $key = $kind . ':' . $sale->reference . ($movement === null || $movement->kind === SaleMovement::PAID ? '' : ':' . $movement->key) . (count($siteClicks) > 1 ? ':' . $click : '');
                    try {
                        $report = ReportedSale::of($sale, $kind, $key, $at, $click, $first, $last, $movement?->kind === SaleMovement::PAID ? null : $movement);
                    } catch (\InvalidArgumentException $refused) {
                        $this->logger->error('A report about a sale to a connected site could not be made: ' . $refused->getMessage(), ['site' => $site->key, 'sale' => $sale->reference]);
                        continue;
                    }
                    $this->queue($site, $key, $kind, $report->body(), $at);
                }
            }
        });
    }

    /**
     * Puts a report in the queue for a site, once for its key: true when it was queued, false when it was already.
     *
     * @param array<string,mixed> $body
     */
    public function queue(Site $site, string $key, string $kind, array $body, DateTimeImmutable $at): bool
    {
        $now = Rows::sql($this->clock->now());

        return $this->connection->fetchOne(
            'INSERT INTO ' . self::OUT . ' (site_key, key, kind, body, made_at, next_try_at) VALUES (:site, :key, :kind, :body, :now, :now) ON CONFLICT (site_key, key) DO NOTHING RETURNING id',
            ['site' => $site->key, 'key' => mb_substr($key, 0, 200), 'kind' => $kind, 'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'now' => $now],
        ) !== false;
    }

    /**
     * The task's work: reports not delivered in time given up, each site's due reports sent, and those delivered and
     * received long enough ago removed. Says what it did.
     */
    public function deliver(DateTimeImmutable $now): string
    {
        $kept = $this->settings->siteReportsKept()->before($now);
        $gaveUp = (int) $this->connection->executeStatement('UPDATE ' . self::OUT . ' SET gave_up_at = :now WHERE delivered_at IS NULL AND gave_up_at IS NULL AND made_at < :kept',
            ['now' => Rows::sql($now), 'kept' => Rows::sql($kept)]);
        $sent = 0;
        $failed = 0;
        if ($this->http !== null) {
            $due = $this->connection->fetchFirstColumn('SELECT DISTINCT r.site_key FROM ' . self::OUT . ' r JOIN ' . Sites::TABLE . ' s ON s.key = r.site_key
                 WHERE s.removed_at IS NULL AND r.delivered_at IS NULL AND r.gave_up_at IS NULL AND r.next_try_at <= :now ORDER BY r.site_key', ['now' => Rows::sql($now)]);
            foreach ($due as $key) {
                $site = $this->sites->get(Rows::text($key));
                if ($site === null) {
                    continue;
                }
                [$delivered, $notDelivered] = $this->send($site, $now);
                $sent += $delivered;
                $failed += $notDelivered;
            }
        }
        $removed = (int) $this->connection->executeStatement('DELETE FROM ' . self::OUT . ' WHERE delivered_at IS NOT NULL AND made_at < :kept', ['kept' => Rows::sql($kept)])
            + (int) $this->connection->executeStatement('DELETE FROM ' . self::IN . ' WHERE received_at < :kept', ['kept' => Rows::sql($kept)]);

        return sprintf('%s delivered, %s not delivered yet, %s given up, %s removed%s.', Sites::reports($sent), number_format($failed), number_format($gaveUp), number_format($removed),
            $this->http === null ? ' (nothing is sent without symfony/http-client)' : '');
    }

    /**
     * Sends a site its due reports, up to 50 at a time, until none is due or one message is not accepted. Gives how many
     * were delivered and how many were not.
     *
     * @return array{int,int}
     */
    public function send(Site $site, DateTimeImmutable $now): array
    {
        $delivered = 0;
        do {
            $rows = $this->connection->fetchAllAssociative('SELECT id, body, attempts FROM ' . self::OUT . ' WHERE site_key = :site AND delivered_at IS NULL AND gave_up_at IS NULL AND next_try_at <= :now
                 ORDER BY made_at, id LIMIT ' . self::BATCH, ['site' => $site->key, 'now' => Rows::sql($now)]);
            if ($rows === []) {
                return [$delivered, 0];
            }
            $ids = array_map(static fn (array $row): int => Rows::int($row['id']), $rows);
            $bodies = array_map(static fn (array $row): mixed => json_decode(Rows::text($row['body']), true), $rows);
            $problem = $this->post($site, json_encode($bodies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now);
            if ($problem !== null) {
                $this->retry($rows, $problem, $now);

                return [$delivered, count($rows)];
            }
            $this->connection->executeStatement('UPDATE ' . self::OUT . ' SET delivered_at = :now, attempts = attempts + 1, last_error = NULL WHERE id IN (' . implode(', ', $ids) . ')',
                ['now' => Rows::sql($now)]);
            $delivered += count($rows);
        } while (count($rows) === self::BATCH);

        return [$delivered, 0];
    }

    /**
     * Check the connection: a signed GET to the other site's /webhooks/marketing/check. Accepted, the site is connected
     * and the clocks' difference noted; otherwise the problem is noted. Gives null when connected, else the problem in
     * words. It is in the activity log either way.
     */
    public function check(Site $site, ?Actor $actor = null): ?string
    {
        $now = $this->clock->now();
        if ($this->http === null) {
            return 'Connected sites need symfony/http-client, which this installation does not have.';
        }
        try {
            $signature = $this->sites->sign($site, '', $now);
        } catch (\RuntimeException) {
            $problem = 'Its shared secret here could not be read. Put the secret in again.';
            $this->sites->problem($site, $problem, $now);
            $this->sites->logCheck($site, $problem, null, $actor);

            return $problem;
        }
        $apart = null;
        try {
            $response = $this->http->request('GET', $site->base() . self::CHECK_PATH, ['headers' => [self::SIGNATURE_HEADER => $signature, self::FROM_HEADER => $this->from()],
                'timeout' => 10, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
            $apart = self::apart($response->getHeaders(false), $now);
            $problem = $status === 200 ? null : $this->refusal($status, $apart);
        } catch (TransportExceptionInterface) {
            $problem = 'The other site could not be reached. Check its address, and that it is running.';
        }
        if ($problem === null) {
            $this->sites->checked($site, $now, $apart);
            $this->sites->logCheck($site, 'Connected', $apart, $actor);

            return null;
        }
        $this->sites->problem($site, $problem, $now, $apart);
        $this->sites->logCheck($site, $problem, $apart, $actor);

        return $problem;
    }

    /** Send them again: a site's reports given up, offered once more from now. Gives how many. */
    public function sendAgain(Site $site): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::OUT . ' SET gave_up_at = NULL, attempts = 0, made_at = :now, next_try_at = :now, last_error = NULL
             WHERE site_key = :site AND gave_up_at IS NOT NULL', ['now' => Rows::sql($this->clock->now()), 'site' => $site->key]);
    }

    /** How a site's reports stand: waiting (still being tried), given up and delivered (and not removed yet), with the last error of those waiting. */
    public function counts(Site $site): ReportCounts
    {
        $row = $this->connection->fetchAssociative('SELECT
                COUNT(*) FILTER (WHERE delivered_at IS NULL AND gave_up_at IS NULL) AS waiting,
                COUNT(*) FILTER (WHERE gave_up_at IS NOT NULL) AS given_up,
                COUNT(*) FILTER (WHERE delivered_at IS NOT NULL) AS delivered,
                MIN(next_try_at) FILTER (WHERE delivered_at IS NULL AND gave_up_at IS NULL) AS next_try
             FROM ' . self::OUT . ' WHERE site_key = :site', ['site' => $site->key]) ?: [];
        $received = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::IN . ' WHERE site_key = :site', ['site' => $site->key]);

        return new ReportCounts(Rows::int($row['waiting'] ?? 0), Rows::int($row['given_up'] ?? 0), Rows::int($row['delivered'] ?? 0), $received,
            Rows::instant($row['next_try'] ?? null, new DateTimeZone('UTC')));
    }

    /**
     * The reports about a sale that were queued for connected sites, oldest first: for a sale's page.
     *
     * @return list<array{site:string,kind:string,made_at:DateTimeImmutable,delivered_at:?DateTimeImmutable,gave_up_at:?DateTimeImmutable}>
     */
    public function forSale(string $reference): array
    {
        $utc = new DateTimeZone('UTC');
        $rows = $this->connection->fetchAllAssociative("SELECT site_key, kind, made_at, delivered_at, gave_up_at FROM " . self::OUT . " WHERE body->'sale'->>'reference' = :reference ORDER BY made_at, id",
            ['reference' => $reference]);

        return array_map(static fn (array $row): array => ['site' => Rows::text($row['site_key']), 'kind' => Rows::text($row['kind']),
            'made_at' => Rows::instant($row['made_at'], $utc) ?? new DateTimeImmutable(), 'delivered_at' => Rows::instant($row['delivered_at'], $utc), 'gave_up_at' => Rows::instant($row['gave_up_at'], $utc)], $rows);
    }

    /** POSTs a body of reports to a site: null when accepted, else the problem in words (noted on the site either way). */
    private function post(Site $site, string $body, DateTimeImmutable $now): ?string
    {
        try {
            $signature = $this->sites->sign($site, $body, $now);
        } catch (\RuntimeException) {
            $problem = 'Its shared secret here could not be read. Put the secret in again.';
            $this->sites->problem($site, $problem, $now);

            return $problem;
        }
        $apart = null;
        try {
            $response = ($this->http ?? throw new \LogicException('Reports are sent only with an HTTP client.'))->request('POST', $site->base() . self::PATH, [
                'headers' => [self::SIGNATURE_HEADER => $signature, self::FROM_HEADER => $this->from(), 'Content-Type' => 'application/json'],
                'body' => $body, 'timeout' => 20, 'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $apart = self::apart($response->getHeaders(false), $now);
            $problem = $status === 200 ? null : $this->refusal($status, $apart);
        } catch (TransportExceptionInterface) {
            $problem = 'The other site could not be reached. Its reports are tried again later.';
        }
        if ($problem === null) {
            $this->sites->sentTo($site, $now, $apart);
        } else {
            $this->sites->problem($site, $problem, $now, $apart);
        }

        return $problem;
    }

    /**
     * Each report of a message not accepted is tried again later: after the first wait, then twice as long each time,
     * never longer apart than the longest.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function retry(array $rows, string $problem, DateTimeImmutable $now): void
    {
        $first = $this->settings->siteRetryFirst()->lengthInSeconds();
        $longest = $this->settings->siteRetryLongest()->lengthInSeconds();
        foreach ($rows as $row) {
            $attempts = Rows::int($row['attempts']) + 1;
            $wait = (int) min($longest, $first * (2 ** min($attempts - 1, 30)));
            $this->connection->executeStatement('UPDATE ' . self::OUT . ' SET attempts = :attempts, next_try_at = :next, last_error = :error WHERE id = :id',
                ['attempts' => $attempts, 'next' => Rows::sql($now->modify('+' . max(1, $wait) . ' seconds')), 'error' => mb_substr($problem, 0, 300), 'id' => Rows::int($row['id'])]);
        }
    }

    /** What an answer other than 200 means, in words, naming the clocks when they are too far apart. */
    private function refusal(int $status, ?int $apart): string
    {
        $tolerance = $this->settings->siteTolerance()->lengthInSeconds();
        if ($status === 401 && $apart !== null && abs($apart) > $tolerance) {
            return sprintf('The other site refused this site\'s signature because the two clocks are %s apart, more than the %s allowed. Set both clocks right.',
                Sites::apart($apart), Sites::apart($tolerance));
        }
        if ($status === 401) {
            return 'The other site did not accept this site\'s signature. Check that both have the same shared secret.';
        }

        return sprintf('The other site answered with an error (HTTP %d). Its reports are tried again later.', $status);
    }

    /**
     * The sale's first and last touches that came from connected sites, by site and by the click's reference there, with
     * whether each is the first touch and the last.
     *
     * @return array<string,array<string,array{bool,bool}>>
     */
    private function clicksBySite(Sale $sale): array
    {
        [$first, $last] = $sale->firstTouchId !== null || $sale->lastTouchId !== null
            ? [$sale->firstTouchId === null ? null : $this->touches->byId($sale->firstTouchId), $sale->lastTouchId === null ? null : $this->touches->byId($sale->lastTouchId)]
            : $this->provisionalTouches($sale);
        $clicks = [];
        foreach (['first' => $first, 'last' => $last] as $which => $touch) {
            if ($touch === null || $touch->originSite === null || $touch->originReference === null) {
                continue;
            }
            $flags = $clicks[$touch->originSite][$touch->originReference] ?? [false, false];
            $flags[$which === 'first' ? 0 : 1] = true;
            $clicks[$touch->originSite][$touch->originReference] = $flags;
        }

        return $clicks;
    }

    /**
     * For a sale whose campaign is not worked out yet (placed, not paid): the buyer's first and last counted touches within
     * MARKETING_SALE_COUNTS_WITHIN before it was placed, among the visitor that placed it and the account's visitors.
     *
     * @return array{?Touch,?Touch}
     */
    private function provisionalTouches(Sale $sale): array
    {
        $since = $this->settings->saleCountsWithin()->before($sale->placedAt);
        $counted = array_values(array_filter($this->touchesOf($sale->visitorId, $sale->account),
            static fn (Touch $touch): bool => $touch->counted && $touch->at >= $since && $touch->at <= $sale->placedAt));

        return [$counted[0] ?? null, $counted === [] ? null : $counted[count($counted) - 1]];
    }

    /** The touch an event counts for: its own, else the reader's latest counted touch within the sale setting before it. */
    private function currentTouch(JourneyEvent $event): ?Touch
    {
        if ($event->touchId !== null) {
            return $this->touches->byId($event->touchId);
        }
        $since = $this->settings->saleCountsWithin()->before($event->at);
        $latest = null;
        foreach ($this->touchesOf($event->visitorId, $event->account) as $touch) {
            if ($touch->counted && $touch->at >= $since && $touch->at <= $event->at) {
                $latest = $touch;
            }
        }

        return $latest;
    }

    /** @return list<Touch> the touches of a visitor and of an account's visitors, oldest first */
    private function touchesOf(?int $visitorId, ?string $account): array
    {
        $ids = $visitorId === null ? [] : [$visitorId];
        if ($account !== null && $account !== '') {
            foreach ($this->visitors->ofAccount($account) as $visitor) {
                $ids[] = $visitor->id;
            }
        }

        return $this->touches->forVisitors(array_values(array_unique($ids)));
    }

    /** Runs a queueing step under a savepoint: a failure is logged and never undoes what it reports. */
    private function safely(string $what, callable $step): void
    {
        try {
            $this->connection->transactional(static function () use ($step): void {
                $step();
            });
        } catch (\Throwable $failure) {
            $this->logger->error('A report to a connected site about ' . $what . ' could not be queued; what it reports was kept all the same.', ['exception' => $failure]);
        }
    }

    /** This site's address, as it names itself to the other. */
    private function from(): string
    {
        return $this->installation?->address() ?? '';
    }

    /**
     * How far the other site's clock is ahead of this one's (behind when below zero), from its answer's Date header.
     *
     * @param array<string,list<string>> $headers
     */
    private static function apart(array $headers, DateTimeImmutable $now): ?int
    {
        $date = $headers['date'][0] ?? null;
        if (!is_string($date)) {
            return null;
        }
        $theirs = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', trim($date), new DateTimeZone('UTC')) ?: DateTimeImmutable::createFromFormat(DATE_RFC2822, trim($date));

        return $theirs === false ? null : $theirs->getTimestamp() - $now->getTimestamp();
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
