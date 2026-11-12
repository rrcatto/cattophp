<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/** The sign-up check's records: every registration attempt with what was found and its outcome. */
final class Step202611122200SignupAttempts extends SqlStep
{
    public function id(): string
    {
        return '202611122200-signup-attempts';
    }

    public function describe(): string
    {
        return 'Sign-up attempts: each registration with its address, network, browser, score and outcome';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_signup_attempts (
                id bigserial PRIMARY KEY,
                public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                created_at timestamptz NOT NULL DEFAULT now(),
                email varchar(254) NOT NULL,
                first_name varchar(120) NOT NULL DEFAULT \'\',
                last_name varchar(120) NOT NULL DEFAULT \'\',
                details jsonb NOT NULL DEFAULT \'{}\'::jsonb CHECK (jsonb_typeof(details) = \'object\'),
                ip_address inet NULL,
                country_code char(2) NULL CHECK (country_code ~ \'^[A-Z]{2}$\'),
                home_country char(2) NULL CHECK (home_country ~ \'^[A-Z]{2}$\'),
                network_asn bigint NULL,
                network_name varchar(200) NOT NULL DEFAULT \'\',
                user_agent varchar(500) NOT NULL DEFAULT \'\',
                browser_time_zone varchar(64) NOT NULL DEFAULT \'\',
                form_seconds integer NULL CHECK (form_seconds >= 0),
                score smallint NOT NULL CHECK (score BETWEEN 0 AND 100),
                band varchar(10) NOT NULL CHECK (band IN (\'low\', \'medium\', \'high\')),
                signals jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                hold_reasons jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                outcome varchar(20) NOT NULL CHECK (outcome IN (\'link_sent\', \'refused\', \'trapped\', \'completed\')),
                refusal varchar(300) NULL,
                held boolean NULL,
                account text NULL,
                completed_at timestamptz NULL
            )',
            'CREATE INDEX cattophp_signup_attempts_created_idx ON cattophp_signup_attempts (created_at DESC)',
            'CREATE INDEX cattophp_signup_attempts_ip_idx ON cattophp_signup_attempts (ip_address, created_at DESC)',
            'CREATE INDEX cattophp_signup_attempts_account_idx ON cattophp_signup_attempts (account) WHERE account IS NOT NULL',
        ];
    }
}
