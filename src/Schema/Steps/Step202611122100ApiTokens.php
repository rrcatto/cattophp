<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * The API module's records (Api\ApiTokens): each token issued to an account, its words kept only as their SHA-256, with
 * its scopes, lifetime, revocation and last use. An account is its id in text.
 */
final class Step202611122100ApiTokens extends SqlStep
{
    public function id(): string
    {
        return '202611122100-api-tokens';
    }

    public function describe(): string
    {
        return 'API tokens: each issued to an account, with its scopes';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_api_tokens (
                id bigserial PRIMARY KEY,
                public_id uuid NOT NULL UNIQUE,
                account_id text NOT NULL,
                name text NOT NULL CHECK (btrim(name) <> \'\'),
                token_hash char(64) NOT NULL UNIQUE,
                scopes jsonb NOT NULL DEFAULT \'[]\'::jsonb CHECK (jsonb_typeof(scopes) = \'array\'),
                created_by text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                last_used_at timestamptz NULL,
                last_used_ip_hash char(64) NULL,
                last_used_user_agent text NULL,
                expires_at timestamptz NULL,
                revoked_at timestamptz NULL
            )',
            'CREATE INDEX cattophp_api_tokens_account_idx ON cattophp_api_tokens (account_id, created_at DESC)',
        ];
    }
}
