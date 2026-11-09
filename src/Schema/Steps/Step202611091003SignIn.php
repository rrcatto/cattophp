<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Sign-in links (each kept only as a hash, used once) and two-step sign-in (the key encrypted, the last step used, and
 * the recovery codes as keyed hashes).
 */
final class Step202611091003SignIn extends SqlStep
{
    public function id(): string
    {
        return '202611091003-sign-in';
    }

    public function describe(): string
    {
        return 'Sign-in links and two-step sign-in';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_sign_in_links (
                token_hash text PRIMARY KEY,
                account_id text NOT NULL,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                used_at timestamptz NULL,
                return_path text NULL
            )',
            'CREATE INDEX cattophp_sign_in_links_account ON cattophp_sign_in_links (account_id)',
            'CREATE INDEX cattophp_sign_in_links_expires ON cattophp_sign_in_links (expires_at)',
            'CREATE TABLE cattophp_two_step (
                account_id text PRIMARY KEY,
                secret_encrypted text NOT NULL,
                last_counter bigint NOT NULL,
                recovery_hashes jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                set_up_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )',
        ];
    }
}
