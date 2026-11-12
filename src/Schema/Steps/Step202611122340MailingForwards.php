<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Mailing, part 3, second half: each copy a person forwarded to a friend, to whom, with their note. The friend gets one
 * copy and is put on no list; the forward's token is the friend's "Never email me" link. A person is their account id in
 * text.
 */
final class Step202611122340MailingForwards extends SqlStep
{
    public function id(): string
    {
        return '202611122340-mailing-forwards';
    }

    public function describe(): string
    {
        return 'Mailing: the copies people forwarded to a friend';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_mailing_forwards (
                id bigserial PRIMARY KEY,
                copy_id bigint NOT NULL REFERENCES cattophp_mailing_copies(id) ON DELETE CASCADE,
                account text NOT NULL,
                to_email varchar(320) NOT NULL,
                to_name varchar(120) NOT NULL DEFAULT \'\',
                note varchar(500) NOT NULL DEFAULT \'\',
                token char(32) NOT NULL UNIQUE CHECK (token ~ \'^[0-9a-f]{32}$\'),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (copy_id, to_email)
            )',
            'CREATE INDEX cattophp_mailing_forwards_account_idx ON cattophp_mailing_forwards (account, created_at DESC)',
        ];
    }
}
