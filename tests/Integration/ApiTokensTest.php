<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Api\ApiAccess;
use CattoPHP\Api\ApiAccessDenied;
use CattoPHP\Api\ApiAccounts;
use CattoPHP\Api\ApiRefused;
use CattoPHP\Api\ApiTokens;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;

/** API tokens issued, listed, revoked and accepted, ported from cattolms's API firewall tests. */
final class ApiTokensTest extends DatabaseTestCase
{
    private Foundations $site;
    private ApiTokens $tokens;
    /** @var array<int|string,array{name:string,public_id:string}> the accounts that may use the API, by account id */
    private array $active = ['7' => ['name' => 'ada@example.com', 'public_id' => 'u-7'], '8' => ['name' => 'ben@example.com', 'public_id' => 'u-8']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations();
        $accounts = new class ($this) implements ApiAccounts {
            public function __construct(private readonly ApiTokensTest $test)
            {
            }

            /** @return array<string,mixed>|null */
            public function find(string $accountId): ?array
            {
                return $this->test->account($accountId);
            }
        };
        $this->tokens = new ApiTokens($this->connection, $this->site->roles, ['profile:read' => 'Read the profile.', 'courses:read' => 'Read the catalogue.', 'system:admin' => 'Everything.'],
            'example_', 'system:admin', $accounts, $this->site->activity);
    }

    /** @return array{name:string,public_id:string}|null */
    public function account(string $accountId): ?array
    {
        return $this->active[$accountId] ?? null;
    }

    public function testATokenIsShownOnceKeptAsItsHashAndActsForItsAccount(): void
    {
        $this->site->roles->grant('7', 'ADMIN');
        $made = $this->tokens->create('7', ' Reporting ', ['profile:read', 'profile:read'], null, new Actor('7', 'Ada'));
        self::assertStringStartsWith('example_', $made['token']);
        self::assertSame(['profile:read'], $made['scopes'], 'Each scope once.');
        self::assertNull($made['expires_in']);
        self::assertSame(hash('sha256', $made['token']), $this->connection->fetchOne('SELECT token_hash FROM cattophp_api_tokens'), 'Only the words\' SHA-256 is kept.');
        self::assertSame('Reporting', $this->connection->fetchOne('SELECT name FROM cattophp_api_tokens'));

        $identity = $this->tokens->authenticateBearer('Bearer ' . $made['token'], str_repeat('a', 64), 'ExampleClient/1.0');
        self::assertSame('7', $identity->accountId);
        self::assertSame('ada@example.com', $identity->name());
        self::assertSame('u-7', $identity->account['public_id'], 'What the project reports of the account.');
        self::assertSame(['ADMIN'], $identity->roles);
        self::assertTrue($identity->hasPermission('PLATFORM.SETTINGS.VIEW'), 'An administrator has every permission, through the token as on the site.');
        self::assertTrue($identity->hasScope('profile:read'));
        self::assertFalse($identity->hasScope('courses:read'), 'Only the scopes it was issued for.');
        self::assertSame($made['public_id'], $identity->tokenPublicId);
        $used = $this->connection->fetchAssociative('SELECT last_used_at, last_used_ip_hash, last_used_user_agent FROM cattophp_api_tokens');
        self::assertNotFalse($used);
        self::assertNotNull($used['last_used_at']);
        self::assertSame([str_repeat('a', 64), 'ExampleClient/1.0'], [$used['last_used_ip_hash'], $used['last_used_user_agent']], 'Each use is noted.');
        self::assertSame(['api.token_created'], array_map(static fn ($entry): string => $entry->action, $this->site->activityStore->recent(5, 'api.token_created')));

        $listed = $this->tokens->tokensOf('7');
        self::assertCount(1, $listed);
        self::assertSame(['public_id', 'name', 'scopes', 'created_at', 'last_used_at', 'expires_at', 'revoked_at'], array_keys($listed[0]));
        self::assertSame([], $this->tokens->tokensOf('8'));
    }

    public function testATokenIsRefusedInTheWordsClientsHaveAlwaysBeenShown(): void
    {
        $made = $this->tokens->create('8', 'Client', ['courses:read']);
        foreach ([[null, 'A Bearer API token is required.'], ['Basic abc', 'A Bearer API token is required.'], ['Bearer example_nothing', 'The API token is invalid, expired or revoked.']] as [$header, $words]) {
            try {
                $this->tokens->authenticateBearer($header);
                self::fail('Refused: ' . var_export($header, true));
            } catch (ApiRefused $refused) {
                self::assertSame($words, $refused->getMessage());
            }
        }
        $this->expectExceptionMessage('The API token is missing.');
        try {
            $this->tokens->authenticate('');
        } finally {
            self::assertSame('8', $this->tokens->authenticate($made['token'])->accountId);
        }
    }

