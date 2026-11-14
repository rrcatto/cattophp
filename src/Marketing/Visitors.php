<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The visitors who came by a tracking link. A person arriving by one is given a visitor and a random token their browser
 * keeps in a first-party cookie; arriving again with it they keep their visitor (a repeat visit). Each has a reference,
 * its address on the Visitors pages and its name until it is known, never the token. A visitor's account is known once
 * they register or sign in, and stays the first account it was tied to; one person may have several visitors (a phone
 * and a computer). When the account is deleted the visitor loses it, and after the history setting, or when a person's
 * journey is forgotten, a visitor is made anonymous (token and account gone), its touches and the totals kept.
 */
final class Visitors
{
    public const TABLE = 'cattophp_marketing_visitors';

    /** The cookie's name: it holds a visitor's random token and nothing else. */
    public const COOKIE = 'cattophp_visitor';

    public const TOKEN = '/^[0-9a-f]{32}$/';

    /** A visitor's reference: eight of the readable letters and digits references are made of. */
    public const REFERENCE = '/^[a-z0-9]{8,10}$/';

    /** The subject of a visitor's entries in the activity log, its id being the visitor's reference. */
    public const SUBJECT = 'marketing_visitor';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** The visitor a browser's token names, unless it was made anonymous. */
    public function byToken(string $token): ?Visitor
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE token = :token AND anonymised_at IS NULL', ['token' => $token]);

        return $row === false ? null : self::visitor($row);
    }

    public function byId(int $id): ?Visitor
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : self::visitor($row);
    }

    /** The visitor a reference names, made anonymous or not (an anonymous one names nobody: isAnonymous() says so). */
    public function byReference(string $reference): ?Visitor
    {
        $reference = strtolower(trim($reference));
        if (preg_match(self::REFERENCE, $reference) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE reference = :reference', ['reference' => $reference]);

        return $row === false ? null : self::visitor($row);
    }

    /** @return list<Visitor> every visitor tied to an account (one person's phone, computer...), the first seen first */
    public function ofAccount(string $account): array
    {
        return array_map(self::visitor(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE account = :account ORDER BY first_seen_at, id', ['account' => $account]));
    }

    /**
     * A person arriving by a tracking link: the visitor their token names, seen again now, or a new visitor with a new
     * token, and whether they are new.
     *
     * @return array{0:Visitor,1:bool}
     */
    public function arrive(?string $token, string $country, string $device, DateTimeImmutable $now): array
    {
        $known = $token === null ? null : $this->byToken($token);
        if ($known !== null) {
            $this->connection->executeStatement(
                "UPDATE " . self::TABLE . " SET last_seen_at = :now, country = CASE WHEN :country <> '' THEN :country ELSE country END,
                    device = CASE WHEN :device <> 'unknown' THEN :device ELSE device END WHERE id = :id",
                ['now' => Rows::sql($now), 'country' => $country, 'device' => $device, 'id' => $known->id],
            );

            return [$this->byId($known->id) ?? $known, false];
        }
        // A new reference until one is free: ON CONFLICT leaves a caller's transaction usable, as a refused insert would not.
        do {
            $id = $this->connection->fetchOne(
                'INSERT INTO ' . self::TABLE . ' (token, reference, first_seen_at, last_seen_at, country, device) VALUES (:token, :reference, :now, :now, :country, :device)
                 ON CONFLICT (reference) DO NOTHING RETURNING id',
                ['token' => bin2hex(random_bytes(16)), 'reference' => Rows::reference(), 'now' => Rows::sql($now), 'country' => $country,
                    'device' => array_key_exists($device, Visitor::DEVICES) ? $device : 'unknown'],
            );
        } while ($id === false);

        return [$this->byId((int) $id) ?? throw new \RuntimeException('A visitor could not be read back.'), true];
    }

    /**
     * Ties a visitor to the account they registered or signed in as, only while it has none: a browser two people use
     * keeps the first person it was tied to. Gives whether it was tied now.
     */
    public function identify(int $visitorId, string $account): bool
    {
        return $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET account = :account WHERE id = :id AND account IS NULL AND anonymised_at IS NULL',
            ['account' => $account, 'id' => $visitorId]) > 0;
    }

    /** A visitor seen again (on any page, not only by a tracking link): its last_seen_at moved on, never back. */
    public function seen(int $visitorId, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET last_seen_at = GREATEST(last_seen_at, :now) WHERE id = :id AND anonymised_at IS NULL',
            ['now' => Rows::sql($now), 'id' => $visitorId]);
    }

    /**
     * Makes one person's visitors anonymous: the visitor given and, when it has an account, every visitor tied to that
     * account (token and account gone, its touches and the totals kept). Gives the ids made anonymous now, so the caller
     * can unname what else they hold.
     *
     * @return list<int>
     */
    public function anonymise(Visitor $visitor, DateTimeImmutable $now): array
    {
        $account = $this->byId($visitor->id)->account ?? $visitor->account;
        $ids = $this->connection->fetchFirstColumn(
            'UPDATE ' . self::TABLE . ' SET token = NULL, account = NULL, anonymised_at = :now WHERE anonymised_at IS NULL AND (id = :id' . ($account === null ? '' : ' OR account = :account') . ') RETURNING id',
            ['now' => Rows::sql($now), 'id' => $visitor->id] + ($account === null ? [] : ['account' => $account]),
        );
        $ids = array_map(Rows::int(...), $ids);
        sort($ids);

        return $ids;
    }

    /** How many visitors there are, made anonymous or not. */
    public function count(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** Forgets an account: its visitors no longer name it. Gives how many did. */
    public function forgetAccount(string $account): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET account = NULL WHERE account = :account', ['account' => $account]);
    }

    /** Makes anonymous every visitor last seen before a moment: token and account gone, its touches kept. Gives how many. */
    public function anonymiseBefore(DateTimeImmutable $before, DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET token = NULL, account = NULL, anonymised_at = :now WHERE anonymised_at IS NULL AND last_seen_at < :before',
            ['now' => Rows::sql($now), 'before' => Rows::sql($before)],
        );
    }

    /** @param array<string,mixed> $row */
    private static function visitor(array $row): Visitor
    {
        $utc = new \DateTimeZone('UTC');

        return new Visitor(Rows::int($row['id']), Rows::nullableText($row['token']), Rows::instant($row['first_seen_at'], $utc) ?? new DateTimeImmutable(),
            Rows::instant($row['last_seen_at'], $utc) ?? new DateTimeImmutable(), Rows::nullableText($row['account']), Rows::text($row['country']), Rows::text($row['device']),
            Rows::instant($row['anonymised_at'], $utc), Rows::text($row['reference'] ?? ''));
    }
}
