<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use Doctrine\DBAL\Exception as DatabaseError;
use CattoPHP\Access\DbalRoleStore;
use CattoPHP\Access\FoundationPermissions;
use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Access\Roles;
use CattoPHP\Activity\DbalActivityStore;
use CattoPHP\Security\TwoStep\DbalTwoStepStore;
use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Settings\DbalSettingsStore;
use CattoPHP\Links\DbalLinkStore;
use CattoPHP\Sessions\DbalSessionStore;
use CattoPHP\Tests\Support\SessionStoreContract;
use CattoPHP\Tests\Support\LinkStoreContract;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;

final class DatabaseStoresTest extends DatabaseTestCase
{
    public function testSettingsAreKeptWithSecretsEncrypted(): void
    {
        $site = new Foundations([], new DbalSettingsStore($this->connection), activityStore: new DbalActivityStore($this->connection));
        $site->settings->save('mail', ['MAILER_DSN' => 'smtps://user:hunter2@mail.example.com', 'MAIL_FROM_ADDRESS' => 'news@example.com', 'MAIL_FROM_NAME' => 'News']);

        $again = new Foundations([], new DbalSettingsStore($this->connection));
        self::assertSame('smtps://user:hunter2@mail.example.com', $again->settings->get('MAILER_DSN'));
        self::assertSame('News', $again->settings->get('MAIL_FROM_NAME'));
        $stored = (string) $this->connection->fetchOne("SELECT value FROM cattophp_settings WHERE name = 'MAILER_DSN'");
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('hunter2', $stored);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM cattophp_activity WHERE details::text LIKE '%hunter2%'"));

        $again->settings->reset('MAIL_FROM_NAME');
        $left = $this->connection->fetchFirstColumn('SELECT name FROM cattophp_settings');
        sort($left);
        self::assertSame(['MAILER_DSN', 'MAIL_FROM_ADDRESS'], $left);
    }

