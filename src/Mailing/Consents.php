<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Who agreed to be emailed about what. A person says yes or no to a list about a subject (the list made at the first yes:
 * a no to a list nobody has joined makes none), joins a list an administrator made while it is open, stops one list (an
 * email's unsubscribe link) or every list (a spam complaint, "unsubscribe from everything"). Each answer goes into the
 * history with where it was given (the project's word for the place, such as registration), and into the activity log as
 * mailing.subscribed, mailing.unsubscribed or mailing.unsubscribed_all, the person as the one who acted. Which kinds of
 * subject there are and which places, the project says.
 */
final class Consents
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MailingLists $lists,
        private readonly Subscriptions $subscriptions,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /** A yes to the list of a kind and subject. Returns the list's id when the answer changed, null when it was yes already. */
    public function subscribe(string $account, string $kind, int|string|null $subject, string $source): ?int
    {
        return $this->answer($account, $kind, $subject, 'subscribed', $source);
    }

    /** A no to the list of a kind and subject. Returns the list's id when the answer changed, else null. */
    public function unsubscribe(string $account, string $kind, int|string|null $subject, string $source): ?int
    {
        return $this->answer($account, $kind, $subject, 'unsubscribed', $source);
    }

    /** Joins a list an administrator made, while it is open. False when they were on it already. */
    public function joinList(string $account, int $listId, string $source): bool
    {
        $list = $this->lists->ownList($listId);
        if ($list === null || $list['closed_at'] !== null) throw new InvalidArgumentException('That list cannot be joined now.');

        return $this->connection->transactional(function () use ($account, $listId, $source): bool {
            $done = $this->subscriptions->answer($listId, $account, 'subscribed', $source);
            if ($done) $this->record('mailing.subscribed', ['list_id' => $listId, 'source' => $source], $account);

            return $done;
        });
    }

    /** Stops one list by its id, as an email's unsubscribe link does. False when they had stopped it already. */
    public function unsubscribeList(string $account, int $listId, string $source): bool
    {
        return $this->connection->transactional(function () use ($account, $listId, $source): bool {
            $done = $this->subscriptions->answer($listId, $account, 'unsubscribed', $source);
            if ($done) $this->record('mailing.unsubscribed', ['list_id' => $listId, 'source' => $source], $account);

            return $done;
        });
    }

    /** Stops every list a person is on. Returns how many were stopped. */
    public function unsubscribeAll(string $account, string $source): int
    {
        return $this->connection->transactional(function () use ($account, $source): int {
            $stopped = 0;
            foreach ($this->subscriptions->subscribedListIds($account) as $listId) {
                if ($this->subscriptions->answer($listId, $account, 'unsubscribed', $source)) $stopped++;
            }
            if ($stopped > 0) $this->record('mailing.unsubscribed_all', ['lists' => $stopped, 'source' => $source], $account);

            return $stopped;
        });
    }

    public function isSubscribedToList(string $account, int $listId): bool
    {
        return in_array($listId, $this->subscriptions->subscribedListIds($account), true);
    }

    private function answer(string $account, string $kind, int|string|null $subject, string $status, string $source): ?int
    {
        if ($kind === MailingLists::OWN) throw new InvalidArgumentException('Join a list an administrator made by its id.');

        return $this->connection->transactional(function () use ($account, $kind, $subject, $status, $source): ?int {
            $listId = $this->lists->listId($kind, $subject, $status === 'subscribed');
            if ($listId === null || !$this->subscriptions->answer($listId, $account, $status, $source)) return null;
            $this->record($status === 'subscribed' ? 'mailing.subscribed' : 'mailing.unsubscribed', ['kind' => $kind, 'subject_id' => $subject, 'source' => $source], $account);

            return $listId;
        });
    }

    /** @param array<string,mixed> $details */
    private function record(string $action, array $details, string $account): void
    {
        $this->activity?->record($action, $details, actor: $account !== '' && $account !== '0' ? new Actor($account, $account) : Actor::system());
    }
}
