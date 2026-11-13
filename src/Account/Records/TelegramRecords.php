<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Telegram\TelegramAccounts;
use CattoPHP\Telegram\TelegramLog;
use CattoPHP\Telegram\TelegramState;

/**
 * Telegram's part in forgetting an account: the Telegram account it connected goes; the messages sent to it, its chat or
 * its username stay in the Telegram log, to nobody named, and the bot's values it saved no longer say who saved them. Its
 * Telegram username is given as words for the activity log to forget (telegram.connected keeps it).
 */
final class TelegramRecords implements ForgetsAccounts
{
    public function __construct(
        private readonly TelegramAccounts $accounts,
        private readonly TelegramLog $log,
        private readonly TelegramState $state,
    ) {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        $connected = $this->accounts->forAccount($account);
        $username = $connected['username'] ?? null;

        return [
            'removed' => ['Telegram connections' => $this->accounts->forget($account)],
            'unnamed' => ['Telegram messages' => $this->log->forget($account, $connected['chat_id'] ?? null, $username), 'Telegram bot values' => $this->state->forget($account)],
            'words' => $username === null ? [] : [$username, '@' . $username],
        ];
    }
}
