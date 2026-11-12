<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Identity verification's records: each check made on the provider's hosted page (only its status, the verified name,
 * the document's type, number and issuing country, the date of birth and when; never an image), the provider's webhook
 * events already handled, and when an administrator last allowed an account another try.
 */
final class Step202611122201IdentityChecks extends SqlStep
{
    public function id(): string
    {
        return '202611122201-identity-checks';
    }

    public function describe(): string
    {
        return 'Identity checks: each verification, the webhook events handled and the tries allowed again';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_identity_verifications (
                id bigserial PRIMARY KEY,
                account text NOT NULL,
                provider varchar(24) NOT NULL DEFAULT \'didit\' CHECK (provider IN (\'didit\')),
                session_id varchar(80) NOT NULL UNIQUE,
                status varchar(16) NOT NULL DEFAULT \'started\' CHECK (status IN (\'started\', \'in_review\', \'approved\', \'declined\', \'abandoned\', \'expired\', \'revoked\')),
                verified_name varchar(240) NULL,
                document_type varchar(80) NULL,
                document_number varchar(80) NULL,
                issuing_country varchar(80) NULL,
                date_of_birth date NULL,
                decline_reason varchar(500) NULL,
                started_at timestamptz NOT NULL DEFAULT now(),
                decided_at timestamptz NULL,
                revoked_at timestamptz NULL,
                revoked_by text NULL,
                revocation_reason varchar(500) NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT cattophp_identity_approved_has_name CHECK (status NOT IN (\'approved\', \'revoked\') OR (verified_name IS NOT NULL AND decided_at IS NOT NULL)),
                CONSTRAINT cattophp_identity_revoked_has_reason CHECK (status <> \'revoked\' OR (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL AND btrim(revocation_reason) <> \'\'))
            )',
            'CREATE INDEX cattophp_identity_verifications_account_idx ON cattophp_identity_verifications (account, started_at DESC)',
            'CREATE INDEX cattophp_identity_verifications_review_idx ON cattophp_identity_verifications (started_at) WHERE status = \'in_review\'',
            'CREATE UNIQUE INDEX cattophp_identity_verifications_one_approved ON cattophp_identity_verifications (account) WHERE status = \'approved\'',
            'CREATE TABLE cattophp_identity_webhook_events (
                event_id uuid PRIMARY KEY,
                received_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE cattophp_identity_retries (
                account text PRIMARY KEY,
                reset_at timestamptz NOT NULL DEFAULT now()
            )',
        ];
    }
}
