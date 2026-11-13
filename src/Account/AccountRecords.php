<?php

declare(strict_types=1);

namespace CattoPHP\Account;

use CattoPHP\Access\AccessRefused;
use CattoPHP\Access\Roles;
use CattoPHP\Access\RoleStore;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\ActivityStore;
use CattoPHP\Activity\Actor;
use CattoPHP\Installation\Installation;
use CattoPHP\Links\LinkStore;
use CattoPHP\Security\TwoStep\TwoStepStore;
use CattoPHP\Sessions\SessionStore;
use CattoPHP\Settings\SettingsStore;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInAccounts;
use Closure;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * What CattoPHP keeps of an account, forgotten when the project deletes the account: the one call a project makes then,
 * `forget($account, $by)`, inside the transaction that deletes the account's own row and before that row goes (so its
 * address is still known, and nothing can name the account again between the two). A transaction already open is joined.
 *
 * Its roles, two-step key and recovery codes, links (and those made for its address before it had an account), sign-ins
 * and anything else that is the account's alone go. What stays, because it says what happened or is someone else's
 * too, stops naming it: the activity log keeps every entry, by "A deleted account" where it did something and about
 * nobody where it was the subject, and anything in what an entry says that names it (its id, its address and name as the
 * project names it now, and what the modules and the project's own services know it by: a Telegram username, an identity
 * check's session, an earlier address) is said so too, those entries keeping no network address; a setting it saved no
 * longer says who saved it; an address kept in a module's records becomes FORGOTTEN_EMAIL, and a name or username there
 * is left empty. Each module switched on forgets its own records the same way (`ForgetsAccounts`), as does any of the
 * project's services that implements it. Where the records are in the database it is one transaction: all of it, or
 * nothing.
 *
 * Without a database the files are changed one after the other, each written whole or not at all, the two-step key last:
 * a failure part of the way leaves the account able to sign in as before, and forget() run again carries on where it
 * stopped.
 *
 * It refuses, as taking the Administrator role away does, to forget the last administrator, or an administrator's own
 * account at their own hand. An administrator is one by the Administrator role or by the project's own flag
 * (SignInAccount::administrator, as SignIn\SignInRules counts them), among the role's holders the project still finds and
 * the account of the installation's administrator's address (APP_ADMIN_EMAIL); the role's holders are locked until the
 * transaction ends, so two administrators forgetting each other at once leave one. The forgetting itself is in the
 * activity log, naming nobody it forgot.
 *
 * `$lastAdministrator` lets the last administrator go too, for an account made only to be removed again: a check's own
 * administrator on a database that had none before it, which leaves the site as it found it. Never for a person's.
 */
final class AccountRecords
{
    /** Who an account is in the records that stay once it is forgotten. */
    public const FORGOTTEN = 'A deleted account';

    /** The address a record that must keep one (a mailing copy, a message in the Inbox) is left with once it is forgotten. */
    public const FORGOTTEN_EMAIL = 'deleted-account@forgotten.invalid';

    /**
     * @param iterable<ForgetsAccounts> $keepers
     * @param SignInAccounts|(Closure(): SignInAccounts)|null $accounts the project's accounts, or a closure giving them, so a
     *     project's own accounts service may take this to delete an account without the two needing each other at once
     * @param Installation|null $installation the installation, whose administrator's address (APP_ADMIN_EMAIL) is counted
     *     among the administrators
     */
    public function __construct(
        private readonly Roles $roles,
        private readonly RoleStore $roleStore,
        private readonly TwoStepStore $twoStep,
        private readonly LinkStore $links,
        private readonly SessionStore $sessions,
        private readonly SettingsStore $settings,
        private readonly ActivityStore $activityStore,
        private readonly ActivityLog $activity,
        private readonly iterable $keepers = [],
        private readonly ?Connection $connection = null,
        private readonly SignInAccounts|Closure|null $accounts = null,
        private readonly ?Installation $installation = null,
    ) {
    }

    /**
     * Forgets an account the project is deleting.
     *
     * @param Actor|null $by who is deleting it; null is the signed-in person, or the system when nobody is signed in
     * @param bool $lastAdministrator true lets the last administrator go too: only for an account made to be removed again
     * @return array{removed: array<string,int>, unnamed: array<string,int>} how many records of each kind went, and how
     *     many stay naming the account no more, by a plural name for them ('roles', 'activity entries')
     * @throws AccessRefused for the last administrator (unless $lastAdministrator), or an administrator's own account at
     *     their own hand
     */
    public function forget(string $account, ?Actor $by = null, bool $lastAdministrator = false): array
    {
        $account = trim($account);
        if ($account === '') {
            throw new InvalidArgumentException('Name the account to forget by its id.');
        }
        $by ??= $this->activity->signedIn() ?? Actor::system();
        $accounts = $this->accounts instanceof Closure ? ($this->accounts)() : $this->accounts;
        $known = $accounts?->forId($account);
        $email = $known !== null && trim($known->email) !== '' ? $known->email : null;
        $forget = function () use ($account, $known, $email, $by, $lastAdministrator, $accounts): array {
            $this->refuseAnAdministrator($account, $known, $by, $lastAdministrator, $accounts);

            return $this->forgetAll($account, $email, $known !== null ? [$known->email, $known->name] : [], $by);
        };
        try {
            return $this->connection !== null ? $this->connection->transactional(static fn (): array => $forget()) : $forget();
        } finally {
            $this->roles->refresh();
        }
    }

    /**
     * Refuses to forget an administrator at their own hand, or the last one: counted with the role's holders locked, so a
     * second forgetting at the same moment waits for this one and then counts what it left.
     *
     * @throws AccessRefused
     */
    private function refuseAnAdministrator(string $account, ?SignInAccount $known, Actor $by, bool $lastAdministrator, ?SignInAccounts $accounts): void
    {
        $holders = $this->roleStore->lockHolders(Roles::ADMIN);
        $isAdministrator = static fn (string $id, ?SignInAccount $found): bool => in_array($id, $holders, true) || ($found !== null && $found->administrator);
        if (!$isAdministrator($account, $known)) {
            return;
        }
        if ($by->id === $account) {
            throw new AccessRefused('You cannot delete your own account while you are an administrator: another administrator can.');
        }
        if ($lastAdministrator) {
            return;
        }
        $others = $holders;
        $address = trim($this->installation?->administratorEmail() ?? '');
        if ($accounts !== null && $address !== '') {
            $owner = $accounts->forEmail($address);
            if ($owner !== null) {
                $others[] = $owner->id;
            }
        }
        foreach (array_unique($others) as $other) {
            if ($other === $account) {
                continue;
            }
            if ($accounts === null) {
                // Without the project's accounts, a holder of the role counts as it stands.
                return;
            }
            // A role held by an account the project no longer finds is nobody's: it is not counted.
            $found = $accounts->forId($other);
            if ($found !== null && $isAdministrator($other, $found)) {
                return;
            }
        }
        throw new AccessRefused('This is the last administrator: give the role to someone else first.');
    }

    /**
     * @param list<string> $words what names the account in the details of the log's entries: its address and name
     * @return array{removed: array<string,int>, unnamed: array<string,int>}
     */
    private function forgetAll(string $account, ?string $email, array $words, Actor $by): array
    {
        $removed = [
            'roles' => $this->roleStore->forgetAccount($account),
            'two-step keys' => 0,
            'links' => $this->links->forgetAccount($account, $email),
            'sign-ins' => $this->sessions->forgetAccount($account),
        ];
        $unnamed = ['settings' => $this->settings->forgetAccount($account)];
        foreach ($this->keepers as $keeper) {
            $forgotten = $keeper->forgetAccount($account, $email);
            foreach ($forgotten['removed'] as $kind => $count) {
                $removed[$kind] = ($removed[$kind] ?? 0) + $count;
            }
            foreach ($forgotten['unnamed'] as $kind => $count) {
                $unnamed[$kind] = ($unnamed[$kind] ?? 0) + $count;
            }
            foreach ($forgotten['words'] ?? [] as $word) {
                $words[] = $word;
            }
        }
        // The log after everything else, so nothing written above still names the account.
        $unnamed['activity entries'] = $this->activityStore->forgetAccount($account, self::FORGOTTEN, array_values(array_unique(array_filter($words, static fn (string $word): bool => trim($word) !== ''))));
        // The two-step key last of all: without a database, a failure before this leaves the account signing in as it did.
        // Never read first: a key kept in a form this site cannot read is forgotten all the same.
        $removed['two-step keys'] = $this->twoStep->forget($account) ? 1 : 0;
        // Then the forgetting itself, by whoever did it (an account deleting itself is no longer named either).
        $this->activity->record('account.forgotten', ['removed' => array_filter($removed), 'unnamed' => array_filter($unnamed)],
            actor: $by->id === $account ? new Actor(null, self::FORGOTTEN) : $by);

        return ['removed' => $removed, 'unnamed' => $unnamed];
    }
}
