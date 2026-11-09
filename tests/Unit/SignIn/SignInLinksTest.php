<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\SignIn;

use CattoPHP\Links\FileLinkStore;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInAccounts;
use CattoPHP\SignIn\SignInLinks;
use CattoPHP\SignIn\SignInRules;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;

final class SignInLinksTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        Foundations::remove($this->folder);
    }

    public function testTheOwnerGetsALinkAndAnyOtherAddressNothing(): void
    {
        $site = new Foundations(['APP_SITE_MODE' => 'live']);
        $links = $site->signInLinks($this->folder);

        self::assertSame(SignInLinks::NO_ACCOUNT, $links->request('stranger@example.org', '203.0.113.5'));
        self::assertSame([], $site->transport->sent);
        self::assertSame(SignInLinks::SENT, $links->request(' Owner@Example.com ', '203.0.113.5'));
        $email = $site->transport->last();
        self::assertSame('owner@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Sign in to Example Site', $email->getSubject());
        self::assertMatchesRegularExpression('#https://example\.com/sign-in/link/[0-9a-f]{64}#', (string) $email->getTextBody());
        self::assertStringContainsString('for 30 minutes', (string) $email->getTextBody());
        self::assertStringContainsString('without your one-time code as well', (string) $email->getTextBody(), 'the owner is an administrator, who must use two-step');
    }

    public function testALinkWorksOnceAndOnlyWhileItLasts(): void
    {
        $site = new Foundations(['AUTH_MAGIC_LINK_TTL' => '15m']);
        $links = $site->signInLinks($this->folder);
        $owner = new SignInAccount('owner', 'owner@example.com', 'Owner', true);

        $token = $links->create($owner, '/domains');
        self::assertNotNull($links->usable($token), 'looking does not use it');
        self::assertNotNull($links->usable($token));
        $claimed = $links->claim($token);
        self::assertNotNull($claimed);
        self::assertSame('owner', $claimed->accountId);
        self::assertSame('/domains', $claimed->returnPath);
        self::assertNull($links->claim($token), 'used once');
        self::assertNull($links->usable($token));

        $late = $links->create($owner);
        $site->clock->modify('+15 minutes');
        self::assertNull($links->claim($late), 'run out');
        self::assertNull($links->claim('not-a-token'));
    }

    public function testOnlyTheLinksHashIsKept(): void
    {
        $site = new Foundations();
        $token = $site->signInLinks($this->folder)->create(new SignInAccount('owner', 'owner@example.com', 'Owner', true));

        $kept = (string) shell_exec('grep -rl ' . escapeshellarg($token) . ' ' . escapeshellarg($this->folder));
        self::assertSame('', trim($kept));
        self::assertStringContainsString(hash('sha256', $token), (string) file_get_contents($this->folder . '/links.json'), 'its hash, in the one file of links');
        self::assertSame(0600, fileperms($this->folder . '/links.json') & 0777);
    }

    public function testAnAddressMayAskOnlySoOften(): void
    {
        $site = new Foundations(['AUTH_REQUEST_LIMIT_EMAIL' => '2', 'AUTH_REQUEST_WINDOW' => '1h']);
        $links = $site->signInLinks($this->folder);

        self::assertSame(SignInLinks::SENT, $links->request('owner@example.com', '203.0.113.5'));
        self::assertSame(SignInLinks::SENT, $links->request('owner@example.com', '203.0.113.6'));
        self::assertSame(SignInLinks::TOO_MANY, $links->request('owner@example.com', '203.0.113.7'));
        self::assertCount(2, $site->transport->sent);
    }

    public function testANetworkAddressMayAskOnlySoOftenForAnyAddresses(): void
    {
        $site = new Foundations(['AUTH_REQUEST_LIMIT_IP' => '2']);
        $links = $site->signInLinks($this->folder);

        $links->request('a@example.org', '203.0.113.5');
        $links->request('b@example.org', '203.0.113.5');
        self::assertSame(SignInLinks::TOO_MANY, $links->request('owner@example.com', '203.0.113.5'));
    }

    public function testOnlyAdministratorsAndListedAddressesMaySignInUntilEveryoneMay(): void
    {
        $accounts = new class implements SignInAccounts {
            public function forEmail(string $email): SignInAccount
            {
                return new SignInAccount('id-' . $email, $email, $email, false);
            }

            public function forId(string $id): ?SignInAccount
            {
                return null;
            }
        };
        $site = new Foundations(['AUTH_SIGNIN_ALLOWED_EMAILS' => 'listed@example.org, other@example.org']);
        $links = $site->signInLinks($this->folder, $accounts);

        self::assertSame(SignInLinks::NOT_ALLOWED, $links->request('learner@example.org', '203.0.113.5'));
        self::assertSame(SignInLinks::SENT, $links->request('listed@example.org', '203.0.113.5'));
        self::assertSame('sign_in.link_refused', $site->activity->recent(1, subjectId: 'id-learner@example.org')[0]->action);

        $everyone = new Foundations(['AUTH_SIGNIN' => 'everyone']);
        self::assertSame(SignInLinks::SENT, $everyone->signInLinks($this->folder, $accounts)->request('learner@example.org', '203.0.113.5'));
    }

    public function testAnAddressMaySignInAsTheSettingsSay(): void
    {
        $limited = new SignInRules((new Foundations(['AUTH_SIGNIN' => 'ADMIN', 'AUTH_SIGNIN_ALLOWED_EMAILS' => "Listed@Example.org, not-an-address;\nother@example.org listed@example.org"]))->settings);
        self::assertTrue($limited->limited());
        self::assertSame(['listed@example.org', 'other@example.org'], $limited->allowedEmails(), 'small letters, each once, nothing that is not an address');
        self::assertTrue($limited->mayUse(' LISTED@example.org ', false));
        self::assertFalse($limited->mayUse('stranger@example.org', false));
        self::assertTrue($limited->mayUse('stranger@example.org', true), 'an administrator always may');
        $open = new SignInRules((new Foundations(['AUTH_SIGNIN' => 'everyone']))->settings);
        self::assertFalse($open->limited());
        self::assertTrue($open->mayUse('stranger@example.org', false));
        self::assertTrue((new SignInRules((new Foundations(['AUTH_SIGNIN' => 'nonsense']))->settings))->limited(), 'anything but EVERYONE is limited, the safe one');
    }

    public function testTwoStepIsRequiredAsTheSettingSays(): void
    {
        $person = new SignInAccount('7', 'ada@example.org', 'Ada', false);
        $administrator = new SignInAccount('1', 'owner@example.com', 'Owner', true);
        $rules = static fn (string $value): SignInRules => new SignInRules((new Foundations(['AUTH_TWO_STEP' => $value]))->settings);

        self::assertTrue($rules('ADMINS')->mustUseTwoStep($administrator));
        self::assertFalse($rules('ADMINS')->mustUseTwoStep($person));
        self::assertTrue($rules('EVERYONE')->mustUseTwoStep($person));
        self::assertFalse($rules('CHOICE')->mustUseTwoStep($administrator));
        self::assertTrue($rules('anything else')->mustUseTwoStep($administrator), 'anything else is the safe one');
    }

    /** @return iterable<string,array{0:?string,1:?string}> */
    public static function returnPaths(): iterable
    {
        yield 'a path here' => ['/domains?site=example.org', '/domains?site=example.org'];
        yield 'another site' => ['https://evil.example/', null];
        yield 'another site, without the scheme' => ['//evil.example/', null];
        yield 'a backslash trick' => ['/\\evil.example', null];
        yield 'nothing' => ['', null];
        yield 'a line break' => ["/x\r\nLocation: https://evil.example", null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('returnPaths')]
    public function testItGoesBackOnlyToAPageOnThisSite(?string $path, ?string $safe): void
    {
        self::assertSame($safe, SignInLinks::safePath($path));
    }

    public function testTheFileStoreForgetsOldLinks(): void
    {
        $site = new Foundations(['AUTH_MAGIC_LINK_TTL' => '1m']);
        $links = $site->signInLinks($this->folder);
        $owner = new SignInAccount('owner', 'owner@example.com', 'Owner', true);
        $old = $links->create($owner);
        $site->clock->modify('+2 minutes');
        self::assertNull($links->usable($old), 'it ran out');
        $links->create($owner);
        $store = new FileLinkStore($this->folder . '/links.json');
        self::assertNotNull($store->find(hash('sha256', $old), 'sign_in'), 'kept a week, so requests can still be counted');
        $site->clock->modify('+8 days');
        $links->create($owner);
        self::assertNull($store->find(hash('sha256', $old), 'sign_in'), 'then cleared when a link is made');
    }
}
