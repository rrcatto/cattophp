<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Mailing, part 2: the lists (one for each subject a project offers to follow, made when its first person follows it, and
 * the lists an administrator makes, kind 'own'), each person's answer for a list and the history of every answer, so
 * consent can always be shown, and the addresses never emailed. A person is their account id in text; what a list is
 * about is the project's subject, also in text.
 */
final class Step202611122320MailingLists extends SqlStep
{
    public function id(): string
    {
        return '202611122320-mailing-lists';
    }

    public function describe(): string
    {
        return 'Mailing: lists, subscriptions with their history, and the addresses never emailed';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_mailing_lists (
                id bigserial PRIMARY KEY,
                kind varchar(32) NOT NULL CHECK (kind ~ \'^[a-z][a-z_]{0,31}$\'),
                subject varchar(100) NULL,
                name varchar(120) NULL,
                description varchar(500) NULL,
                shown boolean NOT NULL DEFAULT true,
                join_token char(32) NULL UNIQUE CHECK (join_token IS NULL OR join_token ~ \'^[0-9a-f]{32}$\'),
                from_name varchar(120) NULL,
                reply_to varchar(320) NULL,
                closed_at timestamptz NULL,
                created_by text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CHECK (kind <> \'own\' OR (name IS NOT NULL AND join_token IS NOT NULL AND subject IS NULL))
            )',
            'CREATE UNIQUE INDEX cattophp_mailing_lists_one_per_subject ON cattophp_mailing_lists (kind, COALESCE(subject, \'\')) WHERE kind <> \'own\'',
            'CREATE TABLE cattophp_mailing_subscriptions (
                list_id bigint NOT NULL REFERENCES cattophp_mailing_lists(id) ON DELETE CASCADE,
                account text NOT NULL,
                status varchar(16) NOT NULL CHECK (status IN (\'subscribed\', \'unsubscribed\', \'pending\')),
                source varchar(32) NOT NULL,
                changed_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (list_id, account)
            )',
            'CREATE INDEX cattophp_mailing_subscriptions_account_idx ON cattophp_mailing_subscriptions (account, status)',
            'CREATE TABLE cattophp_mailing_subscription_events (
                id bigserial PRIMARY KEY,
                list_id bigint NOT NULL REFERENCES cattophp_mailing_lists(id) ON DELETE CASCADE,
                account text NOT NULL,
                status varchar(16) NOT NULL CHECK (status IN (\'subscribed\', \'unsubscribed\', \'pending\')),
                source varchar(32) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE INDEX cattophp_mailing_subscription_events_account_idx ON cattophp_mailing_subscription_events (account, created_at DESC)',
            'CREATE TABLE cattophp_mailing_suppressions (
                email varchar(320) PRIMARY KEY CHECK (email = lower(email)),
                reason varchar(16) NOT NULL CHECK (reason IN (\'hard_bounce\', \'complaint\', \'manual\')),
                detail varchar(500) NULL,
                copy_id text NULL,
                created_by text NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
        ];
    }
}
