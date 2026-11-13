<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Account;

use CattoPHP\Access\AccessRefused;
use CattoPHP\Access\Roles;
use CattoPHP\Account\AccountRecords;
use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Activity\ActivityEntry;
use CattoPHP\Activity\Actor;
use CattoPHP\Activity\FileActivityStore;
use CattoPHP\Links\FileLinkStore;
use CattoPHP\Links\LinkStore;
use CattoPHP\Links\MemoryLinkStore;
use CattoPHP\Security\TwoStep\FileTwoStepStore;
use CattoPHP\Security\TwoStep\MemoryTwoStepStore;
use CattoPHP\Security\TwoStep\TwoStepRecord;
use CattoPHP\Security\TwoStep\TwoStepStore;
use CattoPHP\Sessions\FileSessionStore;
use CattoPHP\Sessions\MemorySessionStore;
use CattoPHP\Sessions\SessionStore;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInAccounts;
use CattoPHP\Tests\Support\Foundations;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Forgetting an account the project deletes (AccountRecords::forget()): the foundations' records of it go or stop
 * naming it, a project's own records are forgotten through ForgetsAccounts, and an administrator is refused as taking the
 * Administrator role away refuses.
 */
final class AccountRecordsTest extends TestCase
{
    private Foundations $site;
    private MemorySettingsStore $settingsStore;
    private MemoryTwoStepStore $twoStepStore;
    private MemoryLinkStore $links;
    private MemorySessionStore $sessions;

    protected function setUp(): void
    {
        $this->settingsStore = new MemorySettingsStore();
        $this->site = new Foundations(settingsStore: $this->settingsStore);
        $this->twoStepStore = new MemoryTwoStepStore();
        $this->links = new MemoryLinkStore();
        $this->sessions = new MemorySessionStore();
    }

