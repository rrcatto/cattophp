<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Rows;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * The kept versions of landing pages. Publishing writes a published version with the whole page as it is; every change to
 * a published page writes another straight after it, in the same transaction, with what changed in words; unpublishing
 * writes an unpublished one. A draft's changes write nothing. A version is never changed or deleted while its page exists
 * (the database refuses), and is kept as long as the page, so it has no length of time.
 */
final class LandingVersions
{
    public const TABLE = 'cattophp_marketing_landing_versions';

    public function __construct(private readonly Connection $connection, private readonly ?Campaigns $campaigns = null)
    {
    }

    /**
     * The page as it is now, as a version keeps it: its settings and every section in order (kind, reference, enabled,
     * width, shown when, data), read straight from the records.
     *
     * @return array{settings:array<string,mixed>,sections:list<array<string,mixed>>}
     */
    public function snapshot(int $pageId): array
    {
        $page = $this->connection->fetchAssociative('SELECT slug, meta_title, meta_description, share_picture, not_running, not_running_to FROM ' . LandingPages::TABLE . ' WHERE id = :id', ['id' => $pageId]);
        if ($page === false) {
            throw new \RuntimeException('There is no such landing page.');
        }
        $share = json_decode(Rows::text($page['share_picture'] ?? 'null'), true);
        $sections = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM ' . LandingPages::SECTIONS . ' WHERE landing_page_id = :id ORDER BY position, id', ['id' => $pageId]) as $row) {
            $data = json_decode(Rows::text($row['data']), true);
            $sections[] = ['kind' => Rows::text($row['kind']), 'reference' => Rows::text($row['reference']), 'enabled' => Rows::bool($row['enabled']), 'width' => Rows::text($row['width']),
                'shown_when' => Rows::text($row['shown_when']), 'data' => is_array($data) ? $data : []];
        }

        return ['settings' => ['slug' => Rows::nullableText($page['slug']), 'meta_title' => Rows::nullableText($page['meta_title']), 'meta_description' => Rows::nullableText($page['meta_description']),
            'share_picture' => is_array($share) ? $share : null, 'not_running' => Rows::text($page['not_running']), 'not_running_to' => Rows::nullableText($page['not_running_to'])], 'sections' => $sections];
    }

    /** Writes the next version of a page: published with the page as it is now, or unpublished. Gives its number. */
    public function write(int $pageId, string $kind, string $whatChanged, DateTimeImmutable $at, ?Actor $actor = null): int
    {
        if (!in_array($kind, [LandingVersion::PUBLISHED, LandingVersion::UNPUBLISHED], true)) {
            throw new \InvalidArgumentException('A version is published or unpublished.');
        }
        $number = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(number), 0) + 1 FROM ' . self::TABLE . ' WHERE landing_page_id = :id', ['id' => $pageId]);
        $content = $kind === LandingVersion::PUBLISHED ? json_encode($this->snapshot($pageId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (landing_page_id, number, kind, at, made_by, what_changed, content) VALUES (:page, :number, :kind, :at, :by, :what, CAST(:content AS jsonb))',
            ['page' => $pageId, 'number' => $number, 'kind' => $kind, 'at' => Rows::sql($at), 'by' => $actor?->id, 'what' => mb_substr($whatChanged, 0, 300), 'content' => $content],
        );

        return $number;
    }

    /** @return list<LandingVersion> a page's versions, the newest first, each with until when it was what visitors saw */
    public function list(LandingPage $page): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE landing_page_id = :id ORDER BY number', ['id' => $page->id]);
        $versions = [];
        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1] ?? null;
            $versions[] = $this->version($row, $next === null ? null : Rows::instant($next['at'], $this->zone()), $next === null ? null : Rows::text($next['kind']));
        }

        return array_reverse($versions);
    }

    public function get(LandingPage $page, int $number): ?LandingVersion
    {
        foreach ($this->list($page) as $version) {
            if ($version->number === $number) {
                return $version;
            }
        }

        return null;
    }

    /** The version visitors see now, or null when the page is not published. */
    public function current(LandingPage $page): ?LandingVersion
    {
        $versions = $this->list($page);

        return isset($versions[0]) && $versions[0]->isCurrent() ? $versions[0] : null;
    }

    private function zone(): DateTimeZone
    {
        return $this->campaigns?->zone() ?? new DateTimeZone('UTC');
    }

    /** @param array<string,mixed> $row */
    private function version(array $row, ?DateTimeImmutable $until, ?string $endedBy): LandingVersion
    {
        $content = json_decode(Rows::text($row['content'] ?? 'null'), true);

        return new LandingVersion(Rows::int($row['id']), Rows::int($row['landing_page_id']), Rows::int($row['number']), Rows::text($row['kind']),
            Rows::instant($row['at'], $this->zone()) ?? new DateTimeImmutable(), Rows::nullableText($row['made_by']), Rows::text($row['what_changed']), is_array($content) ? $content : null,
            Rows::text($row['kind']) === LandingVersion::PUBLISHED ? $until : null, $endedBy);
    }
}
