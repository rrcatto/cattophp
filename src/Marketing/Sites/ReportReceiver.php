<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\EventInput;
use CattoPHP\Marketing\Rows;
use CattoPHP\Marketing\Sales\ReportedSale;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Touches;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The reports a connected site sends back about the visitors this site's links sent it (POST /webhooks/marketing,
 * Pages\SiteReportsController), and its checks of the connection (GET /webhooks/marketing/check).
 *
 * The sender is found by X-CattoPHP-From, and the signature and its time are checked (Sites::verify()). An unknown
 * sender, a signature that does not match and a time too far from this site's are refused with nothing said about why;
 * the site's card notes the refusal with its reason. When the check passes, each report is handled in a transaction of
 * its own, kept once by its key in reports in: one received before is skipped; one whose click is none of this site's
 * touches is kept as unknown_click and logged; a registration or subscription is recorded as the journey's event of that
 * touch's visitor, campaign and publication (key site:<sender>:<key>); a sale or its money goes to Sales::reported(), the
 * sale kept with the sender as where it was sold and this touch as its first or last as the report says. A fault of the
 * database stops there and the message is answered with an error, so the other site sends it again, those kept already
 * being skipped then.
 */
final class ReportReceiver
{
    /** The largest message read, in bytes: 50 reports with their lines fit well within it. */
    public const LARGEST = 2_000_000;

    public function __construct(
        private readonly Connection $connection,
        private readonly Sites $sites,
        private readonly Touches $touches,
        private readonly Events $events,
        private readonly ClockInterface $clock,
        private readonly ?Sales $sales = null,
        private readonly ?Installation $installation = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * A message of reports received: how many were recorded now, null when it was refused (answer a bare 401).
     *
     * @throws \Throwable when a report could not be kept for a fault of the database (answer with an error, so it is sent again)
     */
    public function receive(string $from, string $signature, string $body): ?int
    {
        $now = $this->clock->now();
        $site = $this->accepted($from, $signature, $body, $now, 'message');
        if ($site === null) {
            return null;
        }
        $reports = strlen($body) > self::LARGEST ? null : json_decode($body, true);
        if (!is_array($reports) || !array_is_list($reports)) {
            $this->sites->refused($site, 'message: it was signed but was not a list of reports.', $now);

            return null;
        }
        $recorded = 0;
        foreach ($reports as $report) {
            if ($this->one($site, is_array($report) ? $report : [], $now) === Sales::RECORDED) {
                $recorded++;
            }
        }

        return $recorded;
    }

    /** A check of the connection: this site's name when the signature checks, null when it was refused (a bare 401). */
    public function answerCheck(string $from, string $signature): ?string
    {
        $site = $this->accepted($from, $signature, '', $this->clock->now(), 'check of the connection');

        return $site === null ? null : ($this->installation?->name() ?? 'A site');
    }

    /** The sender, when it is a connected site and its signature checks within the time allowed; its acceptance noted. */
    private function accepted(string $from, string $signature, string $body, DateTimeImmutable $now, string $what): ?Site
    {
        $site = $this->sites->byAddress($from);
        if ($site === null) {
            $this->logger->notice('A ' . $what . ' from a site that is not connected was refused.', ['from' => mb_substr($from, 0, 120)]);

            return null;
        }
        try {
            $this->sites->verify($site, $signature, $body, $now);
        } catch (SiteRefused $refused) {
            $this->logger->notice('A ' . $what . ' from a connected site was refused: ' . $refused->getMessage() . '.', ['site' => $site->key]);
            $this->sites->refused($site, $what . ': ' . $refused->getMessage() . '.', $now);

            return null;
        }
        $this->sites->heardFrom($site, $now);

        return $site;
    }

    /**
     * One report handled, kept once by its key: what became of it (Sales::RECORDED, UNKNOWN_CLICK or REFUSED), or '' for
     * one received before.
     *
     * @param array<mixed> $report
     */
    private function one(Site $site, array $report, DateTimeImmutable $now): string
    {
        $key = is_string($report['key'] ?? null) ? mb_substr($report['key'], 0, 200) : '';
        $kind = is_string($report['kind'] ?? null) && preg_match(Events::KIND, $report['kind']) === 1 ? $report['kind'] : 'unknown';
        if ($key === '') {
            $this->logger->warning('A report from a connected site had no key; it was not kept.', ['site' => $site->key]);

            return Sales::REFUSED;
        }

        return $this->connection->transactional(function (Connection $connection) use ($site, $report, $key, $kind, $now): string {
            $id = $connection->fetchOne('INSERT INTO ' . SiteReports::IN . " (site_key, key, kind, received_at, result) VALUES (:site, :key, :kind, :now, 'recorded')
                 ON CONFLICT (site_key, key) DO NOTHING RETURNING id", ['site' => $site->key, 'key' => $key, 'kind' => $kind, 'now' => Rows::sql($now)]);
            if ($id === false) {
                return '';
            }
            $result = $this->handle($site, $report, $key, $kind);
            if ($result !== Sales::RECORDED) {
                $connection->executeStatement('UPDATE ' . SiteReports::IN . ' SET result = :result WHERE id = :id', ['result' => $result, 'id' => Rows::int($id)]);
            }

            return $result;
        });
    }

    /** @param array<mixed> $report */
    private function handle(Site $site, array $report, string $key, string $kind): string
    {
        if (in_array($kind, ReportedSale::KINDS, true)) {
            try {
                $sale = ReportedSale::fromBody($report);
            } catch (\InvalidArgumentException $refused) {
                $this->logger->warning($refused->getMessage(), ['site' => $site->key, 'key' => $key]);

                return Sales::REFUSED;
            }
            if ($this->sales === null) {
                $this->logger->warning('A report about a sale from a connected site was refused: this site keeps no sales.', ['site' => $site->key, 'key' => $key]);

                return Sales::REFUSED;
            }

            return $this->sales->reported($site, $sale);
        }
        if (!in_array($kind, SiteReports::EVENT_KINDS, true) || ($report['version'] ?? null) !== ReportedSale::VERSION) {
            $this->logger->warning('A report from a connected site was of a kind this site does not take; it was not kept.', ['site' => $site->key, 'key' => $key, 'kind' => $kind]);

            return Sales::REFUSED;
        }
        $click = is_string($report['click'] ?? null) ? $this->touches->byReference($report['click']) : null;
        if ($click === null) {
            $this->logger->notice('A report from a connected site names a click this site does not know; it was kept as an unknown click.', ['site' => $site->key, 'key' => $key]);

            return Sales::UNKNOWN_CLICK;
        }
        $at = is_string($report['at'] ?? null) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $report['at']) : false;
        $offer = is_array($report['offer'] ?? null) ? $report['offer'] : [];
        $type = is_string($offer['type'] ?? null) && preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $offer['type']) === 1 ? $offer['type'] : null;
        $reference = $type !== null && is_string($offer['reference'] ?? null) && $offer['reference'] !== '' ? mb_substr($offer['reference'], 0, 120) : null;
        $label = is_string($offer['label'] ?? null) ? mb_substr(trim($offer['label']), 0, 80) : '';
        $this->events->record(new EventInput($kind, $at === false ? $this->clock->now() : $at, $click->visitorId, null, $click->campaignId, $click->publicationId, $click->id, null,
            $reference === null ? null : $type, $reference, $label === '' ? [] : ['offer' => $label], '', false, 'site:' . $site->key . ':' . $key, $site->key));

        return Sales::RECORDED;
    }
}
