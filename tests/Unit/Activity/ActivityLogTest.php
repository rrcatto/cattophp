<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Activity;

use CattoPHP\Account\AccountRecords;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Activity\FileActivityStore;
use CattoPHP\Activity\MemoryActivityStore;
use CattoPHP\Http\RequestReference;
use CattoPHP\SignIn\SignInUser;
use CattoPHP\Tests\Support\ActivityStoreContract;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\FullDisk;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ActivityLogTest extends TestCase
{
    public function testSecretsAreNeverKept(): void
    {
        $clean = ActivityLog::clean([
            'password' => 'hunter2',
            'api_key' => 'abc',
            'webhook_secret' => 'def',
            'nested' => ['token' => 'ghi', 'name' => 'kept'],
            'transport' => 'smtp://user:hunter2@mail.example.com:587',
            'course_code' => 'FIN-101',
            'empty_password' => '',
            'secret_changed' => true,
        ]);

        self::assertSame([
            'password' => '••••',
            'api_key' => '••••',
            'webhook_secret' => '••••',
            'nested' => ['token' => '••••', 'name' => 'kept'],
            'transport' => 'smtp://user:••••@mail.example.com:587',
            'course_code' => 'FIN-101',
            'empty_password' => '',
            'secret_changed' => true,
        ], $clean);
    }

    public function testTheSignedInPersonTheAddressAndTheReferenceAreRecorded(): void
    {
        $requests = new RequestStack();
        $request = Request::create('/settings', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $requests->push($request);
        $tokens = new TokenStorage();
        $user = new SignInUser('7', 'ada@example.com', 'Ada', false);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $store = new MemoryActivityStore();
        $log = new ActivityLog($store, new MockClock('2026-11-09 10:00:00'), $requests, $tokens);

        $log->record('settings.changed', ['setting' => 'APP_NAME'], 'setting', 'APP_NAME');

        $entry = $store->recent(1)[0];
        self::assertSame('7', $entry->actorId);
        self::assertSame('Ada', $entry->actorName);
        self::assertSame('203.0.113.9', $entry->ipAddress);
        self::assertSame(RequestReference::of($request), $entry->requestReference);
    }

    public function testWithNobodySignedInItIsTheSystem(): void
    {
        $site = new Foundations();
        $site->activity->record('maintenance.ran');

        $entry = $site->activity->recent(1)[0];
        self::assertNull($entry->actorId);
        self::assertSame(Actor::system()->name, $entry->actorName);
    }

    public function testAnActionIsNamedAreaDotWhatHappened(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Foundations())->activity->record('Settings Changed');
    }

    public function testTheFileStoreKeepsEntriesForAnInstallationWithoutADatabase(): void
    {
        $folder = Foundations::folder();
        try {
            $store = new FileActivityStore($folder);
            $site = new Foundations(activityStore: $store);
            $site->activity->record('sign_in.signed_in', [], 'account', 'owner');
            $site->clock->modify('+1 minute');
            $site->activity->record('sign_in.signed_out', ['password' => 'never'], 'account', 'owner');
            $site->activity->record('other.thing');

            $found = $store->recent(10, subjectId: 'owner');
            self::assertSame(['sign_in.signed_out', 'sign_in.signed_in'], array_map(static fn ($entry): string => $entry->action, $found));
            self::assertSame(['password' => '••••'], $found[0]->details);
            self::assertSame(0600, fileperms($folder . '/activity.log') & 0777);
        } finally {
            Foundations::remove($folder);
        }
    }

    public function testEntriesFromBeforeAMomentAreRemovedFromMemoryAndFromAFile(): void
    {
        $folder = Foundations::folder();
        try {
            foreach (['memory' => new MemoryActivityStore(), 'file' => new FileActivityStore($folder)] as $kind => $store) {
                $site = new Foundations(activityStore: $store);
                $site->activity->record('area.old');
                $site->clock->modify('+2 days');
                $site->activity->record('area.new');
                if ($kind === 'file') {
                    file_put_contents($folder . '/activity.log', "not an entry\n", FILE_APPEND);
                }

                self::assertSame(1, $site->activity->removeBefore($site->clock->now()->modify('-1 day')), $kind);
                self::assertSame(['area.new'], array_map(static fn ($entry): string => $entry->action, $store->recent()), $kind);
                self::assertSame(0, $site->activity->removeBefore($site->clock->now()->modify('-1 day')), $kind . ': nothing more');
                if ($kind === 'file') {
                    self::assertStringContainsString("not an entry\n", (string) file_get_contents($folder . '/activity.log'), 'a line that cannot be dated is kept');
                    self::assertSame(0600, fileperms($folder . '/activity.log') & 0777);
                }
            }
        } finally {
            Foundations::remove($folder);
        }
    }

    public function testTheLogIsReadAPageAtATimeCountedAndListsTheActionsItHoldsInMemoryAndInAFile(): void
    {
        $folder = Foundations::folder();
        try {
            ActivityStoreContract::pages(new MemoryActivityStore(), 'memory');
            ActivityStoreContract::pages(new FileActivityStore($folder), 'file');
            self::assertSame([], (new FileActivityStore($folder . '/none'))->actions(), 'a log not written yet holds nothing');
            self::assertSame(0, (new FileActivityStore($folder . '/none'))->count());
        } finally {
            Foundations::remove($folder);
        }
    }

    public function testAnAccountForgottenIsNamedNowhereInTheLogInMemoryAndInAFile(): void
    {
        $folder = Foundations::folder();
        try {
            ActivityStoreContract::forgets(new MemoryActivityStore(), 'memory');
            ActivityStoreContract::forgets(new FileActivityStore($folder), 'file');
        } finally {
            Foundations::remove($folder);
        }
    }

    /**
     * Review finding 11: a rewrite of the file log (forgetting an account, removing old entries) that cannot be written,
     * as on a full disk, leaves the log as it was, never cut short or empty, and says so; it works once the disk has room.
     */
    public function testARewriteThatCannotBeWrittenLeavesTheLogAsItWas(): void
    {
        $folder = Foundations::folder();
        FullDisk::register($folder);
        try {
            $store = new FileActivityStore(FullDisk::SCHEME . '://log');
            $site = new Foundations(activityStore: $store);
            $site->activity->record('account.created', ['email' => 'anna@example.com'], 'account', '2', new Actor('2', 'Anna Example'));
            $site->clock->modify('+1 day');
            $site->activity->record('example.note_written', ['number' => 1]);
            $before = (string) file_get_contents($folder . '/log/activity.log');
            FullDisk::$full = true;

            foreach (['forgetting an account' => static fn (): int => $store->forgetAccount('2', AccountRecords::FORGOTTEN, ['anna@example.com']),
                'removing old entries' => static fn (): int => $store->removeBefore(new DateTimeImmutable('2026-11-10 00:00:00 UTC'))] as $what => $rewrite) {
                try {
                    $rewrite();
                    self::fail($what . ': a write that failed went unnoticed.');
                } catch (RuntimeException $failed) {
                    self::assertStringContainsString('as it was', $failed->getMessage(), $what);
                }
                self::assertSame($before, (string) file_get_contents($folder . '/log/activity.log'), $what . ': the log as it was.');
            }

            FullDisk::$full = false;
            self::assertSame(1, $store->forgetAccount('2', AccountRecords::FORGOTTEN, ['anna@example.com']));
            self::assertSame(0, $store->count(actorId: '2'));
            self::assertSame(2, $store->count(), 'Every entry still there.');
            self::assertSame([$folder . '/log/activity.log', $folder . '/log/activity.log.lock'], glob($folder . '/log/activity.log*'), 'No half-written file left beside it.');
        } finally {
            FullDisk::unregister();
            Foundations::remove($folder);
        }
    }

    public function testTheLogItselfPagesCountsAndListsItsActions(): void
    {
        $site = new Foundations();
        $site->activity->record('area.first');
        $site->activity->record('area.second');
        $site->activity->record('area.second');

        self::assertSame(['area.second'], array_map(static fn ($entry): string => $entry->action, $site->activity->recent(1, offset: 1)));
        self::assertSame(['area.second', 'area.second', 'area.first'], array_map(static fn ($entry): string => $entry->action, $site->activity->recent(10, offset: -5)), 'an offset below nothing is the start');
        self::assertSame(2, $site->activity->count('area.second'));
        self::assertSame(['area.first', 'area.second'], $site->activity->actions());
    }
}
