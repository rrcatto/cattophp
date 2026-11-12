<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Mailing, part 1: what is known of the connection to catto-mail besides its secrets (one row), and each event catto-mail
 * reports to the webhook, kept once by its id with what was done about it.
 */
final class Step202611122310CattoMail extends SqlStep
{
    public function id(): string
    {
        return '202611122310-cattomail';
    }

    public function describe(): string
    {
        return 'Mailing: the connection to catto-mail and the events it reports';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_cattomail_connection (
                id smallint PRIMARY KEY CHECK (id = 1),
                state jsonb NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE cattophp_cattomail_events (
                id bigserial PRIMARY KEY,
                event_id varchar(100) NOT NULL UNIQUE,
                event_type varchar(60) NOT NULL,
                payload jsonb NOT NULL,
                received_at timestamptz NOT NULL DEFAULT now(),
                processed_at timestamptz NULL,
                outcome varchar(500) NULL
            )',
            'CREATE INDEX cattophp_cattomail_events_waiting_idx ON cattophp_cattomail_events (id) WHERE processed_at IS NULL',
        ];
    }
}
