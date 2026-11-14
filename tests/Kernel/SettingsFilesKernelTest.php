<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Settings\Settings;
use CattoPHP\SignIn\SignInLengthStorageFactory;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The ready-made Settings page on an installation without a database, its records in files, as its administrator sees
 * it: saving and resetting into the storage folder, settings the project holds fixed shown and refused however they are
 * sent, hidden ones not there at all, the project's facts, a longest length of time, a file that cannot be read said to
 * be so, and a sign-in lasting as long as the setting says, the browser's session with it.
 */
final class SettingsFilesKernelTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0';

    private string $folder;
    private TestKernel $kernel;
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    public function testTheAdministratorSavesAndResetsIntoTheStorageFolder(): void
    {
        $this->start();
        $this->signIn();
        $page = $this->browser->request('GET', '/admin/settings');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringNotContainsString('keeps its settings in .env', $page->text(), 'The page can save here.');
        self::assertCount(1, $page->filter('form[action="/admin/settings/installation"] button[type="submit"]'), 'Save.');

        $this->browser->submit($this->form($page, '/admin/settings/installation'), ['APP_NAME' => 'Example Hall']);
        $page = $this->browser->followRedirect();
        self::assertStringContainsString('This installation: 1 setting saved.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('Saved here on', $this->help($page, 'setting-app-name'));
        $file = json_decode((string) file_get_contents($this->folder . '/storage/settings.json'), true);
        self::assertIsArray($file);
        self::assertSame('Example Hall', $file['settings']['APP_NAME']['value'] ?? null, 'Kept in the storage folder.');
        self::assertSame('Example Hall', $this->settings()->get('APP_NAME'));

        $this->browser->submit($page->filter('form#setting-app-name-reset')->form());
        $page = $this->browser->followRedirect();
        self::assertStringContainsString('This installation: Name is back to .env\'s value.', $page->filter('.cl-flash-stack')->text());
        self::assertSame('Example Site', $this->settings()->get('APP_NAME'));

        $log = (string) file_get_contents($this->folder . '/storage/activity.log');
        self::assertSame(1, substr_count($log, '"action":"settings.changed"'), 'Each change is in the activity log, as with a database.');
        self::assertSame(1, substr_count($log, '"action":"settings.reset"'));
    }

    public function testAFixedSettingIsShownAndRefusedHiddenOnesAreNotThereAndTheFactsAreShown(): void
    {
        $this->start(settingsConfig: ['fixed' => ['APP_URL', 'site_mode'], 'hidden' => ['mail']], facts: true);
        $this->signIn();
        $page = $this->browser->request('GET', '/admin/settings');

        $address = $page->filter('#setting-app-url');
        self::assertSame('https://example.com', $address->attr('value'), 'Shown with its value.');
        self::assertNotNull($address->attr('disabled'), 'Nothing to change it with.');
        self::assertStringContainsString('Fixed in this installation\'s configuration: it cannot be changed here. From .env (APP_URL).', $this->help($page, 'setting-app-url'));
        self::assertNull($page->filter('#setting-app-name')->attr('disabled'), 'The rest of its group can be changed.');
        self::assertSame('Fixed in this installation\'s configuration.', trim($page->filter('#settings-site_mode .cl-ui-accordion-description')->text()));
        self::assertCount(0, $page->filter('form[action="/admin/settings/site_mode"] button[type="submit"]'), 'A group fixed whole has no Save.');
        self::assertCount(0, $page->filter('#settings-mail'), 'A hidden group is not on the page.');
        self::assertCount(0, $page->filter('#setting-mailer-dsn'));

        $facts = $page->filter('#facts-protected_networks');
        self::assertCount(1, $facts);
        self::assertStringContainsString('Protected networks', $facts->text());
        self::assertStringContainsString('These networks are never refused.', $facts->text());
        self::assertSame(['Office', 'Tunnel'], $facts->filter('dt')->each(static fn (Crawler $term): string => $term->text()));
        self::assertSame(['192.0.2.0/24', '198.51.100.0/24'], $facts->filter('dd')->each(static fn (Crawler $value): string => $value->text()));
        self::assertCount(0, $facts->filter('form, input'), 'Facts are only shown.');

        // Sent all the same, by hand: refused.
        $token = (string) $page->filter('form[action="/admin/settings/installation"] input[name="_token"]')->attr('value');
        $this->browser->request('POST', '/admin/settings/installation', $this->form($page, '/admin/settings/installation')->getValues() + ['APP_URL' => 'https://attacker.example.net']);
        $page = $this->browser->followRedirect();
        self::assertStringContainsString('This installation was not saved', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('Public address is fixed in this installation\'s configuration and cannot be changed here.', $page->filter('#setting-app-url-error')->text());
        $this->browser->request('POST', '/admin/settings/site_mode', ['_token' => $token, 'APP_SITE_MODE' => 'demo']);
        self::assertStringContainsString('Demo or live is fixed in this installation\'s configuration', $this->browser->followRedirect()->filter('.cl-flash-stack')->text());
        $this->browser->request('POST', '/admin/settings/installation/reset/APP_URL', ['_token' => $token]);
        self::assertStringContainsString('This installation was not reset: Public address is fixed', $this->browser->followRedirect()->filter('.cl-flash-stack')->text());
        $this->browser->request('POST', '/admin/settings/mail', ['_token' => $token, 'MAIL_FROM_ADDRESS' => 'someone@example.net']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'A hidden group cannot be saved to.');

        $settings = $this->settings();
        self::assertSame(['https://example.com', 'live', 'site@example.com'], [$settings->get('APP_URL'), $settings->get('APP_SITE_MODE'), $settings->get('MAIL_FROM_ADDRESS')]);
        self::assertFileDoesNotExist($this->folder . '/storage/settings.json', 'Nothing was saved.');
    }

    public function testALengthOfTimeLongerThanItsLongestIsRefusedInWords(): void
    {
        $this->start();
        $this->signIn();
        $page = $this->browser->request('GET', '/admin/settings');
        self::assertStringContainsString('It may be from 5 minutes to 365 days.', $this->help($page, 'setting-auth-session-ttl'));
        $this->browser->submit($this->form($page, '/admin/settings/sign_in'), ['AUTH_SESSION_TTL' => '400d']);
        $page = $this->browser->followRedirect();
        self::assertSame('How long a sign-in lasts must be at most 365 days.', trim($page->filter('#setting-auth-session-ttl-error')->text()));
        self::assertSame('400d', $page->filter('#setting-auth-session-ttl')->attr('value'), 'As typed.');
        self::assertSame('1d', $this->settings()->get('AUTH_SESSION_TTL'));
    }

    public function testSavedSettingsThatCannotBeReadAreSaidToBeSoAndTheStartingValuesApply(): void
    {
        mkdir($this->folder . '/storage', 0700, true);
        file_put_contents($this->folder . '/storage/settings.json', 'not what CattoPHP writes');
        $this->start();
        $this->signIn();
        $page = $this->browser->request('GET', '/admin/settings');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'The application goes on.');
        self::assertStringContainsString('Saved settings could not be read', $page->filter('.cl-ui-notice--warning')->text());
        self::assertStringContainsString('The file of saved settings is damaged', $page->filter('.cl-ui-notice--warning')->text());
        self::assertSame('Example Site', $this->settings()->get('APP_NAME'));

        $this->browser->submit($this->form($page, '/admin/settings/installation'), ['APP_NAME' => 'Example Hall']);
        $page = $this->browser->followRedirect();
        self::assertCount(0, $page->filter('.cl-ui-notice--warning'), 'Saved afresh.');
        self::assertSame('Example Hall', $this->settings()->get('APP_NAME'));
        self::assertCount(1, glob($this->folder . '/storage/settings.json.damaged-*') ?: [], 'The unreadable file kept beside it.');
    }

    public function testASignInLastsAsLongAsThePersonComesBackOrLeavesAPageOpenWithinTheLengthAndItsCookieWithIt(): void
    {
        $this->start();
        $this->browser->request('GET', '/sign-in');
        $before = $this->sessionCookie();
        self::assertNotNull($before, 'A browser nobody has signed in on has its session cookie too.');
        self::assertSame(0, $before->getExpiresTime(), 'Until someone signs in, it ends when the browser is closed.');
        self::assertTrue($before->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $before->getSameSite());
        $cookie = $this->signIn();
        self::assertNotNull($cookie, 'The session cookie is sent again as the sign-in finishes.');
        self::assertEqualsWithDelta($this->clock()->now()->getTimestamp() + 86400, $cookie->getExpiresTime(), 2, 'It lasts a day, past closing the browser.');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite(), 'Its flags as they were.');

        // A visit every twenty hours for four days: still signed in, the cookie sent again each time, a day on.
        for ($visit = 1; $visit <= 5; ++$visit) {
            $this->clock()->modify('+20 hours');
            $this->browser->request('GET', '/');
            self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'Signed in at visit ' . $visit . ', ' . (20 * $visit) . ' hours after signing in.');
            $sent = $this->sessionCookie();
            self::assertNotNull($sent, 'Visit ' . $visit . ' sends the cookie again.');
            self::assertSame($this->clock()->now()->getTimestamp() + 86400, $sent->getExpiresTime(), 'Visit ' . $visit . ': a day from now.');
        }

        // A page left open, refreshing itself every twenty hours here, keeps the person signed in for three days more.
        for ($refresh = 1; $refresh <= 4; ++$refresh) {
            $this->clock()->modify('+20 hours');
            $this->browser->request('GET', '/admin/activity', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_X_LIVE_REFRESH' => '1']);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'Signed in at refresh ' . $refresh . '.');
            self::assertSame($this->clock()->now()->getTimestamp() + 86400, $this->sessionCookie()?->getExpiresTime(), 'Refresh ' . $refresh . ' moves the cookie on too.');
        }
        // Fifteen seconds later another refresh: too soon to move the end, so nothing is written and no cookie sent.
        $this->clock()->modify('+15 seconds');
        $this->browser->request('GET', '/admin/activity', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_X_LIVE_REFRESH' => '1']);
        self::assertNull($this->sessionCookie(), 'Not on every request.');
        // The page closed, a day and five hours away: signed out.
        $this->clock()->modify('+29 hours');
        $this->browser->request('GET', '/');
        self::assertSame(303, $this->browser->getResponse()->getStatusCode(), 'Signed out after staying away longer than a day.');
        self::assertStringStartsWith('/sign-in', (string) $this->browser->getResponse()->headers->get('Location'));

        // Lengthened to 30 days with no visit since: it still ends a day after the last visit.
        $this->signIn();
        $this->settings()->set('AUTH_SESSION_TTL', '30d');
        $this->clock()->modify('+1 day +1 minute');
        $this->browser->request('GET', '/');
        self::assertSame(303, $this->browser->getResponse()->getStatusCode(), 'A longer setting waits for the next visit.');

        // Lengthened, then a visit: from then on 30 days.
        $this->settings()->set('AUTH_SESSION_TTL', '1d');
        $this->signIn();
        $this->settings()->set('AUTH_SESSION_TTL', '30d');
        $this->clock()->modify('+12 hours');
        $this->browser->request('GET', '/');
        self::assertEqualsWithDelta($this->clock()->now()->getTimestamp() + 30 * 86400, $this->sessionCookie()?->getExpiresTime(), 2, 'The visit gives it 30 days.');
        $this->clock()->modify('+20 days');
        $this->browser->request('GET', '/');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'Signed in twenty days after the visit.');

        // Shortened to a day: two days after the last visit, signed out at once.
        $this->settings()->set('AUTH_SESSION_TTL', '1d');
        $this->clock()->modify('+2 days');
        $this->browser->request('GET', '/');
        self::assertSame(303, $this->browser->getResponse()->getStatusCode(), 'A shorter setting applies at once, from the last visit.');

        self::assertInstanceOf(SignInLengthStorageFactory::class, $this->services()->get('session.storage.factory'),
            'Each request\'s session storage is given the length too, so the stored session is kept as long.');
    }

    private function sessionCookie(): ?Cookie
    {
        foreach ($this->browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'MOCKSESSID' && !$cookie->isCleared()) {
                return $cookie;
            }
        }

        return null;
    }

    /** @param array{fixed?:list<string>,hidden?:list<string>} $settingsConfig */
    private function start(array $settingsConfig = [], bool $facts = false): void
    {
        // A starting value of this test's own: a container dumped writes paths relative to its own folder, so two kernels
        // configured alike in one run would share one container class, the second reading the first test's files.
        $this->kernel = new TestKernel($this->folder, null, ['AUTH_TWO_STEP' => 'CHOICE', 'APP_DEMO_HEADING' => 'Demo ' . basename($this->folder)],
            stockPages: true, clock: 'now', settingsConfig: $settingsConfig, settingsFacts: $facts);
        $this->kernel->boot();
        $browser = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $this->browser = $browser;
        $this->browser->disableReboot();
        $this->browser->setServerParameter('HTTP_USER_AGENT', self::BROWSER);
        $this->browser->setServerParameter('REMOTE_ADDR', '203.0.113.9');
    }

    /** Signs the administrator in by the emailed link; the session cookie the last step sent, if it sent one. */
    private function signIn(): ?Cookie
    {
        $page = $this->browser->request('GET', '/sign-in');
        $this->browser->submit($page->filter('form')->form(['email' => 'owner@example.com']));
        $transport = $this->kernel->getContainer()->get(RecordingTransport::class);
        self::assertInstanceOf(RecordingTransport::class, $transport);
        self::assertSame(1, preg_match('#https://example\.com(/sign-in/link/[0-9a-f]{64})#', (string) $transport->last()->getTextBody(), $found));
        $page = $this->browser->request('GET', $found[1] ?? '');
        $this->browser->submit($page->filter('form')->form());
        self::assertSame('/', $this->browser->getResponse()->headers->get('Location'), 'Signed in.');
        foreach ($this->browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'MOCKSESSID') {
                return $cookie;
            }
        }

        return null;
    }

    private function settings(): Settings
    {
        $settings = $this->services()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);
        $settings->refresh();

        return $settings;
    }

    private function services(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function clock(): MockClock
    {
        $clock = $this->kernel->getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    private function form(Crawler $page, string $action): \Symfony\Component\DomCrawler\Form
    {
        return $page->filter('form[action="' . $action . '"]')->form();
    }

    private function help(Crawler $page, string $field): string
    {
        return $page->filter('#' . $field . '-help')->text();
    }
}