    public function testWhatIsTheAccountsAloneGoesAndWhatStaysNamesItNoMore(): void
    {
        $this->recordsOf('2', 'anna@example.com');
        $this->recordsOf('1', 'owner@example.com');
        $this->site->roles->grant('1', Roles::ADMIN);
        $this->links->add('joining-anna', 'sign_in_join', 'Anna@Example.com', null, [], null, $this->site->clock->now(), $this->site->clock->now()->modify('+30 minutes'));
        $this->links->add('joining-other', 'sign_in_join', 'someone@example.org', null, [], null, $this->site->clock->now(), $this->site->clock->now()->modify('+30 minutes'));
        // The log from here: what she did, what was done to her, what she did to Olive, and something about neither.
        $this->site->activityStore->removeBefore(new DateTimeImmutable('2027-01-01'));
        $this->site->activity->record('account.created', ['email' => 'ANNA@example.com', 'name' => 'Anna Example', 'how' => 'joined'], 'account', '2', new Actor('2', 'Anna Example'));
        $this->site->activity->record('access.role_given', ['role' => 'EDITOR'], 'account', '2', new Actor('1', 'Olive Owner'));
        $this->site->activity->record('access.role_given', ['role' => 'ADMIN'], 'account', '1', new Actor('2', 'Anna Example'));
        $this->site->activity->record('settings.changed', [], 'setting', 'APP_NAME', new Actor('1', 'Olive Owner'));

        $done = $this->records()->forget('2', new Actor('1', 'Olive Owner'));

        self::assertSame(['roles' => 1, 'two-step keys' => 1, 'links' => 2, 'sign-ins' => 2], $done['removed'], 'Her role, key, own link and joining link, and both her sign-ins.');
        self::assertSame(['settings' => 1, 'activity entries' => 3], $done['unnamed'], 'Her setting, and the entries by or about her (the role she gave Olive among them).');
        self::assertSame([], $this->site->roles->rolesOf('2'));
        self::assertNull($this->twoStepStore->find('2'));
        self::assertSame([], $this->sessions->activeFor('2', $this->site->clock->now()));
        self::assertNull($this->links->find('joining-anna', 'sign_in_join'), 'The link made for her address before she had an account.');
        self::assertNotNull($this->links->find('joining-other', 'sign_in_join'), 'Another address\'s stays.');
        self::assertNull($this->settingsStore->all()['SETTING_2']->updatedBy, 'What she saved stays, by nobody named.');
        self::assertSame('yes', $this->settingsStore->all()['SETTING_2']->value);

        // Olive's are untouched.
        self::assertSame([Roles::ADMIN, 'EDITOR'], $this->site->roles->rolesOf('1'));
        self::assertNotNull($this->twoStepStore->find('1'));
        self::assertCount(1, $this->sessions->activeFor('1', $this->site->clock->now()));
        self::assertSame('1', $this->settingsStore->all()['SETTING_1']->updatedBy);

        $entries = array_reverse($this->site->activity->recent(10));
        self::assertCount(5, $entries);
        self::assertSame([null, AccountRecords::FORGOTTEN, 'account', null], self::who($entries[0]), 'What she did: by "A deleted account", about nobody.');
        self::assertSame(['email' => AccountRecords::FORGOTTEN, 'name' => AccountRecords::FORGOTTEN, 'how' => 'joined'], $entries[0]->details, 'Her address and name in what it says, whatever their case; the rest as it was.');
        self::assertSame(['role' => 'EDITOR'], $entries[1]->details);
        self::assertSame(['1', 'Olive Owner', 'account', null], self::who($entries[1]), 'What Olive did to her: still Olive\'s, about nobody.');
        self::assertSame([null, AccountRecords::FORGOTTEN, 'account', '1'], self::who($entries[2]), 'What she did to Olive: by nobody named, still about Olive.');
        self::assertSame(['1', 'Olive Owner', 'setting', 'APP_NAME'], self::who($entries[3]), 'Nothing about her: as it was.');
        $forgotten = end($entries);
        self::assertInstanceOf(ActivityEntry::class, $forgotten);
        self::assertSame('account.forgotten', $forgotten->action);
        self::assertSame(['1', 'Olive Owner', null, null], self::who($forgotten), 'The forgetting itself: by Olive, about nobody.');
        self::assertSame(['removed' => ['roles' => 1, 'two-step keys' => 1, 'links' => 2, 'sign-ins' => 2], 'unnamed' => ['settings' => 1, 'activity entries' => 3]], $forgotten->details);
    }

    public function testTheLastAdministratorIsNotForgotten(): void
    {
        $this->recordsOf('1', 'owner@example.com');
        $this->site->roles->grant('1', Roles::ADMIN);
        try {
            $this->records()->forget('1', new Actor('2', 'Anna Example'));
            self::fail('The last administrator was forgotten.');
        } catch (AccessRefused $refused) {
            self::assertSame('This is the last administrator: give the role to someone else first.', $refused->getMessage());
        }
        self::assertSame([Roles::ADMIN, 'EDITOR'], $this->site->roles->rolesOf('1'), 'Nothing changed.');
        self::assertNotNull($this->twoStepStore->find('1'));
        self::assertCount(1, $this->sessions->activeFor('1', $this->site->clock->now()));

        // An account made only to be removed again, such as a check's own administrator on a database that had none.
        $done = $this->records()->forget('1', lastAdministrator: true);

        self::assertSame(2, $done['removed']['roles']);
        self::assertSame([], $this->site->roles->holders(Roles::ADMIN), 'The site is as it was before that account: no administrator.');
    }

    public function testAnAdministratorDoesNotForgetTheirOwnAccountAnotherAdministratorDoes(): void
    {
        $this->site->roles->grant('1', Roles::ADMIN);
        $this->site->roles->grant('2', Roles::ADMIN);
        try {
            $this->records()->forget('2', new Actor('2', 'Anna Example'));
            self::fail('An administrator forgot their own account.');
        } catch (AccessRefused $refused) {
            self::assertSame('You cannot delete your own account while you are an administrator: another administrator can.', $refused->getMessage());
        }

        $done = $this->records()->forget('2', new Actor('1', 'Olive Owner'));

        self::assertSame(1, $done['removed']['roles']);
        self::assertSame(['1'], $this->site->roles->holders(Roles::ADMIN));
    }