    public function testTheActivityLogIsReadBackNewestFirstAndNarrowed(): void
    {
        $site = new Foundations(activityStore: new DbalActivityStore($this->connection));
        $site->activity->record('sign_in.signed_in', ['how' => 'link'], 'account', '7');
        $site->clock->modify('+1 second');
        $site->activity->record('settings.changed', ['setting' => 'APP_NAME', 'old' => 'A', 'new' => 'B'], 'setting', 'APP_NAME');
        $site->clock->modify('+1 second');
        $site->activity->record('sign_in.signed_out', [], 'account', '7');

        $store = new DbalActivityStore($this->connection);
        self::assertSame(['sign_in.signed_out', 'settings.changed', 'sign_in.signed_in'], array_map(static fn ($entry): string => $entry->action, $store->recent()));
        $mine = $store->recent(10, subjectType: 'account', subjectId: '7');
        self::assertCount(2, $mine);
        self::assertSame(['how' => 'link'], $mine[1]->details);
        self::assertSame([], $mine[0]->details);
        self::assertSame('2026-11-09 10:00:00', $mine[1]->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testEntriesFromBeforeAMomentAreRemovedAndTheRestKept(): void
    {
        $site = new Foundations(activityStore: new DbalActivityStore($this->connection));
        $site->activity->record('area.first');
        $site->clock->modify('+1 day');
        $site->activity->record('area.second');
        $site->clock->modify('+1 day');
        $site->activity->record('area.third');

        self::assertSame(2, $site->activity->removeBefore($site->clock->now()->modify('-1 hour')));
        self::assertSame(['area.third'], array_map(static fn ($entry): string => $entry->action, $site->activity->recent()));
        self::assertSame(0, $site->activity->removeBefore($site->clock->now()->modify('-1 hour')), 'nothing more to remove');
        $indexes = $this->connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE tablename = 'cattophp_activity' AND indexname IN ('cattophp_activity_actor', 'cattophp_activity_action') ORDER BY indexname");
        self::assertStringContainsString('(action, happened_at DESC, id DESC)', (string) $indexes[0], 'one kind of activity, newest first');
        self::assertStringContainsString('(actor_id, happened_at DESC, id DESC)', (string) $indexes[1], 'one person\'s activity, newest first');
    }

    public function testRolesAreKeptAndGoWithTheirHolders(): void
    {
        $site = new Foundations();
        $roles = new Roles(new DbalRoleStore($this->connection), new PermissionCatalogue([new FoundationPermissions()]), $site->activity, $site->clock);
        $roles->save('AUDITOR', 'Auditor', 'Reads the log.', ['PLATFORM.ACTIVITY.VIEW', 'PLATFORM.SETTINGS.VIEW']);
        $roles->grant('7', 'AUDITOR');
        $roles->grant('1', 'ADMIN');

        $again = new Roles(new DbalRoleStore($this->connection), new PermissionCatalogue([new FoundationPermissions()]), $site->activity, $site->clock);
        self::assertSame(['PLATFORM.ACTIVITY.VIEW', 'PLATFORM.SETTINGS.VIEW'], $again->permissionsOf('7'));
        self::assertTrue($again->isAdministrator('1'));
        self::assertSame(['7'], $again->holders('AUDITOR'));

        $again->delete('AUDITOR');
        self::assertSame([], $again->rolesOf('7'), 'deleting a role takes it from its holders');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM cattophp_role_permissions'));
    }

    public function testARenamedRoleTakesItsPermissionsAndHoldersAndNoSystemPermissionIsKeptForAnyRole(): void
    {
        $site = new Foundations();
        $roles = new Roles(new DbalRoleStore($this->connection), new PermissionCatalogue([new FoundationPermissions()]), $site->activity, $site->clock);
        $roles->save('AUDITOR', 'Auditor', 'Reads the log.', ['PLATFORM.ACTIVITY.VIEW']);
        $roles->grant('7', 'AUDITOR');
        $roles->rename('AUDITOR', 'READER');

        $again = new Roles(new DbalRoleStore($this->connection), new PermissionCatalogue([new FoundationPermissions()]), $site->activity, $site->clock);
        self::assertSame(['READER'], $again->rolesOf('7'));
        self::assertSame(['PLATFORM.ACTIVITY.VIEW'], $again->permissionsOf('7'));
        self::assertArrayNotHasKey('AUDITOR', $again->all());
        try {
            $this->connection->insert('cattophp_role_permissions', ['role_key' => 'READER', 'permission' => 'SYSTEM.ROLE.MANAGE']);
            self::fail('The database keeps a SYSTEM permission for a role.');
        } catch (DatabaseError) {
            self::assertSame(['PLATFORM.ACTIVITY.VIEW'], $this->connection->fetchFirstColumn("SELECT permission FROM cattophp_role_permissions WHERE role_key = 'READER'"));
        }
    }

    public function testTheDatabaseKeepsSignInsAsEveryStoreDoes(): void
    {
        SessionStoreContract::holds(new DbalSessionStore($this->connection), 'database');
    }

    public function testTheDatabaseKeepsLinksAsEveryStoreDoes(): void
    {
        LinkStoreContract::holds(new DbalLinkStore($this->connection), 'database');
    }

    public function testASignInLinkIsUsedOnceEvenByTwoAttemptsAtOnce(): void
    {
        $site = new Foundations();
        $links = $site->signInLinks('/nowhere', store: new DbalLinkStore($this->connection));
        $again = $site->signInLinks('/nowhere', store: new DbalLinkStore($this->connection));
        $token = $links->create(new SignInAccount('owner', 'owner@example.com', 'Owner', true), '/back');

        self::assertSame([hash('sha256', $token), 'sign_in'], array_values((array) $this->connection->fetchAssociative('SELECT token_hash, purpose FROM cattophp_links')));
        self::assertNotNull($links->usable($token));
        self::assertSame('/back', $links->claim($token)?->returnPath);
        self::assertNull($again->claim($token));
        $site->clock->modify('+8 days');
        $links->create(new SignInAccount('owner', 'owner@example.com', 'Owner', true));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM cattophp_links'), 'used and old links are cleared after a week');
    }

    public function testTwoStepKeepsItsKeyEncrypted(): void
    {
        $site = new Foundations();
        // The store's own clock is a day behind: when a key was set up is the two-step service's, as every store keeps it.
        $store = new DbalTwoStepStore($this->connection, $site->cipher, new \Symfony\Component\Clock\MockClock($site->clock->now()->modify('-1 day')));
        $twoStep = $site->twoStep($store);
        $secret = OneTimeCode::newSecret();
        $codes = (array) $twoStep->setUp('7', $secret, OneTimeCode::codeAt($secret, $site->clock->now()->getTimestamp()));

        $row = $this->connection->fetchAssociative('SELECT secret_encrypted, recovery_hashes FROM cattophp_two_step WHERE account_id = ?', ['7']);
        self::assertIsArray($row);
        self::assertStringStartsWith('v1:', (string) $row['secret_encrypted']);
        self::assertStringNotContainsString($secret, (string) $row['secret_encrypted']);
        self::assertStringNotContainsString($codes[0], (string) $row['recovery_hashes']);

        $site->clock->modify('+30 seconds');
        self::assertTrue($twoStep->check('7', OneTimeCode::codeAt($secret, $site->clock->now()->getTimestamp())));
        self::assertTrue($twoStep->check('7', $codes[0]));
        self::assertSame(7, $twoStep->recoveryCodesLeft('7'));
        self::assertSame($site->clock->now()->modify('-30 seconds')->getTimestamp(), $twoStep->setUpAt('7')?->getTimestamp(), 'when it was set up, kept as codes are used');
        $twoStep->reset('7');
        self::assertFalse($twoStep->isSetUp('7'));
    }
}
