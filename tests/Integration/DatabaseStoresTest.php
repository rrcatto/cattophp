<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\DbalRoleStore;
use CattoPHP\Access\FoundationPermissions;
use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Access\Roles;
use CattoPHP\Activity\DbalActivityStore;
use CattoPHP\Security\TwoStep\DbalTwoStepStore;
use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Settings\DbalSettingsStore;
use CattoPHP\SignIn\DbalSignInLinkStore;
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

    public function testALinkIsUsedOnceEvenByTwoAttemptsAtOnce(): void
    {
        $site = new Foundations();
        $store = new DbalSignInLinkStore($this->connection);
        $links = $site->signInLinks('/nowhere', store: $store);
        $token = $links->create(new SignInAccount('owner', 'owner@example.com', 'Owner', true), '/back');

        self::assertSame(hash('sha256', $token), $this->connection->fetchOne('SELECT token_hash FROM cattophp_sign_in_links'));
        self::assertNotNull($links->usable($token));
        $second = new DbalSignInLinkStore($this->connection);
        $first = $store->claim(hash('sha256', $token), $site->clock->now());
        self::assertSame('/back', $first?->returnPath);
        self::assertNull($second->claim(hash('sha256', $token), $site->clock->now()));
        $site->clock->modify('+1 day');
        $links->create(new SignInAccount('owner', 'owner@example.com', 'Owner', true));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM cattophp_sign_in_links'), 'used and old links are cleared');
    }

    public function testTwoStepKeepsItsKeyEncrypted(): void
    {
        $site = new Foundations();
        $store = new DbalTwoStepStore($this->connection, $site->cipher, $site->clock);
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
        $twoStep->reset('7');
        self::assertFalse($twoStep->isSetUp('7'));
    }
}
