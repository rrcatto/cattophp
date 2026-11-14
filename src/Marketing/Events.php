<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;
use CattoPHP\Marketing\Journey\EventInput;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The journey's events, in one table: written by Marketing\Journey (a landing page opened, an offer's button pressed, an
 * offer viewed, favourited, put in the basket, a registration, a sign-in, coming back...) and Marketing\Sales (an order
 * placed, a payment failed, paid, refunded...), each kind a small-letter word, so a later kind needs no change to the
 * rows. An event with an idempotency key is written once, however often it is reported; one of a sale names it. No IP
 * address is kept; forgetting an account removes it here too.
 */
final class Events
{
    public const TABLE = 'cattophp_marketing_events';

    public const LANDING_OPENED = 'landing_opened';
    public const LANDING_PRESSED = 'landing_pressed';

    // The journey's kinds from step 3 (Marketing\Journey).
    public const OFFER_VIEWED = 'offer_viewed';
    public const FAVOURITED = 'favourited';
    public const UNFAVOURITED = 'unfavourited';
    public const BASKET_ADDED = 'basket_added';
    public const BASKET_REMOVED = 'basket_removed';
    public const TAKEN = 'taken';
    public const SUBSCRIBED = 'subscribed';
    public const REGISTERED = 'registered';
    public const SIGNED_IN = 'signed_in';
    public const CAME_BACK = 'came_back';
    public const EMAIL_OPENED = 'email_opened';

    // A sale's kinds (Marketing\Sales).
    public const ORDER_CREATED = 'order_created';
    public const PAYMENT_FAILED = 'payment_failed';
    public const ORDER_CANCELLED = 'order_cancelled';
    public const PAID = 'paid';
    public const REFUNDED = 'refunded';
    public const CHARGED_BACK = 'charged_back';

    /** Every kind CattoPHP records; a project's checks may hold to them, the table allows any small-letter word. */
    public const KINDS = [self::LANDING_OPENED, self::LANDING_PRESSED, self::OFFER_VIEWED, self::FAVOURITED, self::UNFAVOURITED, self::BASKET_ADDED, self::BASKET_REMOVED,
        self::TAKEN, self::SUBSCRIBED, self::REGISTERED, self::SIGNED_IN, self::CAME_BACK, self::EMAIL_OPENED, self::ORDER_CREATED, self::PAYMENT_FAILED, self::ORDER_CANCELLED,
        self::PAID, self::REFUNDED, self::CHARGED_BACK];

    public const KIND = '/^[a-z][a-z_]{0,39}$/';

    /** What a detail may hold: a few short words and numbers. */
    private const DETAIL_KEYS = 12;
    private const DETAIL_TEXT = 80;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Records an event and gives its id, or null when its idempotency key was recorded before. It is given as an
     * EventInput; the arguments one by one, as step 2 gave them, are still taken (the input's fields in the same order,
     * without the sale). Its detail keeps only words and numbers, at most twelve, each word cut to 80 characters.
     *
     * @param array<string,mixed> $detail
     */
    public function record(EventInput|string $kind, ?DateTimeImmutable $at = null, ?int $visitorId = null, ?string $account = null, ?int $campaignId = null, ?int $publicationId = null,
        ?int $touchId = null, ?int $landingPageId = null, ?string $offerType = null, ?string $offerReference = null, array $detail = [], string $country = '',
        bool $doNotTrack = false, ?string $idempotencyKey = null, ?string $originSite = null, ?string $originReference = null): ?int
    {
        if (is_string($kind)) {
            $kind = new EventInput($kind, $at ?? throw new \InvalidArgumentException('An event is recorded with its moment.'), $visitorId, $account, $campaignId, $publicationId, $touchId,
                $landingPageId, $offerType, $offerReference, $detail, $country, $doNotTrack, $idempotencyKey, $originSite, $originReference);
        }

        return $this->write($kind);
    }

