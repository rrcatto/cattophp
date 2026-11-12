<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use Doctrine\DBAL\Connection;

/**
 * The links in a campaign (cattophp_mailing_links), each kept once with an id its copies' tracked links carry, and who
 * clicked each how often (cattophp_mailing_link_clicks). A click is also counted on the copy, and counts as an opening.
 */
final class Links
{
    public const TABLE = 'cattophp_mailing_links';
    public const CLICKS = 'cattophp_mailing_link_clicks';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** The id of a link in a campaign, made the first time it is used. */
    public function id(int $campaignId, string $url): int
    {
        $id = $this->connection->fetchOne('SELECT id FROM ' . self::TABLE . ' WHERE campaign_id = :campaign AND url = :url', ['campaign' => $campaignId, 'url' => $url]);
        if ($id !== false && $id !== null) return (int) $id;

        return (int) $this->connection->fetchOne(
            'INSERT INTO ' . self::TABLE . ' (campaign_id, url) VALUES (:campaign, :url) ON CONFLICT (campaign_id, url) DO UPDATE SET url = EXCLUDED.url RETURNING id',
            ['campaign' => $campaignId, 'url' => $url]
        );
    }

    /** The address of a link in a campaign; null when the campaign has no such link. */
    public function url(int $campaignId, int $linkId): ?string
    {
        $url = $this->connection->fetchOne('SELECT url FROM ' . self::TABLE . ' WHERE id = :id AND campaign_id = :campaign', ['id' => $linkId, 'campaign' => $campaignId]);

        return is_string($url) ? $url : null;
    }

    /** A click on a link in a copy: counted on the copy (and as an opening) and on the link. */
    public function recordClick(int $copyId, int $linkId): void
    {
        $this->connection->executeStatement('UPDATE ' . Copies::TABLE . ' SET click_count = click_count + 1, first_clicked_at = COALESCE(first_clicked_at, NOW()), first_opened_at = COALESCE(first_opened_at, NOW()) WHERE id = :id', ['id' => $copyId]);
        $this->connection->executeStatement(
            'INSERT INTO ' . self::CLICKS . ' (link_id, copy_id, clicks, first_at, last_at) VALUES (:link, :copy, 1, NOW(), NOW())
             ON CONFLICT (link_id, copy_id) DO UPDATE SET clicks = ' . self::CLICKS . '.clicks + 1, last_at = NOW()',
            ['link' => $linkId, 'copy' => $copyId]
        );
    }

    /** @return list<array{url:string,clicks:int,people:int}> each link of a campaign with its clicks, the most clicked first */
    public function figures(int $campaignId): array
    {
        return array_map(static fn(array $row): array => ['url' => (string) $row['url'], 'clicks' => (int) $row['clicks'], 'people' => (int) $row['people']], $this->connection->fetchAllAssociative(
            'SELECT l.url, COALESCE(SUM(k.clicks), 0) AS clicks, COUNT(k.copy_id) AS people FROM ' . self::TABLE . ' l
               LEFT JOIN ' . self::CLICKS . ' k ON k.link_id = l.id WHERE l.campaign_id = :campaign GROUP BY l.id, l.url ORDER BY 2 DESC, l.id',
            ['campaign' => $campaignId]
        ));
    }
}