    public function testARevokedExpiredOrRefusedAccountsTokenIsNoLongerAccepted(): void
    {
        $revoked = $this->tokens->create('8', 'Revoked', ['courses:read']);
        $this->tokens->revoke('7', $revoked['public_id']);
        self::assertSame('8', $this->tokens->authenticate($revoked['token'])->accountId, 'Another account cannot revoke it.');
        $this->tokens->revoke('8', $revoked['public_id'], new Actor('8', 'Ben'));
        $expired = $this->tokens->create('8', 'Short', ['courses:read'], 60);
        $this->connection->executeStatement("UPDATE cattophp_api_tokens SET expires_at = NOW() - INTERVAL '1 second' WHERE CAST(public_id AS text) = :id", ['id' => $expired['public_id']]);
        $left = $this->tokens->create('8', 'Left', ['courses:read']);
        unset($this->active[8]);
        foreach (['revoked' => $revoked['token'], 'expired' => $expired['token'], 'account refused' => $left['token']] as $case => $token) {
            try {
                $this->tokens->authenticate($token);
                self::fail('Refused: ' . $case);
            } catch (ApiRefused $refused) {
                self::assertSame('The API token is invalid, expired or revoked.', $refused->getMessage(), $case);
            }
        }
        $byId = array_column($this->tokens->tokensOf('8'), 'revoked_at', 'public_id');
        self::assertNotNull($byId[$revoked['public_id']], 'A revoked token is listed as revoked.');
        self::assertNull($byId[$left['public_id']]);
        self::assertCount(1, $this->site->activityStore->recent(5, 'api.token_revoked', actorId: '8'), 'Revoking is in the activity log.');
    }

    public function testAMadeTokenIsCheckedFirst(): void
    {
        foreach ([['', ['courses:read'], null, 'Enter a token name'], [str_repeat('n', 121), ['courses:read'], null, 'Enter a token name'], ['A', ['grades:write'], null, 'Unknown API scope: grades:write'],
            ['A', [], null, 'At least one API scope is required.'], ['A', ['courses:read'], 59, 'Token lifetime must be at least 60 seconds.']] as [$name, $scopes, $ttl, $words]) {
            try {
                $this->tokens->create('7', $name, $scopes, $ttl);
                self::fail('Refused: ' . $words);
            } catch (InvalidArgumentException $refused) {
                self::assertStringStartsWith($words, $refused->getMessage());
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_api_tokens'));
        self::assertSame(['profile:read', 'courses:read', 'system:admin'], array_keys($this->tokens->scopes()));
    }

    public function testAccessAsksForTheScopeAndThePermission(): void
    {
        $made = $this->tokens->create('8', 'Everything', ['system:admin']);
        $identity = $this->tokens->authenticate($made['token']);
        self::assertTrue($identity->hasScope('courses:read'), 'The scope that stands for every scope.');
        $access = new ApiAccess();
        $access->requireScope($identity, 'profile:read');
        try {
            $access->requireBusinessAccess($identity, 'profile:read', 'PLATFORM.SETTINGS.VIEW');
            self::fail('An account without the permission is refused, whatever the token.');
        } catch (ApiAccessDenied $denied) {
            self::assertSame('The authenticated account does not have the required permission.', $denied->getMessage());
        }
        $narrow = $this->tokens->authenticate($this->tokens->create('8', 'Narrow', ['courses:read'])['token']);
        $this->expectExceptionMessage('The API token does not grant the required scope.');
        $access->requireScope($narrow, 'profile:read');
    }

    public function testATokenAnApplicationStoredIsAccepted(): void
    {
        // As cattolms's upgrade migration moves its tokens in: the words' SHA-256 as hex, scopes as JSON, the id kept.
        $this->connection->executeStatement("INSERT INTO cattophp_api_tokens (id, public_id, account_id, name, token_hash, scopes, created_at) VALUES (40, '11111111-2222-4333-8444-555555555555', '7', 'Old', :hash, '[\"profile:read\"]', NOW() - INTERVAL '30 days')",
            ['hash' => hash('sha256', 'clpat_an-old-token')]);
        $identity = $this->tokens->authenticate('clpat_an-old-token');
        self::assertSame(['7', '11111111-2222-4333-8444-555555555555', ['profile:read']], [$identity->accountId, $identity->tokenPublicId, $identity->scopes]);
    }
}