    /** An event by its id, or null. */
    public function byId(int $id): ?JourneyEvent
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : self::event($row);
    }

    /** The event of an idempotency key, or null. */
    public function byKey(string $idempotencyKey): ?JourneyEvent
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE idempotency_key = :key', ['key' => mb_substr($idempotencyKey, 0, 120)]);

        return $row === false ? null : self::event($row);
    }

    /**
     * The latest event of some kinds by a visitor or an account (either, when both are given), about an offer if one is
     * given, since a moment if one is given: how a repeat within the same visit is found.
     *
     * @param list<string> $kinds
     */
    public function latest(array $kinds, ?int $visitorId, ?string $account, ?string $offerType = null, ?string $offerReference = null, ?DateTimeImmutable $since = null): ?JourneyEvent
    {
        if ($kinds === [] || ($visitorId === null && $account === null)) {
            return null;
        }
        $who = [];
        $params = ['kinds' => $kinds];
        $types = ['kinds' => ArrayParameterType::STRING];
        if ($visitorId !== null) {
            $who[] = 'visitor_id = :visitor';
            $params['visitor'] = $visitorId;
        }
        if ($account !== null) {
            $who[] = 'account = :account';
            $params['account'] = $account;
        }
        $where = 'kind IN (:kinds) AND (' . implode(' OR ', $who) . ')';
        if ($offerType !== null && $offerReference !== null) {
            $where .= ' AND offer_type = :type AND offer_reference = :reference';
            $params += ['type' => $offerType, 'reference' => $offerReference];
        }
        if ($since !== null) {
            $where .= ' AND at >= :since';
            $params['since'] = Rows::sql($since);
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY at DESC, id DESC LIMIT 1', $params, $types);

        return $row === false ? null : self::event($row);
    }

    /**
     * One person's events: those of any of their visitors and those naming their account, the oldest first.
     *
     * @param list<int> $visitorIds
     * @return list<JourneyEvent>
     */
    public function forPerson(array $visitorIds, ?string $account, int $limit = 2000): array
    {
        $who = [];
        $params = ['limit' => max(1, $limit)];
        $types = [];
        if ($visitorIds !== []) {
            $who[] = 'visitor_id IN (:visitors)';
            $params['visitors'] = $visitorIds;
            $types['visitors'] = ArrayParameterType::INTEGER;
        }
        if ($account !== null) {
            $who[] = 'account = :account';
            $params['account'] = $account;
        }
        if ($who === []) {
            return [];
        }

        return array_map(self::event(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE ' . implode(' OR ', $who) . ' ORDER BY at, id LIMIT :limit',
            $params, $types));
    }

    /** @return list<JourneyEvent> a sale's events, the oldest first */
    public function forSale(int $saleId): array
    {
        return array_map(self::event(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE sale_id = :id ORDER BY at, id', ['id' => $saleId]));
    }

    /** The events of visitors made anonymous name nobody: their account removed. Gives how many. */
    public function anonymiseVisitors(): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' e SET account = NULL FROM ' . Visitors::TABLE . ' v
            WHERE e.visitor_id = v.id AND v.anonymised_at IS NOT NULL AND e.account IS NOT NULL');
    }

    private function write(EventInput $input): ?int
    {
        if (preg_match(self::KIND, $input->kind) !== 1) {
            throw new \InvalidArgumentException(sprintf('An event\'s kind is small letters and underscores, such as landing_opened: "%s" is not.', $input->kind));
        }
        $values = ['kind' => $input->kind, 'at' => Rows::sql($input->at), 'visitor_id' => $input->visitorId, 'account' => $input->account, 'campaign_id' => $input->campaignId,
            'publication_id' => $input->publicationId, 'touch_id' => $input->touchId, 'landing_page_id' => $input->landingPageId,
            'offer_type' => $input->offerType === null ? null : mb_substr($input->offerType, 0, 40), 'offer_reference' => $input->offerReference === null ? null : mb_substr($input->offerReference, 0, 120),
            'detail' => json_encode((object) self::detail($input->detail), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'country' => strtoupper(substr($input->country, 0, 2)), 'do_not_track' => $input->doNotTrack ? 'true' : 'false',
            'idempotency_key' => $input->idempotencyKey === null ? null : mb_substr($input->idempotencyKey, 0, 120), 'origin_site' => $input->originSite,
            'origin_reference' => $input->originReference, 'sale_id' => $input->saleId];
        $columns = array_keys($values);
        $placeholders = array_map(static fn(string $column): string => $column === 'detail' ? 'CAST(:detail AS jsonb)' : ':' . $column, $columns);
        $sql = 'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        if ($input->idempotencyKey === null) {
            return (int) $this->connection->fetchOne($sql . ' RETURNING id', $values);
        }
        // A repeat changes nothing, and leaves a caller's transaction usable, as a refused insert would not.
        $id = $this->connection->fetchOne($sql . ' ON CONFLICT (idempotency_key) DO NOTHING RETURNING id', $values);

        return $id === false ? null : (int) $id;
    }

    /** @return list<JourneyEvent> the latest events of every kind, newest first */
    public function recent(int $limit = 100): array
    {
        return $this->events('TRUE', [], $limit);
    }

    /** @return list<JourneyEvent> a landing page's events, newest first */
    public function forPage(int $landingPageId, int $limit = 100): array
    {
        return $this->events('landing_page_id = :id', ['id' => $landingPageId], $limit);
    }

    /** @return list<JourneyEvent> a campaign's events, newest first */
    public function forCampaign(int $campaignId, int $limit = 100): array
    {
        return $this->events('campaign_id = :id', ['id' => $campaignId], $limit);
    }

    /** @return list<JourneyEvent> a visitor's events, oldest first: their way through the site */
    public function forVisitor(int $visitorId): array
    {
        return array_reverse($this->events('visitor_id = :id', ['id' => $visitorId], 1000));
    }

    /** How many events of a kind there are, for a landing page or a campaign, since a moment. */
    public function count(string $kind, ?int $landingPageId = null, ?int $campaignId = null, ?DateTimeImmutable $since = null): int
    {
        [$where, $params] = $this->narrowed($kind, $landingPageId, $campaignId, $since);

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $where, $params);
    }

    /** @return array<int,int> each landing page's visits (landing_opened) since a moment, by its id */
    public function visitsByPage(DateTimeImmutable $since): array
    {
        $visits = [];
        foreach ($this->connection->fetchAllAssociative('SELECT landing_page_id, COUNT(*) AS visits FROM ' . self::TABLE . ' WHERE kind = :kind AND at >= :since AND landing_page_id IS NOT NULL GROUP BY landing_page_id',
            ['kind' => self::LANDING_OPENED, 'since' => Rows::sql($since)]) as $row) {
            $visits[Rows::int($row['landing_page_id'])] = Rows::int($row['visits']);
        }

        return $visits;
    }

    /** Forgets an account: its events stay, naming nobody. Gives how many did. */
    public function forgetAccount(string $account): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET account = NULL WHERE account = :account', ['account' => $account]);
    }

    /** An offer's code changed: its events follow it. */
    public function offerRenamed(string $type, string $from, string $to): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET offer_reference = :to WHERE offer_type = :type AND offer_reference = :from',
            ['type' => $type, 'from' => $from, 'to' => $to]);
    }

    /**
     * @param array<string,mixed> $params
     * @return list<JourneyEvent>
     */
    private function events(string $where, array $params, int $limit): array
    {
        return array_map(self::event(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY at DESC, id DESC LIMIT :limit',
            $params + ['limit' => max(1, $limit)]));
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function narrowed(string $kind, ?int $landingPageId, ?int $campaignId, ?DateTimeImmutable $since): array
    {
        $where = ['kind = :kind'];
        $params = ['kind' => $kind];
        if ($landingPageId !== null) {
            $where[] = 'landing_page_id = :page';
            $params['page'] = $landingPageId;
        }
        if ($campaignId !== null) {
            $where[] = 'campaign_id = :campaign';
            $params['campaign'] = $campaignId;
        }
        if ($since !== null) {
            $where[] = 'at >= :since';
            $params['since'] = Rows::sql($since);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * A detail kept small: at most twelve keys of words and numbers, each word cut to 80 characters.
     *
     * @param array<string,mixed> $detail
     * @return array<string,scalar|null>
     */
    private static function detail(array $detail): array
    {
        $kept = [];
        foreach ($detail as $key => $value) {
            if (count($kept) === self::DETAIL_KEYS) {
                break;
            }
            if (is_string($value)) {
                $kept[(string) $key] = mb_substr($value, 0, self::DETAIL_TEXT);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $kept[(string) $key] = $value;
            }
        }

        return $kept;
    }

    /** @param array<string,mixed> $row */
    private static function event(array $row): JourneyEvent
    {
        $detail = json_decode(Rows::text($row['detail']), true);
        $utc = new \DateTimeZone('UTC');

        return new JourneyEvent(Rows::int($row['id']), Rows::text($row['kind']), Rows::instant($row['at'], $utc) ?? new DateTimeImmutable(), Rows::nullableInt($row['visitor_id']),
            Rows::nullableText($row['account']), Rows::nullableInt($row['campaign_id']), Rows::nullableInt($row['publication_id']), Rows::nullableInt($row['touch_id']),
            Rows::nullableInt($row['landing_page_id']), Rows::nullableText($row['offer_type']), Rows::nullableText($row['offer_reference']),
            is_array($detail) ? array_filter($detail, static fn(mixed $value): bool => is_scalar($value) || $value === null) : [], Rows::text($row['country']), Rows::bool($row['do_not_track']),
            Rows::nullableText($row['idempotency_key']), Rows::nullableText($row['origin_site']), Rows::nullableText($row['origin_reference']), Rows::nullableInt($row['sale_id'] ?? null));
    }
}
