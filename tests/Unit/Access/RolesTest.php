<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Access;

use CattoPHP\Access\AccessRefused;
use CattoPHP\Access\Permission;
use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Access\PermissionProvider;
use CattoPHP\Access\PermissionVoter;
use CattoPHP\Activity\Actor;
use CattoPHP\SignIn\SignInUser;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class RolesTest extends TestCase
{
    private Foundations $site;

    protected function setUp(): void
    {
        $this->site = new Foundations(permissionProviders: [new ExamplePermissions()]);
    }

    public function testARoleGrantsItsPermissionsAndNothingElse(): void
    {
        $roles = $this->site->roles;
        $roles->save('EDITOR', 'Editor', 'Writes pages.', ['PAGE.EDIT', 'PAGE.VIEW']);
        $roles->grant('7', 'EDITOR');

        self::assertTrue($roles->may('7', 'PAGE.EDIT'));
        self::assertFalse($roles->may('7', 'PAGE.DELETE'));
        self::assertFalse($roles->may('8', 'PAGE.EDIT'));
        self::assertSame(['PAGE.EDIT', 'PAGE.VIEW'], $roles->permissionsOf('7'));
    }

    public function testAdminHoldsEveryPermissionNowAndLater(): void
    {
        $this->site->roles->grant('1', 'ADMIN');

        self::assertTrue($this->site->roles->may('1', 'PAGE.DELETE'));
        self::assertTrue($this->site->roles->may('1', 'SYSTEM.SETTINGS.MANAGE'));
        self::assertFalse($this->site->roles->may('1', 'PAGE.MISTYPED'), 'a key not in the catalogue is never granted');
    }

    public function testSystemPermissionsAreForAdministratorsAlone(): void
    {
        $this->expectException(AccessRefused::class);
        $this->expectExceptionMessage('for administrators alone');
        $this->site->roles->save('HELPER', 'Helper', '', ['SYSTEM.ROLE.MANAGE']);
    }

    public function testNobodyTakesTheAdministratorRoleFromThemselvesOrFromTheLastOne(): void
    {
        $roles = $this->site->roles;
        $roles->grant('1', 'ADMIN');
        try {
            $roles->revoke('1', 'ADMIN', new Actor('2', 'Someone else'));
            self::fail('The last administrator must keep the role.');
        } catch (AccessRefused $refused) {
            self::assertStringContainsString('last administrator', $refused->getMessage());
        }
        $roles->grant('2', 'ADMIN');
        try {
            $roles->revoke('1', 'ADMIN', new Actor('1', 'Themselves'));
            self::fail('Nobody takes the role from themselves.');
        } catch (AccessRefused $refused) {
            self::assertStringContainsString('from yourself', $refused->getMessage());
        }
        $roles->revoke('1', 'ADMIN', new Actor('2', 'The other administrator'));
        self::assertFalse($roles->isAdministrator('1'));
    }

    public function testTheAdministratorRoleCannotBeChangedOrDeleted(): void
    {
        $this->expectException(AccessRefused::class);
        $this->site->roles->delete('ADMIN');
    }

    public function testRoleNamesAreUniqueWhateverTheirCase(): void
    {
        $this->site->roles->save('EDITOR', 'Editor', '', []);
        $this->expectException(AccessRefused::class);
        $this->expectExceptionMessage('already called Editor');
        $this->site->roles->save('WRITER', 'editor', '', []);
    }

    public function testEveryChangeIsInTheActivityLog(): void
    {
        $roles = $this->site->roles;
        $roles->save('EDITOR', 'Editor', '', ['PAGE.EDIT']);
        $roles->grant('7', 'EDITOR');
        $roles->revoke('7', 'EDITOR');
        $roles->delete('EDITOR');

        self::assertSame(
            ['access.role_deleted', 'access.role_taken', 'access.role_given', 'access.role_made'],
            array_map(static fn ($entry): string => $entry->action, $this->site->activity->recent(10)),
        );
    }

    public function testARoleIsRenamedWithItsHoldersButTheAdministratorKeepsItsKey(): void
    {
        $roles = $this->site->roles;
        $roles->save('EDITOR', 'Editor', 'Edits pages.', ['PAGE.EDIT']);
        $roles->grant('7', 'EDITOR');
        $roles->rename('EDITOR', 'writer');

        self::assertSame(['WRITER'], $roles->rolesOf('7'));
        self::assertTrue($roles->may('7', 'PAGE.EDIT'));
        self::assertSame(['name' => 'Editor', 'old_key' => 'EDITOR', 'key' => 'WRITER'], $this->site->activity->recent(1)[0]->details);
        foreach ([['ADMIN', 'BOSS'], ['WRITER', 'ADMIN'], ['WRITER', 'x'], ['NOBODY', 'SOMEONE']] as [$from, $to]) {
            try {
                $roles->rename($from, $to);
                self::fail($from . ' became ' . $to);
            } catch (AccessRefused) {
                self::assertSame(['WRITER'], $roles->rolesOf('7'));
            }
        }
        $roles->save('READER', 'Reader', '', []);
        $this->expectException(AccessRefused::class);
        $roles->rename('WRITER', 'READER');
    }

    public function testAChangeRecordsTheDescriptionBeforeAndAfterAndRefreshReadsTheStoreAgain(): void
    {
        $roles = $this->site->roles;
        $roles->save('EDITOR', 'Editor', 'Edits pages.', ['PAGE.EDIT']);
        $roles->save('EDITOR', 'Editor', 'Edits every page.', ['PAGE.EDIT']);
        $details = $this->site->activity->recent(1)[0]->details;
        self::assertSame(['Edits every page.', 'Edits pages.'], [$details['description'], $details['old_description']]);

        $roles->grant('7', 'EDITOR');
        self::assertSame(['EDITOR'], $roles->rolesOf('7'));
        $this->site->roleStore->revoke('7', 'EDITOR');
        self::assertSame(['EDITOR'], $roles->rolesOf('7'), 'what was read is remembered');
        $roles->refresh();
        self::assertSame([], $roles->rolesOf('7'), 'until refreshed');
    }

    public function testThePermissionVoterAsksTheRolesAndLetsAdministratorsDoAnything(): void
    {
        $roles = $this->site->roles;
        $roles->save('EDITOR', 'Editor', '', ['PAGE.EDIT']);
        $roles->grant('7', 'EDITOR');
        $voter = new PermissionVoter($roles);
        $editor = new SignInUser('7', 'ed@example.com', 'Ed', false);
        $owner = new SignInUser('owner', 'owner@example.com', 'Owner', true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new UsernamePasswordToken($editor, 'main', $editor->getRoles()), null, ['PAGE.EDIT']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($editor, 'main', $editor->getRoles()), null, ['PAGE.DELETE']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new UsernamePasswordToken($owner, 'main', $owner->getRoles()), null, ['SYSTEM.ROLE.MANAGE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new UsernamePasswordToken($owner, 'main', $owner->getRoles()), null, ['ROLE_USER']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new UsernamePasswordToken($owner, 'main', $owner->getRoles()), null, ['PAGE.MISTYPED']));
    }

    public function testAPermissionIsNamedResourceFirstInCapitals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Permission('page.edit', 'Pages', '');
    }

    public function testAPermissionNamedTwiceIsAMistake(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PermissionCatalogue([new ExamplePermissions(), new ExamplePermissions()]);
    }
}

final class ExamplePermissions implements PermissionProvider
{
    public function permissions(): iterable
    {
        yield new Permission('PAGE.VIEW', 'Pages', 'See pages.');
        yield new Permission('PAGE.EDIT', 'Pages', 'Change pages.');
        yield new Permission('PAGE.DELETE', 'Pages', 'Delete pages.');
    }
}
