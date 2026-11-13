<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Mailing\Campaigns;
use CattoPHP\Mailing\CattoMail\CattoMailEvents;
use CattoPHP\Mailing\Copies;
use CattoPHP\Mailing\Forwards;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Mailing\Subscriptions;
use CattoPHP\Mailing\Suppressions;

/**
 * Mailing's part in forgetting an account, through each part's own forget(): its answers to the lists with their history
 * and what it forwarded go; its copies stay for the figures, naming nobody (no account, the forgotten address, nothing of
 * its own, links that no longer open them), catto-mail's events no longer give its address, and the lists, campaigns and
 * addresses never emailed it made or approved no longer say who did. Forwards are kept only where the project sends
 * campaigns.
 */
final class MailingRecords implements ForgetsAccounts
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly MailingLists $lists,
        private readonly Suppressions $suppressions,
        private readonly Copies $copies,
        private readonly Campaigns $campaigns,
        private readonly ?Forwards $forwards = null,
        private readonly ?CattoMailEvents $events = null,
    ) {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        return [
            'removed' => ['mailing list answers' => $this->subscriptions->forget($account), 'forwarded emails' => $this->forwards?->forget($account) ?? 0],
            'unnamed' => [
                'catto-mail events' => $email === null ? 0 : ($this->events?->forget($email) ?? 0),
                'mailing copies' => $this->copies->forget($account),
                'mailing lists' => $this->lists->forget($account),
                'campaigns' => $this->campaigns->forget($account),
                'addresses never emailed' => $this->suppressions->forget($account),
            ],
        ];
    }
}