    /**
     * Review finding 4: an administrator by the project's own flag (SignInAccount::administrator) counts as one, as
     * SignInRules counts them: they do not forget their own account, the last of them is not forgotten, and while one is
     * there a holder of the Administrator role is not the last. A role held by an account the project no longer finds
     * counts for nothing.
     */
    public function testAnAdministratorByTheProjectsFlagCountsAsOne(): void
    {
        // Olive (1) is the installation's administrator, flagged by the project, holding no role; Anna (2) holds the role.
        $accounts = self::people(['1' => new SignInAccount('1', 'owner@example.com', 'Olive Owner', true), '2' => new SignInAccount('2', 'anna@example.com', 'Anna Example'),
            '6' => new SignInAccount('6', 'ben@example.com', 'Ben Example')]);
        $this->site->roles->grant('2', Roles::ADMIN);
        try {
            $this->records(accounts: $accounts)->forget('1', new Actor('1', 'Olive Owner'));
            self::fail('An administrator by the project\'s flag forgot their own account.');
        } catch (AccessRefused $refused) {
            self::assertSame('You cannot delete your own account while you are an administrator: another administrator can.', $refused->getMessage());
        }
        // With Olive there, Anna is not the last.
        self::assertSame(1, $this->records(accounts: $accounts)->forget('2', new Actor('1', 'Olive Owner'))['removed']['roles']);
        // Olive, flagged, is the last now: refused, as the last by the role is.
        try {
            $this->records(accounts: $accounts)->forget('1', new Actor('6', 'Ben Example'));
            self::fail('The last administrator, by the project\'s flag, was forgotten.');
        } catch (AccessRefused $refused) {
            self::assertSame('This is the last administrator: give the role to someone else first.', $refused->getMessage());
        }

        // Ben (6) holds the role beside an account deleted since (5): Ben is the last.
        $this->site->roles->grant('6', Roles::ADMIN);
        $this->site->roles->grant('5', Roles::ADMIN);
        $nobodyFlagged = self::people(['6' => new SignInAccount('6', 'ben@example.com', 'Ben Example'), '7' => new SignInAccount('7', 'cara@example.com', 'Cara Example')]);
        try {
            $this->records(accounts: $nobodyFlagged)->forget('6', new Actor('7', 'Cara Example'));
            self::fail('A role held by an account deleted since was counted as an administrator.');
        } catch (AccessRefused $refused) {
            self::assertSame('This is the last administrator: give the role to someone else first.', $refused->getMessage());
        }
    }

    /**
     * Review finding 11: without a database the files are changed one after the other, the two-step key last, so a failure
     * part of the way leaves the account signing in as before (its key kept), and forget() run again carries on.
     */
    public function testWithoutADatabaseAFailurePartOfTheWayKeepsTheTwoStepKeyAndForgettingAgainFinishes(): void
    {
        $folder = Foundations::folder();
        try {
            $activity = new FileActivityStore($folder . '/activity');
            $site = new Foundations(activityStore: $activity);
            $twoStep = new FileTwoStepStore($folder . '/two-step/{account}.json');
            $now = $site->clock->now();
            $twoStep->change('2', static fn (): TwoStepRecord => new TwoStepRecord('encrypted', 0, [], $now));
            $keeper = new class implements ForgetsAccounts {
                public bool $failing = true;

                public function forgetAccount(string $account, ?string $email): array
                {
                    if ($this->failing) {
                        throw new \RuntimeException('A project\'s records could not be forgotten.');
                    }

                    return ['removed' => [], 'unnamed' => []];
                }
            };
            $records = new AccountRecords($site->roles, $site->roleStore, $twoStep, new FileLinkStore($folder . '/links.json'), new FileSessionStore($folder . '/sessions.json'),
                new MemorySettingsStore(), $activity, $site->activity, [$keeper], accounts: self::anna());

            try {
                $records->forget('2');
                self::fail('A failure went unnoticed.');
            } catch (\RuntimeException $failed) {
                self::assertSame('A project\'s records could not be forgotten.', $failed->getMessage());
            }
            self::assertNotNull($twoStep->find('2'), 'Her two-step key is kept: she signs in as before, never without it.');

            $keeper->failing = false;
            self::assertSame(1, $records->forget('2')['removed']['two-step keys'], 'Run again, it carries on where it stopped.');
            self::assertNull($twoStep->find('2'));
        } finally {
            Foundations::remove($folder);
        }
    }

