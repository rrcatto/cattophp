<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Rows;
use Doctrine\DBAL\Connection;

/**
 * How often the offers were viewed and favourited by people who came by a campaign, for its page: each journey event
 * keeps the campaign the reader came by (their latest counted click within MARKETING_SALE_COUNTS_WITHIN before it), so
 * only those who came by this campaign within that time before they did it are counted. A view is counted once a visit
 * (Journey::viewed()).
 */
final class OfferInterest
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string,array{viewed:int,favourited:int}> each offer's views and favourites, by its key (type:reference) */
    public function forCampaign(int $campaignId): array
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative('SELECT offer_type, offer_reference, COUNT(*) FILTER (WHERE kind = :viewed) AS viewed, COUNT(*) FILTER (WHERE kind = :favourited) AS favourited
                FROM ' . Events::TABLE . ' WHERE campaign_id = :campaign AND offer_type IS NOT NULL AND offer_reference IS NOT NULL AND kind IN (:viewed, :favourited)
                GROUP BY offer_type, offer_reference', ['campaign' => $campaignId, 'viewed' => Events::OFFER_VIEWED, 'favourited' => Events::FAVOURITED]) as $row) {
            $counts[Rows::text($row['offer_type']) . ':' . Rows::text($row['offer_reference'])] = ['viewed' => Rows::int($row['viewed']), 'favourited' => Rows::int($row['favourited'])];
        }

        return $counts;
    }
}
