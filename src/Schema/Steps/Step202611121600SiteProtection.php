<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Site protection's records (Protection\): the Site Log (each request: the address and the /64 the rules count, who,
 * the browser, the country and network, the page with its secrets masked, the answer, where the visitor came from, the
 * form and what the form guard found), the flagged addresses, what is kept off the site (an address, a range or a
 * network owner, a time-out or a lockout) and the email addresses and domains refused. A person is their account id in
 * text, as the activity log names them; an application's own tables in the same form move in as they stand.
 */
final class Step202611121600SiteProtection extends SqlStep
{
    public function id(): string
    {
        return '202611121600-site-protection';
    }

    public function describe(): string
    {
        return 'Site protection: the Site Log, flags, lockouts and refused emails';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_site_requests (
                id bigserial PRIMARY KEY,
                at timestamptz NOT NULL DEFAULT now(),
                address inet NOT NULL,
                subject inet NOT NULL,
                account_id text NULL,
                by_administrator boolean NOT NULL DEFAULT false,
                visitor varchar(12) NOT NULL CHECK (visitor IN (\'signed_in\', \'person\', \'automated\')),
                agent_kind varchar(12) NOT NULL CHECK (agent_kind IN (\'browser\', \'outdated\', \'script\', \'search\', \'crawler\', \'none\')),
                agent_label varchar(80) NOT NULL DEFAULT \'\',
                agent_proved boolean NULL,
                device varchar(10) NOT NULL DEFAULT \'\' CHECK (device IN (\'\', \'phone\', \'tablet\', \'computer\')),
                user_agent varchar(500) NOT NULL DEFAULT \'\',
                country_code char(2) NULL CHECK (country_code ~ \'^[A-Z]{2}$\'),
                network_kind varchar(10) NOT NULL DEFAULT \'\' CHECK (network_kind IN (\'\', \'home\', \'hosting\', \'vpn\', \'tor\', \'private\')),
                network_asn bigint NULL,
                network_name varchar(200) NOT NULL DEFAULT \'\',
                method varchar(10) NOT NULL,
                path varchar(500) NOT NULL,
                status smallint NOT NULL,
                is_page boolean NOT NULL DEFAULT false,
                refused boolean NOT NULL DEFAULT false,
                came_from varchar(200) NOT NULL DEFAULT \'\',
                source varchar(10) NOT NULL DEFAULT \'direct\' CHECK (source IN (\'inside\', \'direct\', \'search\', \'social\', \'email\', \'site\', \'share\')),
                form varchar(32) NULL CHECK (form ~ \'^[a-z][a-z0-9_]{0,31}$\'),
                form_signal varchar(16) NULL CHECK (form_signal IN (\'trap\', \'suspect_network\', \'no_browser\', \'outdated\', \'script\', \'email_list\')),
                form_refused boolean NOT NULL DEFAULT false,
                form_email varchar(254) NULL,
                form_email_hash char(64) NULL
            )',
            'CREATE INDEX cattophp_site_requests_at_idx ON cattophp_site_requests (at DESC)',
            'CREATE INDEX cattophp_site_requests_subject_idx ON cattophp_site_requests (subject, at DESC)',
            'CREATE INDEX cattophp_site_requests_account_idx ON cattophp_site_requests (account_id, at DESC) WHERE account_id IS NOT NULL',
            'CREATE INDEX cattophp_site_requests_form_idx ON cattophp_site_requests (at DESC) WHERE form_refused',
            'CREATE TABLE cattophp_site_flags (
                id bigserial PRIMARY KEY,
                subject inet NOT NULL UNIQUE,
                level varchar(10) NOT NULL CHECK (level IN (\'medium\', \'high\')),
                fine boolean NOT NULL DEFAULT false,
                why jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                first_flagged_at timestamptz NOT NULL DEFAULT now(),
                last_flagged_at timestamptz NOT NULL DEFAULT now(),
                decided_by text NULL,
                decided_at timestamptz NULL
            )',
            'CREATE INDEX cattophp_site_flags_last_idx ON cattophp_site_flags (last_flagged_at DESC)',
            'CREATE TABLE cattophp_site_lockouts (
                id bigserial PRIMARY KEY,
                kind varchar(10) NOT NULL CHECK (kind IN (\'address\', \'range\', \'owner\')),
                network cidr NULL,
                asn bigint NULL,
                label varchar(200) NOT NULL,
                which varchar(10) NOT NULL CHECK (which IN (\'timeout\', \'lockout\')),
                why varchar(500) NOT NULL,
                rule_key varchar(20) NULL,
                automatic boolean NOT NULL DEFAULT false,
                created_by text NULL,
                started_at timestamptz NOT NULL DEFAULT now(),
                until_at timestamptz NULL,
                lifted_at timestamptz NULL,
                lifted_by text NULL,
                refused bigint NOT NULL DEFAULT 0,
                last_refused_at timestamptz NULL,
                logged_day date NULL,
                logged_on_day integer NOT NULL DEFAULT 0,
                firewall varchar(10) NOT NULL DEFAULT \'no\' CHECK (firewall IN (\'no\', \'banned\', \'released\')),
                CHECK ((kind = \'owner\') = (asn IS NOT NULL)),
                CHECK ((kind = \'owner\') = (network IS NULL))
            )',
            'CREATE INDEX cattophp_site_lockouts_force_idx ON cattophp_site_lockouts (until_at) WHERE lifted_at IS NULL',
            'CREATE INDEX cattophp_site_lockouts_network_idx ON cattophp_site_lockouts (network, started_at DESC)',
            'CREATE TABLE cattophp_site_refused_emails (
                id bigserial PRIMARY KEY,
                value varchar(254) NOT NULL UNIQUE CHECK (value = lower(value)),
                why varchar(500) NOT NULL DEFAULT \'\',
                refused bigint NOT NULL DEFAULT 0,
                created_by text NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
        ];
    }
}
