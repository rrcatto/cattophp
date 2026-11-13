<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Inbox\InboxStore;

/**
 * The Inbox's part in forgetting an account: the messages it sent (from its account or its address) stay, naming nobody:
 * no name, no address but AccountRecords::FORGOTTEN_EMAIL, no network address; what it answered, wrote, taught the spam
 * filter and blocked stays too, no longer saying who did it.
 */
final class InboxRecords implements ForgetsAccounts
{
    public function __construct(private readonly InboxStore $inbox)
    {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        $forgotten = $this->inbox->forgetAccount($account, $email);

        return ['removed' => [], 'unnamed' => [
            'Inbox messages sent' => $forgotten['conversations'],
            'Inbox messages answered' => $forgotten['answered'],
            'Inbox replies and drafts' => $forgotten['written'],
            'spam filter lessons' => $forgotten['trained'],
            'blocked senders' => $forgotten['blocked'],
        ]];
    }
}
