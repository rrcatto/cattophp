<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Mailing, part 3: campaigns (what a project sends to its lists: its kind, the words of its subject and preview, what it
 * says in the project's own form, its lists, its status and when it goes), each person's copy (its token, what became of
 * it, opens and clicks), the links in a campaign and who clicked them. A person is their account id in text. An address
 * never emailed forgets the copy that caused it when that copy is deleted.
 */
final class Step202611122330MailingCampaigns extends SqlStep
{
    public function id(): string
    {
        return '202611122330-mailing-campaigns';
    }

    public function describe(): string
    {
        return 'Mailing: campaigns, each person\'s copy, and the links in a campaign with who clicked them';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_mailing_campaigns (
                id bigserial PRIMARY KEY,
                kind varchar(32) NOT NULL CHECK (kind ~ \'^[a-z][a-z_]{0,31}$\'),
                name varchar(160) NOT NULL,
                subject varchar(200) NOT NULL DEFAULT \'\',
                preheader varchar(200) NOT NULL DEFAULT \'\',
                content jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                list_ids jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                exclude_list_ids jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                max_recipients integer NULL CHECK (max_recipients IS NULL OR max_recipients > 0),
                from_name varchar(120) NULL,
                reply_to varchar(320) NULL,
                plain_text text NULL,
                status varchar(20) NOT NULL DEFAULT \'draft\' CHECK (status IN (\'draft\', \'awaiting_approval\', \'scheduled\', \'sending\', \'paused\', \'sent\', \'cancelled\')),
                status_note varchar(500) NULL,
                send_at timestamptz NULL,
                created_by text NULL,
                approved_by text NULL,
                approved_at timestamptz NULL,
                started_at timestamptz NULL,
                finished_at timestamptz NULL,
                checks_overridden boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE INDEX cattophp_mailing_campaigns_status_idx ON cattophp_mailing_campaigns (status, send_at)',
            'CREATE TABLE cattophp_mailing_copies (
                id bigserial PRIMARY KEY,
                campaign_id bigint NOT NULL REFERENCES cattophp_mailing_campaigns(id) ON DELETE CASCADE,
                account text NULL,
                email varchar(320) NOT NULL,
                token char(32) NOT NULL UNIQUE,
                list_id bigint NULL REFERENCES cattophp_mailing_lists(id) ON DELETE SET NULL,
                reasons jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                content jsonb NOT NULL DEFAULT \'{}\'::jsonb,
                status varchar(16) NOT NULL DEFAULT \'queued\' CHECK (status IN (\'queued\', \'handed\', \'delivered\', \'deferred\', \'soft_bounced\', \'bounced\', \'complained\', \'failed\', \'suppressed\', \'skipped\')),
                status_detail varchar(500) NULL,
                attempts smallint NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                transport varchar(16) NULL,
                transport_job_id varchar(100) NULL,
                transport_message_id varchar(200) NULL,
                handed_at timestamptz NULL,
                checked_at timestamptz NULL,
                resolved_at timestamptz NULL,
                first_opened_at timestamptz NULL,
                last_opened_at timestamptz NULL,
                open_count integer NOT NULL DEFAULT 0 CHECK (open_count >= 0),
                first_clicked_at timestamptz NULL,
                click_count integer NOT NULL DEFAULT 0 CHECK (click_count >= 0),
                unsubscribed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (campaign_id, account)
            )',
            'CREATE INDEX cattophp_mailing_copies_queue_idx ON cattophp_mailing_copies (campaign_id, status, id)',
            'CREATE INDEX cattophp_mailing_copies_account_idx ON cattophp_mailing_copies (account, created_at DESC)',
            'CREATE INDEX cattophp_mailing_copies_job_idx ON cattophp_mailing_copies (transport_job_id) WHERE transport_job_id IS NOT NULL',
            'CREATE TABLE cattophp_mailing_links (
                id bigserial PRIMARY KEY,
                campaign_id bigint NOT NULL REFERENCES cattophp_mailing_campaigns(id) ON DELETE CASCADE,
                url text NOT NULL,
                UNIQUE (campaign_id, url)
            )',
            'CREATE TABLE cattophp_mailing_link_clicks (
                link_id bigint NOT NULL REFERENCES cattophp_mailing_links(id) ON DELETE CASCADE,
                copy_id bigint NOT NULL REFERENCES cattophp_mailing_copies(id) ON DELETE CASCADE,
                clicks integer NOT NULL DEFAULT 1 CHECK (clicks > 0),
                first_at timestamptz NOT NULL DEFAULT now(),
                last_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (link_id, copy_id)
            )',
            'CREATE FUNCTION cattophp_mailing_forget_copy() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                UPDATE cattophp_mailing_suppressions SET copy_id = NULL WHERE copy_id = OLD.id::text;
                RETURN OLD;
            END;
            $fn$',
            'CREATE TRIGGER cattophp_mailing_copies_forget AFTER DELETE ON cattophp_mailing_copies FOR EACH ROW EXECUTE FUNCTION cattophp_mailing_forget_copy()',
        ];
    }
}
