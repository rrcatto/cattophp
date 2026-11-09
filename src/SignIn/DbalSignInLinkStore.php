<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** Sign-in links in the database (`cattophp_sign_in_links`). */
final class DbalSignInLinkStore implements SignInLinkStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(string $hash, SignInLink $link, DateTimeImmutable $at): void
    {
        $this->connection->insert('cattophp_sign_in_links', [
            'token_hash' => $hash,
            'account_id' => $link->accountId,
            'created_at' => $at->format('Y-m-d H:i:sP'),
            'expires_at' => $link->expiresAt->format('Y-m-d H:i:sP'),
            'return_path' => $link->returnPath,
        ]);
    }

    public function usable(string $hash, DateTimeImmutable $now): ?SignInLink
    {
        $row = $this->connection->fetchAssociative(
            'SELECT account_id, expires_at, return_path FROM cattophp_sign_in_links WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [$hash, $now->format('Y-m-d H:i:sP')],
        );

        return $row === false ? null : self::link($row);
    }

    public function claim(string $hash, DateTimeImmutable $now): ?SignInLink
    {
        $row = $this->connection->fetchAssociative(
            'UPDATE cattophp_sign_in_links SET used_at = :now WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now
             RETURNING account_id, expires_at, return_path',
            ['hash' => $hash, 'now' => $now->format('Y-m-d H:i:sP')],
        );

        return $row === false ? null : self::link($row);
    }

    public function forgetOld(DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_sign_in_links WHERE expires_at < ? OR used_at IS NOT NULL', [$now->format('Y-m-d H:i:sP')]);
    }

    /** @param array<string,mixed> $row */
    private static function link(array $row): SignInLink
    {
        return new SignInLink((string) $row['account_id'], new DateTimeImmutable((string) $row['expires_at']), $row['return_path'] === null ? null : (string) $row['return_path']);
    }
}
