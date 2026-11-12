<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use CattoPHP\Access\Roles;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * API tokens: each issued to an account with a name and the scopes it may use, and optionally a lifetime. Its words are
 * shown once, when it is made; only their SHA-256 is kept (cattophp_api_tokens), so a token cannot be read back. A token
 * is accepted while it is neither revoked nor expired and its account may use the API (ApiAccounts); each use is noted
 * (when, from a hashed address, with which browser). Making and revoking one are in the activity log.
 */
final class ApiTokens
{
    /**
     * @param array<string,string> $scopes each scope the project offers => what it allows
     * @param string $prefix what a token starts with, so it can be recognised (a secret scanner, a person reading a config)
     * @param string|null $allScopes the scope that stands for every scope, when the project has one
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Roles $roles,
        private readonly array $scopes,
        private readonly string $prefix = 'cat_',
        private readonly ?string $allScopes = null,
        private readonly ?ApiAccounts $accounts = null,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /** @return array<string,string> each scope => what it allows */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function isScope(string $scope): bool
    {
        return isset($this->scopes[$scope]);
    }

    /**
     * A new token for an account. Its words are returned once and never kept.
     *
     * @param list<string> $scopes
     * @return array{token:string,public_id:string,expires_in:?int,scopes:list<string>}
     * @throws InvalidArgumentException in plain words
     */
    public function create(string $accountId, string $name, array $scopes, ?int $ttlSeconds = null, ?Actor $actor = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Enter a token name of no more than 120 characters.');
        }
        $scopes = array_values(array_unique(array_map('trim', $scopes)));
        foreach ($scopes as $scope) {
            if (!$this->isScope($scope)) {
                throw new InvalidArgumentException('Unknown API scope: ' . $scope);
            }
        }
        if ($scopes === []) {
            throw new InvalidArgumentException('At least one API scope is required.');
        }
        if ($ttlSeconds !== null && $ttlSeconds < 60) {
            throw new InvalidArgumentException('Token lifetime must be at least 60 seconds.');
        }
        $raw = $this->prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $publicId = self::uuid();
        $this->connection->executeStatement(
            'INSERT INTO cattophp_api_tokens (public_id, account_id, name, token_hash, scopes, created_by, expires_at)
             VALUES (:public_id, :account, :name, :hash, CAST(:scopes AS jsonb), :by, CASE WHEN CAST(:ttl AS integer) IS NULL THEN NULL ELSE NOW() + make_interval(secs => CAST(:ttl AS integer)) END)',
            ['public_id' => $publicId, 'account' => $accountId, 'name' => $name, 'hash' => self::hash($raw), 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'by' => $actor !== null ? $actor->id : $accountId, 'ttl' => $ttlSeconds]
        );
        $this->activity?->record('api.token_created', ['token_id' => $publicId, 'name' => $name, 'scopes' => $scopes], actor: $actor ?? new Actor($accountId, $accountId));

        return ['token' => $raw, 'public_id' => $publicId, 'expires_in' => $ttlSeconds, 'scopes' => $scopes];
    }

    /**
     * An account's tokens, newest first, without their words.
     *
     * @return list<array{public_id:string,name:string,scopes:list<string>,created_at:string,last_used_at:?string,expires_at:?string,revoked_at:?string}>
     */
    public function tokensOf(string $accountId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT public_id, name, scopes, created_at, last_used_at, expires_at, revoked_at FROM cattophp_api_tokens WHERE account_id = :account ORDER BY created_at DESC, id DESC',
            ['account' => $accountId]
        );

        return array_map(static fn (array $row): array => [
            'public_id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'scopes' => self::scopeList($row['scopes']),
            'created_at' => (string) $row['created_at'],
            'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            'revoked_at' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        ], $rows);
    }

    /** Revokes one of an account's tokens: from now on it is refused. */
    public function revoke(string $accountId, string $publicId, ?Actor $actor = null): void
    {
        $publicId = trim($publicId);
        $this->connection->executeStatement(
            'UPDATE cattophp_api_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE account_id = :account AND CAST(public_id AS text) = :public_id',
            ['account' => $accountId, 'public_id' => $publicId]
        );
        $this->activity?->record('api.token_revoked', ['token_id' => $publicId], actor: $actor ?? new Actor($accountId, $accountId));
    }

    /**
     * Who a request acts for, from its Authorization header.
     *
     * @throws ApiRefused
     */
    public function authenticateBearer(?string $header, ?string $ipHash = null, ?string $browser = null): ApiIdentity
    {
        if (preg_match('/^Bearer\s+(.+)$/i', trim((string) $header), $matches) !== 1) {
            throw new ApiRefused('A Bearer API token is required.');
        }

        return $this->authenticate(trim($matches[1]), $ipHash, $browser);
    }

    /**
     * Who a token acts for, its use noted.
     *
     * @throws ApiRefused
     */
    public function authenticate(string $raw, ?string $ipHash = null, ?string $browser = null): ApiIdentity
    {
        if ($raw === '') {
            throw new ApiRefused('The API token is missing.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, public_id, account_id, scopes FROM cattophp_api_tokens
              WHERE token_hash = :hash AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())',
            ['hash' => self::hash($raw)]
        );
        $account = $row === false ? null : ($this->accounts !== null ? $this->accounts->find((string) $row['account_id']) : ['name' => (string) $row['account_id']]);
        if ($row === false || $account === null) {
            throw new ApiRefused('The API token is invalid, expired or revoked.');
        }
        $this->connection->executeStatement(
            'UPDATE cattophp_api_tokens SET last_used_at = NOW(), last_used_ip_hash = :ip, last_used_user_agent = :browser WHERE id = :id',
            ['ip' => $ipHash, 'browser' => $browser === null ? null : mb_substr($browser, 0, 500), 'id' => (int) $row['id']]
        );
        $accountId = (string) $row['account_id'];

        return new ApiIdentity($accountId, $account, $this->roles->rolesOf($accountId), $this->roles->permissionsOf($accountId), self::scopeList($row['scopes']), (string) $row['public_id'], $this->allScopes);
    }

    /** How a token's words are kept: their SHA-256, as hex. */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** @return list<string> */
    private static function scopeList(mixed $json): array
    {
        $scopes = is_string($json) ? json_decode($json, true) : null;

        return is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
