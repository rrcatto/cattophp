<?php

declare(strict_types=1);

namespace CattoPHP\Sessions;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final class DbalSessionStore implements SessionStore
{
    private const COLUMNS = 'id, public_id, account_id, email, requested_from, user_agent, started_at, last_seen_at, expires_at, ended_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function start(string $hash, string $publicId, string $accountId, ?string $email, ?string $requestedFrom, ?string $userAgent, DateTimeImmutable $at, DateTimeImmutable $expiresAt): SignInSession
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO cattophp_sessions (token_hash, public_id, account_id, email, requested_from, user_agent, started_at, last_seen_at, expires_at)
             VALUES (:hash, :public, :account, :email, :from, :agent, :at, :at, :expires) RETURNING id',
            ['hash' => $hash, 'public' => $publicId, 'account' => $accountId, 'email' => $email, 'from' => $requestedFrom, 'agent' => $userAgent,
                'at' => self::time($at), 'expires' => self::time($expiresAt)],
        );

        return new SignInSession($id, $publicId, $accountId, $email, $requestedFrom, $userAgent, $at, $at, $expiresAt);
    }

    public function active(string $hash, DateTimeImmutable $now): ?SignInSession
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM cattophp_sessions WHERE token_hash = ? AND ended_at IS NULL AND expires_at > ?', [$hash, self::time($now)]);

        return $row === false ? null : self::session($row);
    }

    public function touch(string $id, DateTimeImmutable $now, DateTimeImmutable $expiresAt): void
    {
        $this->connection->executeStatement('UPDATE cattophp_sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?', [self::time($now), self::time($expiresAt), (int) $id]);
    }

    public function activeFor(string $accountId, DateTimeImmutable $now): array
    {
        return array_map(self::session(...), $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM cattophp_sessions WHERE account_id = ? AND ended_at IS NULL AND expires_at > ? ORDER BY started_at DESC, id DESC',
            [$accountId, self::time($now)],
        ));
    }

    public function end(string $accountId, string $publicId, DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement('UPDATE cattophp_sessions SET ended_at = ? WHERE account_id = ? AND public_id = ? AND ended_at IS NULL',
            [self::time($now), $accountId, $publicId]) > 0;
    }

    public function endAllFor(string $accountId, DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement('UPDATE cattophp_sessions SET ended_at = ? WHERE account_id = ? AND ended_at IS NULL', [self::time($now), $accountId]);
    }

    public function endByHash(string $hash, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE cattophp_sessions SET ended_at = ? WHERE token_hash = ? AND ended_at IS NULL', [self::time($now), $hash]);
    }

    public function forgetOld(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_sessions WHERE expires_at < :before OR ended_at < :before', ['before' => self::time($before)]);
    }

    /** @param array<string,mixed> $row */
    private static function session(array $row): SignInSession
    {
        return new SignInSession((string) $row['id'], (string) $row['public_id'], (string) $row['account_id'],
            $row['email'] === null ? null : (string) $row['email'], $row['requested_from'] === null ? null : (string) $row['requested_from'],
            $row['user_agent'] === null ? null : (string) $row['user_agent'], new DateTimeImmutable((string) $row['started_at']),
            new DateTimeImmutable((string) $row['last_seen_at']), new DateTimeImmutable((string) $row['expires_at']),
            $row['ended_at'] === null ? null : new DateTimeImmutable((string) $row['ended_at']));
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }
}
