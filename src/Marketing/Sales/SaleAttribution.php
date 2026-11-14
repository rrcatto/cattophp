<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use CattoPHP\Foundation\Duration;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Rows;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Which campaign gets a sale. The touches that count are a person's clicks the tracking links counted (not the same
 * visit, the campaign running), of any visitor tied to the buyer's account or of the browser that placed the order, made
 * at or before the moment and no earlier than the counting period before it. The first touch is the earliest and the
 * last touch the latest, each kept with its campaign and publication (by link).
 *
 * The campaign whose promotion code the sale used (campaigns.promotion; of several, the one running at the moment, else
 * the one that started most recently; a draft never) is kept as a fact either way. How it counts is the setting
 * MARKETING_PROMOTION_COUNTS as it was: no_link, it gets the sale only when no touch counted (first and last, by
 * promotion, no publication); always, it gets the sale even over another campaign's link (each side whose link was
 * another campaign's counts for it by promotion, keeping that link's touch as the fact). With neither, no campaign.
 */
final class SaleAttribution
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function for(?string $account, ?int $visitorId, DateTimeImmutable $at, Duration $within, ?string $promotion = null, string $promotionCounts = 'no_link'): Attribution
    {
        $first = $this->touch($account, $visitorId, $at, $within, 'ASC');
        $last = $first === null ? null : $this->touch($account, $visitorId, $at, $within, 'DESC');
        $promoted = $promotion === null ? null : $this->promotionCampaign($promotion, $at);
        $sides = [];
        foreach (['first' => $first, 'last' => $last] as $side => $touch) {
            if ($touch !== null && ($promoted === null || $promotionCounts !== 'always' || $touch['campaign'] === $promoted)) {
                $sides[$side] = [$touch['id'], $touch['campaign'], $touch['publication'], 'link'];
            } elseif ($promoted !== null) {
                // The code counts over another campaign's link (always), or where no link counted at all (either setting).
                $sides[$side] = [$touch['id'] ?? null, $promoted, null, 'promotion'];
            } else {
                $sides[$side] = [null, null, null, null];
            }
        }

        return new Attribution($sides['first'][0], $sides['last'][0], $sides['first'][1], $sides['last'][1], $sides['first'][2], $sides['last'][2], $sides['first'][3],
            $sides['last'][3], $promoted, $promotionCounts === 'always' ? 'always' : 'no_link', $within->text, $at);
    }

    /**
     * The campaign that carries a promotion code at a moment: of several, the one running then, else the one that started
     * most recently (its start, else when it was made). A draft carries none; codes are compared without regard to case.
     */
    public function promotionCampaign(string $code, DateTimeImmutable $at): ?int
    {
        $id = $this->connection->fetchOne(
            "SELECT id FROM " . Campaigns::TABLE . " WHERE promotion IS NOT NULL AND lower(promotion) = lower(:code) AND status <> 'draft'
             ORDER BY (status = 'active' AND (starts_at IS NULL OR starts_at <= :at) AND (ends_at IS NULL OR ends_at > :at)) DESC, COALESCE(starts_at, created_at) DESC, id DESC LIMIT 1",
            ['code' => trim($code), 'at' => Rows::sql($at)],
        );

        return $id === false ? null : Rows::int($id);
    }

    /** @return array{id:int,campaign:int,publication:?int}|null the earliest (ASC) or latest (DESC) touch that counts */
    private function touch(?string $account, ?int $visitorId, DateTimeImmutable $at, Duration $within, string $direction): ?array
    {
        $who = [];
        $params = ['since' => Rows::sql($within->before($at)), 'at' => Rows::sql($at)];
        if ($visitorId !== null) {
            $who[] = 't.visitor_id = :visitor';
            $params['visitor'] = $visitorId;
        }
        if ($account !== null && $account !== '') {
            $who[] = 't.visitor_id IN (SELECT v.id FROM ' . Visitors::TABLE . ' v WHERE v.account = :account)';
            $params['account'] = $account;
        }
        if ($who === []) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT t.id, t.campaign_id, t.publication_id FROM ' . Touches::TABLE . ' t
            WHERE t.counted AND t.at >= :since AND t.at <= :at AND (' . implode(' OR ', $who) . ') ORDER BY t.at ' . $direction . ', t.id ' . $direction . ' LIMIT 1', $params);

        return $row === false ? null : ['id' => Rows::int($row['id']), 'campaign' => Rows::int($row['campaign_id']), 'publication' => Rows::nullableInt($row['publication_id'])];
    }
}
