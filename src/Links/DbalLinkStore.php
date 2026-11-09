<?php

declare(strict_types=1);

namespace CattoPHP\Links;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalLinkStore implements LinkStore
{
    private const COLUMNS = 'id, token_hash, purpose, email, account_id, context, requested_from, created_at, expires_at, used_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(string $hash, string $purpose, ?string $email, ?string $accountId, array $context, ?string $requestedFrom, DateTimeImmutable $at, DateTimeImmutable $expiresAt): string
    {
        return (string) $this->connection->fetchOne(
            'INSERT INTO cattophp_links (token_hash, purpose, email, account_id, context, requested_from, created_at, expires_at)
             VALUES (:hash, :purpose, :email, :account, :context, :from, :at, :expires) RETURNING id',
            ['hash' => $hash, 'purpose' => $purpose, 'email' => $email, 'account' => $accountId, 'context' => self::json($context), 'from' => $requestedFrom,
                'at' => self::time($at), 'expires' => self::time($expiresAt)],
        );
    }

    public function find(string $hash, string $purpose): ?Link
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM cattophp_links WHERE token_hash = ? AND purpose = ?', [$hash, $purpose]);
    }

    public function usable(string $hash, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM cattophp_links WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?' . ($lock ? ' FOR UPDATE' : ''),
            [$hash, $purpose, self::time($now)]);
    }

    public function usableById(string $id, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            return null;
        }

        return $this->one('SELECT ' . self::COLUMNS . ' FROM cattophp_links WHERE id = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?' . ($lock ? ' FOR UPDATE' : ''),
            [(int) $id, $purpose, self::time($now)]);
    }

    public function changeContext(string $id, array $context): void
    {
        $this->connection->executeStatement('UPDATE cattophp_links SET context = ? WHERE id = ?', [self::json($context), (int) $id]);
    }

    public function use(string $id, DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement('UPDATE cattophp_links SET used_at = ? WHERE id = ? AND used_at IS NULL', [self::time($now), (int) $id]) === 1;
    }

    public function finishOpen(string $email, string $purpose, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE cattophp_links SET used_at = ? WHERE email = ? AND purpose = ? AND used_at IS NULL', [self::time($now), $email, $purpose]);
    }

    public function forgetOpen(string $accountId, string $purpose): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_links WHERE account_id = ? AND purpose = ? AND used_at IS NULL', [$accountId, $purpose]);
    }

    public function forget(string $hash): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_links WHERE token_hash = ? AND used_at IS NULL', [$hash]);
    }

    public function countForEmail(string $email, DateTimeImmutable $since, array $except = []): int
    {
        return $this->count('email', $email, $since, $except);
    }

    public function countFrom(string $requestedFrom, DateTimeImmutable $since, array $except = []): int
    {
        return $this->count('requested_from', $requestedFrom, $since, $except);
    }

    public function forgetOld(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_links WHERE expires_at < :before OR used_at < :before', ['before' => self::time($before)]);
    }

    /** @param list<string> $except */
    private function count(string $column, string $value, DateTimeImmutable $since, array $except): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cattophp_links WHERE ' . $column . ' = :value AND created_at > :since' . ($except === [] ? '' : ' AND purpose NOT IN (:except)'),
            ['value' => $value, 'since' => self::time($since), 'except' => $except],
            ['except' => ArrayParameterType::STRING],
        );
    }

    /** @param list<mixed> $params */
    private function one(string $sql, array $params): ?Link
    {
        $row = $this->connection->fetchAssociative($sql, $params);
        if ($row === false) {
            return null;
        }
        $context = json_decode((string) $row['context'], true);

        return new Link(
            (string) $row['id'],
            (string) $row['token_hash'],
            (string) $row['purpose'],
            $row['email'] === null ? null : (string) $row['email'],
            $row['account_id'] === null ? null : (string) $row['account_id'],
            is_array($context) ? self::keyed($context) : [],
            $row['requested_from'] === null ? null : (string) $row['requested_from'],
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            $row['used_at'] === null ? null : new DateTimeImmutable((string) $row['used_at']),
        );
    }

    /**
     * @param array<mixed> $values
     * @return array<string,mixed>
     */
    private static function keyed(array $values): array
    {
        $keyed = [];
        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /** @param array<string,mixed> $context */
    private static function json(array $context): string
    {
        return json_encode($context === [] ? new \stdClass() : $context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }
}
