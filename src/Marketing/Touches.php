<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Each opening of a tracking link, recorded whatever opened it: a person's is a click unless it is the same visit as their
 * last opening of the same link or the campaign is not running; a link preview's, a search engine's and a bot's are set
 * aside. The IP address is removed after its time; the rest stays for the figures. Each new opening has a reference of
 * ten letters and digits, how it is named to a connected site (never by its number); openings from before step 3 have
 * none.
 */
final class Touches
{
    public const TABLE = 'cattophp_marketing_touches';
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** A touch's reference: ten of the readable letters and digits references are made of. */
    public const REFERENCE = '/^[a-z0-9]{8,10}$/';
    private const REFERENCE_LENGTH = 10;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Records an opening and gives its id, with a new reference of its own. Text is cut to its column's length. An
     * arrival from a connected site gives that site, its click's reference and the publication's code there.
     *
     * @param array<string,?string> $utm the utm_* values given, by their names
     */
    public function record(int $campaignId, ?int $publicationId, ?int $visitorId, string $source, array $utm, DateTimeImmutable $at, ?string $ip, string $country, ?string $referrerHost,
        string $agent, ?string $notCountedBecause, bool $firstForVisitor, bool $doNotTrack, string $landing, ?string $originSite = null, ?string $originReference = null,
        ?string $originPublication = null): int
    {
        $values = ['campaign_id' => $campaignId, 'publication_id' => $publicationId, 'visitor_id' => $visitorId, 'source' => mb_substr($source, 0, 64), 'at' => Rows::sql($at),
            'ip' => $ip, 'country' => strtoupper(substr($country, 0, 2)), 'referrer_host' => $referrerHost === null ? null : substr($referrerHost, 0, 255), 'agent' => $agent,
            'counted' => $notCountedBecause === null ? 'true' : 'false', 'not_counted_because' => $notCountedBecause, 'first_for_visitor' => $firstForVisitor ? 'true' : 'false',
            'do_not_track' => $doNotTrack ? 'true' : 'false', 'landing' => mb_substr($landing, 0, 2000), 'origin_site' => $originSite, 'origin_reference' => $originReference,
            'origin_publication' => $originPublication === null ? null : mb_substr($originPublication, 0, 12)];
        foreach (self::UTM as $name) {
            $value = $utm[$name] ?? null;
            $values[$name] = $value === null || $value === '' ? null : mb_substr($value, 0, 64);
        }
        $columns = [...array_keys($values), 'reference'];
        // A new reference until one is free: ON CONFLICT leaves a caller's transaction usable, as a refused insert would not.
        do {
            $id = $this->connection->fetchOne(
                'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ') ON CONFLICT (reference) DO NOTHING RETURNING id',
                $values + ['reference' => Rows::reference(self::REFERENCE_LENGTH)],
            );
        } while ($id === false);

        return (int) $id;
    }

    /** The opening a reference names (how a connected site names a click), or null. */
    public function byReference(string $reference): ?Touch
    {
        $reference = strtolower(trim($reference));
        if (preg_match(self::REFERENCE, $reference) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE reference = :reference', ['reference' => $reference]);

        return $row === false ? null : self::touch($row);
    }

    /** The arrival from a connected site of that site's click, by its reference there, or null: a click is recorded once. */
    public function byOrigin(string $site, string $reference): ?Touch
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE origin_site = :site AND origin_reference = :reference', ['site' => $site, 'reference' => $reference]);

        return $row === false ? null : self::touch($row);
    }

    public function byId(int $id): ?Touch
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : self::touch($row);
    }

    /** Whether a visitor has opened any tracking link before. */
    public function visitorSeenBefore(int $visitorId): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE visitor_id = :id LIMIT 1', ['id' => $visitorId]) !== false;
    }

    /** Whether a visitor opened the same link (campaign and publication) since a moment: the same visit. */
    public function sameLinkSince(int $visitorId, int $campaignId, ?int $publicationId, DateTimeImmutable $since): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE visitor_id = :visitor AND campaign_id = :campaign AND ' . ($publicationId === null ? 'publication_id IS NULL' : 'publication_id = :publication')
            . " AND agent = 'person' AND at >= :since LIMIT 1",
            ['visitor' => $visitorId, 'campaign' => $campaignId, 'since' => Rows::sql($since)] + ($publicationId === null ? [] : ['publication' => $publicationId]),
        ) !== false;
    }

    /** @return list<Touch> a campaign's openings, the newest first */
    public function forCampaign(int $campaignId, int $limit = 100, int $offset = 0): array
    {
        return array_map(self::touch(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE campaign_id = :id ORDER BY at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['id' => $campaignId, 'limit' => max(1, $limit), 'offset' => max(0, $offset)]));
    }

    /** @return list<Touch> a visitor's openings, the oldest first: their way in */
    public function forVisitor(int $visitorId): array
    {
        return array_map(self::touch(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE visitor_id = :id ORDER BY at, id', ['id' => $visitorId]));
    }

    /**
     * @param list<int> $visitorIds one person's visitors
     * @return list<Touch> their openings together, the oldest first
     */
    public function forVisitors(array $visitorIds): array
    {
        if ($visitorIds === []) {
            return [];
        }

        return array_map(self::touch(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE visitor_id IN (:ids) ORDER BY at, id',
            ['ids' => $visitorIds], ['ids' => ArrayParameterType::INTEGER]));
    }

    /** Removes the IP address of every opening before a moment, keeping the rest. Gives how many. */
    public function removeAddressesBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET ip = NULL WHERE ip IS NOT NULL AND at < :before', ['before' => Rows::sql($before)]);
    }

    /** @param array<string,mixed> $row */
    private static function touch(array $row): Touch
    {
        $utm = [];
        foreach (self::UTM as $name) {
            $utm[$name] = Rows::nullableText($row[$name]);
        }

        return new Touch(Rows::int($row['id']), Rows::nullableInt($row['visitor_id']), Rows::int($row['campaign_id']), Rows::nullableInt($row['publication_id']), Rows::text($row['source']), $utm,
            Rows::instant($row['at'], new \DateTimeZone('UTC')) ?? new DateTimeImmutable(), Rows::nullableText($row['ip']), Rows::text($row['country']), Rows::nullableText($row['referrer_host']),
            Rows::text($row['agent']), Rows::bool($row['counted']), Rows::nullableText($row['not_counted_because']), Rows::bool($row['first_for_visitor']), Rows::bool($row['do_not_track']),
            Rows::text($row['landing']), Rows::nullableText($row['origin_site']), Rows::nullableText($row['origin_reference']), Rows::nullableText($row['reference'] ?? null),
            Rows::nullableText($row['origin_publication'] ?? null));
    }
}