    public function testSomeoneDeletingTheirOwnAccountIsNotNamedInTheRecordOfIt(): void
    {
        $this->recordsOf('2', 'anna@example.com');

        $this->records()->forget('2', new Actor('2', 'Anna Example'));

        $forgotten = $this->site->activity->recent(1)[0];
        self::assertSame('account.forgotten', $forgotten->action);
        self::assertSame([null, AccountRecords::FORGOTTEN, null, null], self::who($forgotten));
    }

    public function testAProjectsOwnRecordsAreForgottenWithTheAddressItHad(): void
    {
        $accounts = $this->createStub(SignInAccounts::class);
        $accounts->method('forId')->willReturn(new SignInAccount('2', 'anna@example.com', 'Anna Example'));
        $notes = new class implements ForgetsAccounts {
            /** @var list<array{string,?string}> */
            public array $asked = [];

            public function forgetAccount(string $account, ?string $email): array
            {
                $this->asked[] = [$account, $email];

                return ['removed' => ['notes' => 3], 'unnamed' => ['notes shared with others' => 1]];
            }
        };

        // Given by a closure, as the bundle gives them, so a project's accounts service may take AccountRecords itself.
        $done = (new AccountRecords($this->site->roles, $this->site->roleStore, $this->twoStepStore, $this->links, $this->sessions, $this->settingsStore,
            $this->site->activityStore, $this->site->activity, [$notes], null, static fn (): SignInAccounts => $accounts))->forget('2');

        self::assertSame([['2', 'anna@example.com']], $notes->asked);
        self::assertSame(3, $done['removed']['notes']);
        self::assertSame(1, $done['unnamed']['notes shared with others']);
        self::assertSame(['notes' => 3], $this->site->activity->recent(1)[0]->details['removed'] ?? null, 'In the record of it, only what there was.');
    }

    public function testWithoutADatabaseTheFilesForgetItToo(): void
    {
        $folder = Foundations::folder();
        try {
            $activity = new FileActivityStore($folder . '/activity');
            $site = new Foundations(activityStore: $activity);
            $links = new FileLinkStore($folder . '/links.json');
            $sessions = new FileSessionStore($folder . '/sessions.json');
            $twoStep = new FileTwoStepStore($folder . '/two-step/{account}.json');
            $now = $site->clock->now();
            foreach (['1', '2'] as $account) {
                $links->add('link-' . $account, 'sign_in', null, $account, [], null, $now, $now->modify('+30 minutes'));
                $sessions->start('hash-' . $account, 'public-' . $account, $account, null, null, 'Firefox', $now, $now->modify('+1 day'));
                $twoStep->change($account, static fn (): TwoStepRecord => new TwoStepRecord('encrypted', 0, [], $now));
                $site->activity->record('account.created', ['email' => $account === '2' ? 'anna@example.com' : 'owner@example.com'], 'account', $account, new Actor($account, 'Person ' . $account));
            }
            $links->add('joining-2', 'sign_in_join', 'anna@example.com', null, [], null, $now, $now->modify('+30 minutes'));

            $done = (new AccountRecords($site->roles, $site->roleStore, $twoStep, $links, $sessions, new MemorySettingsStore(), $activity, $site->activity,
                accounts: self::anna()))->forget('2');

            self::assertSame(['roles' => 0, 'two-step keys' => 1, 'links' => 2, 'sign-ins' => 1], $done['removed']);
            self::assertSame(1, $done['unnamed']['activity entries']);
            self::assertNull($twoStep->find('2'));
            self::assertNotNull($twoStep->find('1'));
            self::assertNull($links->find('joining-2', 'sign_in_join'));
            self::assertNotNull($links->find('link-1', 'sign_in'));
            self::assertCount(0, $sessions->activeFor('2', $now));
            self::assertCount(1, $sessions->activeFor('1', $now));
            $entries = array_reverse($activity->recent(10));
            self::assertSame(['1', 'Person 1', 'account', '1'], self::who($entries[0]));
            self::assertSame([null, AccountRecords::FORGOTTEN, 'account', null], self::who($entries[1]));
            self::assertSame(['email' => AccountRecords::FORGOTTEN], $entries[1]->details, 'Her address, in the file too.');
            self::assertSame(['email' => 'owner@example.com'], $entries[0]->details);
            self::assertSame(0, $activity->count(actorId: '2'), 'Read back from the file: nothing names her.');
            self::assertSame(0, $activity->count(subjectType: 'account', subjectId: '2'));
            self::assertSame('account.forgotten', $entries[2]->action);
        } finally {
            Foundations::remove($folder);
        }
    }

