<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * The Telegram module's records (Telegram\): its few values (the bot's connection, the reader's place, Telegram's address
 * when a check stands in), the Telegram log (the owner's messages waiting and sent, and each message to a person, as it
 * went) and the Telegram accounts people connected. A person is their account id in text; an application's own records in
 * the same form move in as they stand.
 */
final class Step202611121800Telegram extends SqlStep
{
    public function id(): string
    {
        return '202611121800-telegram';
    }

    public function describe(): string
    {
        return 'Telegram: the bot, the Telegram log and connected accounts';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_telegram_state (
                name text PRIMARY KEY CHECK (name IN (\'bot\', \'inbox\', \'address\')),
                value text NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                updated_by text NULL
            )',
            'CREATE TABLE cattophp_telegram_messages (
                id bigserial PRIMARY KEY,
                reference text NOT NULL UNIQUE,
                kind text NOT NULL CHECK (kind ~ \'^[a-z][a-z_]{0,31}$\'),
                body text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                sent_at timestamptz NULL,
                tries integer NOT NULL DEFAULT 0 CHECK (tries >= 0),
                last_tried_at timestamptz NULL,
                last_error text NULL,
                chat_id text NULL,
                to_account text NULL,
                to_username text NULL
            )',
            'CREATE INDEX cattophp_telegram_messages_waiting_idx ON cattophp_telegram_messages (created_at) WHERE sent_at IS NULL',
            'CREATE TABLE cattophp_telegram_accounts (
                account_id text PRIMARY KEY,
                chat_id text NOT NULL UNIQUE CHECK (chat_id ~ \'^-?[0-9]{1,20}$\'),
                username text NULL CHECK (username IS NULL OR username ~ \'^[A-Za-z0-9_]{4,32}$\'),
                connected_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE UNIQUE INDEX cattophp_telegram_accounts_username_idx ON cattophp_telegram_accounts (lower(username)) WHERE username IS NOT NULL',
        ];
    }
}