    public function testAnAccountIsNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->records()->forget(' ');
    }

    /**
     * @param list<ForgetsAccounts> $keepers
     */
    private function records(array $keepers = [], ?SignInAccounts $accounts = null, ?TwoStepStore $twoStep = null, ?LinkStore $links = null, ?SessionStore $sessions = null): AccountRecords
    {
        return new AccountRecords($this->site->roles, $this->site->roleStore, $twoStep ?? $this->twoStepStore, $links ?? $this->links, $sessions ?? $this->sessions,
            $this->settingsStore, $this->site->activityStore, $this->site->activity, $keepers, null, $accounts ?? self::anna(), $this->site->installation);
    }

    /** @param array<array-key,SignInAccount> $people the project's accounts by id; any other id is no account */
    private static function people(array $people): SignInAccounts
    {
        return new class ($people) implements SignInAccounts {
            /** @param array<array-key,SignInAccount> $people */
            public function __construct(private readonly array $people)
            {
            }

            public function forEmail(string $email): ?SignInAccount
            {
                foreach ($this->people as $account) {
                    if (strtolower($account->email) === strtolower(trim($email))) {
                        return $account;
                    }
                }

                return null;
            }

            public function forId(string $id): ?SignInAccount
            {
                return $this->people[$id] ?? null;
            }
        };
    }

    private static function anna(): SignInAccounts
    {
        return new class implements SignInAccounts {
            public function forEmail(string $email): ?SignInAccount
            {
                return null;
            }

            public function forId(string $id): SignInAccount
            {
                return $id === '2' ? new SignInAccount('2', 'anna@example.com', 'Anna Example') : new SignInAccount($id, 'owner@example.com', 'Olive Owner', true);
            }
        };
    }

    /** A role, a two-step key, a link, sign-ins and a setting saved: what an account that has signed in has. */
    private function recordsOf(string $account, string $email): void
    {
        $now = $this->site->clock->now();
        $this->site->roles->save('EDITOR', 'Editor', 'Edits.', []);
        $this->site->roles->grant($account, 'EDITOR');
        $this->twoStepStore->change($account, static fn (): TwoStepRecord => new TwoStepRecord('encrypted', 0, [], $now));
        $this->links->add('link-' . $account, 'sign_in', $email, $account, [], null, $now, $now->modify('+30 minutes'));
        foreach (range(1, $account === '2' ? 2 : 1) as $number) {
            $this->sessions->start('hash-' . $account . '-' . $number, 'public-' . $account . '-' . $number, $account, $email, null, 'Firefox', $now, $now->modify('+1 day'));
        }
        $this->settingsStore->save('SETTING_' . $account, 'yes', false, new DateTimeImmutable('2026-11-09 10:00:00'), $account);
    }

    /** @return array{?string,?string,?string,?string} who did it and what it was about */
    private static function who(ActivityEntry $entry): array
    {
        return [$entry->actorId, $entry->actorName, $entry->subjectType, $entry->subjectId];
    }
}
