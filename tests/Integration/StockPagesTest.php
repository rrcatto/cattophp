<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Account\AccountRecords;
use CattoPHP\Account\SecurityController;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Installation\Installation;
use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Security\TwoStep\TwoStep;
use CattoPHP\Security\TwoStep\TwoStepStore;
use CattoPHP\Settings\Settings;
use CattoPHP\Tests\Kernel\App\ExampleAccounts;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * CattoPHP's own pages in a project that imports their route files, its records in the database: Administration's
 * Settings, Roles and Activity log behind CattoPHP's permissions, a person's Sign-in and security (two-step by choice,
 * where they are signed in, End and End all others), and joining by signing in for the first time.
 */
final class StockPagesTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Firefox/144.0';

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private string $folder;
    private TestKernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        Foundations::remove($this->folder);
        restore_exception_handler();
        parent::tearDown();
    }

    public function testTheRouteFilesGiveEachPageItsAddress(): void
    {
        $this->start();
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        foreach ([
            'cattophp_admin_settings' => ['/admin/settings', 'GET'], 'cattophp_admin_settings_save' => ['/admin/settings/{group}', 'POST'],
            'cattophp_admin_settings_reset_group' => ['/admin/settings/{group}/reset', 'POST'], 'cattophp_admin_settings_reset' => ['/admin/settings/{group}/reset/{name}', 'POST'],
            'cattophp_admin_roles' => ['/admin/roles', 'GET'], 'cattophp_admin_role_make' => ['/admin/roles', 'POST'], 'cattophp_admin_role_new' => ['/admin/roles/new', 'GET'],
            'cattophp_admin_role' => ['/admin/roles/{key}', 'GET'], 'cattophp_admin_role_save' => ['/admin/roles/{key}', 'POST'],
            'cattophp_admin_role_delete' => ['/admin/roles/{key}/delete', 'POST'], 'cattophp_admin_role_give' => ['/admin/roles/{key}/holders', 'POST'],
            'cattophp_admin_role_take' => ['/admin/roles/{key}/holders/take-away', 'POST'], 'cattophp_admin_activity' => ['/admin/activity', 'GET'],
            'cattophp_account_security' => ['/account/security', 'GET'], 'cattophp_account_two_step' => ['/account/security/two-step', 'GET'],
            'cattophp_account_two_step_set_up' => ['/account/security/two-step', 'POST'], 'cattophp_account_two_step_off' => ['/account/security/two-step/off', 'POST'],
            'cattophp_account_recovery_codes' => ['/account/security/recovery-codes', 'GET'], 'cattophp_account_recovery_codes_renew' => ['/account/security/recovery-codes', 'POST'],
            'cattophp_account_sign_in_end' => ['/account/security/sign-ins/end', 'POST'], 'cattophp_account_sign_ins_end_others' => ['/account/security/sign-ins/end-others', 'POST'],
        ] as $name => [$path, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name);
            self::assertSame([$path, [$method]], [$route->getPath(), $route->getMethods()], $name);
        }
        $router->getContext()->setMethod('GET');
        self::assertSame('cattophp_admin_role_new', $router->match('/admin/roles/new')['_route'], 'New is no role\'s key.');
    }

    public function testAStrangerIsSentToSignInAndSomeoneWithoutThePermissionIsRefused(): void
    {
        $this->start();
        $stranger = $this->browser();
        $stranger->request('GET', '/admin/settings');
        self::assertSame(303, $stranger->getResponse()->getStatusCode());
        self::assertSame('/sign-in?return=/admin/settings', $stranger->getResponse()->headers->get('Location'));

        $anna = $this->signedIn('anna@example.com');
        foreach (['/admin/settings', '/admin/roles', '/admin/roles/ADMIN', '/admin/activity'] as $page) {
            $anna->request('GET', $page);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $page . ': Anna holds no role that allows it.');
        }
        $anna->request('POST', '/admin/settings/installation', ['APP_NAME' => 'Taken over']);
        self::assertSame(403, $anna->getResponse()->getStatusCode());
        self::assertNotSame('Taken over', $this->settings()->get('APP_NAME'));
    }

    public function testSettingsSayWhereEachValueComesFromSaveResetAndNeverShowASecret(): void
    {
        $this->start();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/admin/settings');
        self::assertSame(200, $owner->getResponse()->getStatusCode());
        self::assertSame('Settings', trim($page->filter('h1')->text()));
        foreach (['installation', 'mail', 'sign_in'] as $group) {
            self::assertCount(1, $page->filter('#settings-' . $group), 'Every group of the catalogue: ' . $group);
        }
        self::assertStringContainsString('From .env (APP_NAME).', $this->help($page, 'setting-app-name'));
        $dsn = $page->filter('#setting-mailer-dsn');
        self::assertSame('password', $dsn->attr('type'));
        self::assertSame('', (string) $dsn->attr('value'));
        self::assertNull($dsn->attr('placeholder'), 'No secret yet: no asterisks pretending there is one.');
        self::assertStringContainsString('None is set.', $this->help($page, 'setting-mailer-dsn'));

        // A secret saved: shown as asterisks from then on, never its value, and kept when the asterisks are left.
        $owner->submit($this->form($page, '/admin/settings/mail'), ['MAILER_DSN' => 'smtp://site:hunter2@mail.example.com:587', 'MAIL_FROM_ADDRESS' => 'site@example.com']);
        self::assertSame('/admin/settings?open=mail#settings-mail', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('Mail: 1 setting saved.', $page->filter('.cl-flash-stack')->text());
        self::assertSame('************', $page->filter('#setting-mailer-dsn')->attr('placeholder'));
        self::assertSame('', (string) $page->filter('#setting-mailer-dsn')->attr('value'));
        self::assertStringContainsString('Saved, encrypted and never shown. Leave the asterisks to keep it, or type a new one.', $this->help($page, 'setting-mailer-dsn'));
        self::assertStringNotContainsString('hunter2', (string) $owner->getResponse()->getContent());
        self::assertStringNotContainsString('hunter2', (string) $this->connection->fetchOne("SELECT value FROM cattophp_settings WHERE name = 'MAILER_DSN'"), 'Stored encrypted.');
        $untouched = $this->form($page, '/admin/settings/mail');
        self::assertSame('', $untouched->getValues()['MAILER_DSN'] ?? null, 'The asterisks are the field\'s placeholder: left as they are, it sends nothing.');
        $owner->submit($untouched);
        self::assertStringContainsString('Mail: nothing had changed', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame('smtp://site:hunter2@mail.example.com:587', $this->settings()->get('MAILER_DSN'), 'Saving the group without touching the secret keeps it.');
        $owner->submit($this->form($page, '/admin/settings/mail'), ['MAILER_DSN' => '************']);
        $owner->followRedirect();
        self::assertSame('smtp://site:hunter2@mail.example.com:587', $this->settings()->get('MAILER_DSN'), 'The asterisks typed out are not a new secret either.');

        // Refused: nothing saved, the form back as typed, the secret typed never kept to be shown again.
        $page = $owner->request('GET', '/admin/settings');
        $owner->submit($this->form($page, '/admin/settings/mail'), ['MAILER_DSN' => 'smtp://site:topsecret@mail.example.com', 'MAIL_FROM_ADDRESS' => 'not an address']);
        $page = $owner->followRedirect();
        self::assertStringContainsString('Mail was not saved', $page->filter('.cl-flash-stack')->text());
        self::assertSame('not an address', $page->filter('#setting-mail-from-address')->attr('value'), 'As typed.');
        self::assertNotSame('', trim($page->filter('#setting-mail-from-address-error')->text()), 'Why, beside the field.');
        self::assertStringNotContainsString('topsecret', (string) $owner->getResponse()->getContent());
        self::assertSame('smtp://site:hunter2@mail.example.com:587', $this->settings()->get('MAILER_DSN'));

        // Saved, then Reset: back to .env, and every change in the activity log, by whom.
        $page = $owner->request('GET', '/admin/settings');
        $owner->submit($this->form($page, '/admin/settings/installation'), ['APP_NAME' => 'Example Hall']);
        $page = $owner->followRedirect();
        self::assertStringContainsString('Saved here on', $this->help($page, 'setting-app-name'));
        self::assertStringContainsString('by Olive Owner, overriding .env. Reset goes back to .env\'s "Example Site".', $this->help($page, 'setting-app-name'));
        self::assertSame('Reset to .env', trim($page->filter('button[form="setting-app-name-reset"]')->text()));
        self::assertSame('Put Name back to .env\'s value? What is saved here goes.', $page->filter('button[form="setting-app-name-reset"]')->attr('data-confirm'));
        $owner->submit($page->filter('form#setting-app-name-reset')->form());
        $page = $owner->followRedirect();
        self::assertStringContainsString('This installation: Name is back to .env\'s value.', $page->filter('.cl-flash-stack')->text());
        self::assertSame('Example Site', $this->settings()->get('APP_NAME'));
        self::assertSame(['1'], array_values(array_unique($this->connection->fetchFirstColumn("SELECT actor_id FROM cattophp_activity WHERE action IN ('settings.changed', 'settings.reset')"))));
    }

    /**
     * Review finding 2: Enter in a field submits its form through the form's first submit button, so the Save form holds
     * one submit button, Save; each Reset (and Reset every setting here) is a form of its own after it, its button beside
     * its field naming it with form=, asking to be confirmed. Enter saves what was typed and resets nothing.
     */
    public function testEnterInAFieldSavesTheGroupAndEachResetIsAFormOfItsOwn(): void
    {
        $this->start();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/admin/settings');
        $owner->submit($this->form($page, '/admin/settings/installation'), ['APP_NAME' => 'Example Hall', 'APP_ORGANISATION' => 'Example Trust']);
        $page = $owner->followRedirect();

        $save = $page->filter('form[action="/admin/settings/installation"]');
        $owned = $save->filter('button[type="submit"]')->reduce(static fn (Crawler $button): bool => $button->attr('form') === null);
        self::assertSame(['Save these settings'], $owned->each(static fn (Crawler $button): string => trim($button->text())), 'Its one submit button, the one Enter uses: Save.');
        self::assertCount(0, $save->filter('button[formaction]'), 'No button sends the Save form anywhere else.');
        foreach (['setting-app-name-reset' => '/admin/settings/installation/reset/APP_NAME', 'setting-app-organisation-reset' => '/admin/settings/installation/reset/APP_ORGANISATION',
            'settings-installation-reset' => '/admin/settings/installation/reset'] as $id => $action) {
            $reset = $page->filter('form#' . $id);
            self::assertSame($action, $reset->attr('action'), $id);
            self::assertCount(1, $reset->filter('input[name="_token"]'), $id);
            self::assertNotSame('', (string) $page->filter('button[form="' . $id . '"]')->attr('data-confirm'), $id . ': asked to be confirmed.');
        }

        self::assertCount(0, $page->filter('form form'), 'No form inside another.');
        // Enter in Name with a new name typed: the group is saved, nothing reset.
        $owner->submit($save->form(['APP_NAME' => 'Example Lodge']));
        self::assertStringContainsString('This installation: 1 setting saved.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame(['Example Lodge', 'Example Trust'], [$this->settings()->get('APP_NAME'), $this->settings()->get('APP_ORGANISATION')]);
    }

    /**
     * Review findings 1 and 9: a choice .env writes in another case is shown selected as the choice it names, a time zone
     * PHP knows by an older name and a value that is no choice at all are shown selected as they stand, and saving each
     * group for another reason keeps every one of them as .env gives it, never the first option in its place.
     */
    public function testAValueFromEnvIsShownSelectedAndKeptThroughASaveOfItsGroup(): void
    {
        $this->start(['AUTH_TWO_STEP' => 'choice', 'AUTH_SIGNIN' => 'everyone', 'APP_TIMEZONE' => 'Asia/Calcutta', 'APP_SITE_MODE' => 'staging']);
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/admin/settings');
        $selected = static fn (string $id): array => [(string) $page->filter('#' . $id . ' option[selected]')->attr('value'), trim($page->filter('#' . $id . ' option[selected]')->text())];
        self::assertSame(['CHOICE', 'Each person\'s choice'], $selected('setting-auth-two-step'));
        self::assertSame(['EVERYONE', 'Everyone with an account'], $selected('setting-auth-signin'));
        self::assertSame(['Asia/Calcutta', 'Asia/Calcutta (as .env gives it)'], $selected('setting-app-timezone'));
        self::assertSame(['staging', 'staging (as .env gives it, not one of these)'], $selected('setting-app-site-mode'));
        self::assertCount(1, $page->filter('#setting-app-site-mode option[selected]'), 'One option selected.');

        $owner->submit($this->form($page, '/admin/settings/sign_in'), ['AUTH_SESSION_TTL' => '2d']);
        $page = $owner->followRedirect();
        $owner->submit($this->form($page, '/admin/settings/installation'), ['APP_NAME' => 'Example Hall']);
        $page = $owner->followRedirect();
        $owner->submit($this->form($page, '/admin/settings/site_mode'), ['APP_DEMO_HEADING' => 'Trying it out']);
        $owner->followRedirect();

        $settings = $this->settings();
        self::assertSame(['CHOICE', 'EVERYONE', 'Asia/Calcutta', 'staging'], [$settings->get('AUTH_TWO_STEP'), $settings->get('AUTH_SIGNIN'), $settings->get('APP_TIMEZONE'), $settings->get('APP_SITE_MODE')]);
        foreach (['AUTH_TWO_STEP', 'AUTH_SIGNIN', 'APP_TIMEZONE', 'APP_SITE_MODE'] as $name) {
            self::assertFalse($settings->isOverridden($name), $name . ' is still .env\'s.');
        }
        self::assertSame(['2d', 'Example Hall', 'Trying it out'], [$settings->get('AUTH_SESSION_TTL'), $settings->get('APP_NAME'), $settings->get('APP_DEMO_HEADING')], 'What was changed is saved.');
        $installation = $this->container()->get(Installation::class);
        self::assertInstanceOf(Installation::class, $installation);
        self::assertSame('Asia/Calcutta', $installation->timeZone()->getName());
    }

    public function testRolesAreMadeGivenTakenAwayAndDeleted(): void
    {
        $this->start();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/admin/roles');
        self::assertSame(200, $owner->getResponse()->getStatusCode());
        self::assertStringContainsString('Every permission', $page->filter('table')->text());

        $page = $owner->request('GET', '/admin/roles/new');
        self::assertCount(1, $page->filter('form[action="/admin/roles"] input[type="checkbox"][name="permissions[]"][value="PLATFORM.ACTIVITY.VIEW"]'), 'Each permission a feature card to tick.');
        self::assertCount(1, $page->filter('input[type="checkbox"][value="SYSTEM.SETTINGS.MANAGE"][disabled]'), 'One for administrators alone is shown but cannot be ticked.');
        $owner->request('POST', '/admin/roles', ['_token' => $this->token($page), 'key' => 'editor', 'name' => 'Editor', 'permissions' => ['PLATFORM.ACTIVITY.VIEW']]);
        self::assertSame('/admin/roles/EDITOR', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('The role Editor is made.', $page->filter('.cl-flash-stack')->text());

        $owner->submit($page->filter('form[action="/admin/roles/EDITOR/holders"]')->form(['email' => 'nobody@example.org']));
        $page = $owner->followRedirect();
        self::assertStringContainsString('No account has that email address', $page->filter('.cl-flash-stack')->text());
        self::assertSame('nobody@example.org', $page->filter('#give-email')->attr('value'), 'Back as typed.');
        $owner->submit($page->filter('form[action="/admin/roles/EDITOR/holders"]')->form(['email' => 'anna@example.com']));
        $page = $owner->followRedirect();
        self::assertStringContainsString('Anna Example holds the role Editor now.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('anna@example.com', $page->filter('#holders')->text());

        // What the role allows, Anna may now do; nothing more.
        $anna = $this->signedIn('anna@example.com');
        $anna->request('GET', '/admin/activity');
        self::assertSame(200, $anna->getResponse()->getStatusCode());
        $anna->request('GET', '/admin/settings');
        self::assertSame(403, $anna->getResponse()->getStatusCode());

        $owner->submit($page->filter('form[action="/admin/roles/EDITOR/holders/take-away"]')->form());
        $page = $owner->followRedirect();
        self::assertStringContainsString('Anna Example no longer holds the role Editor.', $page->filter('.cl-flash-stack')->text());
        $owner->submit($page->filter('form[action="/admin/roles/EDITOR/delete"]')->form());
        $owner->followRedirect();
        self::assertSame(['access.role_made', 'access.role_given', 'access.role_taken', 'access.role_deleted'], $this->connection->fetchFirstColumn("SELECT action FROM cattophp_activity WHERE action LIKE 'access.%' ORDER BY id"));
    }

    public function testTheActivityLogIsReadAPageAtATimeFilteredByTheActionsItHolds(): void
    {
        $this->start();
        $owner = $this->signedIn('owner@example.com');
        $log = $this->container()->get(ActivityLog::class);
        self::assertInstanceOf(ActivityLog::class, $log);
        foreach (range(1, 30) as $number) {
            $log->record('example.note_written', ['number' => $number, 'password' => 'never']);
        }

        $page = $owner->request('GET', '/admin/activity');
        self::assertSame(200, $owner->getResponse()->getStatusCode());
        self::assertCount(25, $page->filter('#activity-results tbody tr'), 'A page of the default size.');
        self::assertCount(2, $page->filter('#activity-results .pagination-row'), 'Its pager, above and below.');
        $options = $page->filter('#activity-action option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertSame('', $options[0] ?? null, 'Everything first.');
        self::assertSame(['', 'example.note_written', 'sign_in.link_sent', 'sign_in.signed_in'], self::sorted($options), 'Only the actions the log holds.');
        self::assertStringContainsString('Signed in', $page->filter('#activity-action')->text(), 'In words.');
        self::assertStringNotContainsString('never', $page->filter('#activity-results')->text(), 'Never a secret.');

        $page = $owner->request('GET', '/admin/activity?action=example.note_written&activity_page=2');
        self::assertCount(5, $page->filter('#activity-results tbody tr'), 'Page two of thirty: five.');
        self::assertStringContainsString('Number: 5', $page->filter('#activity-results tbody tr')->first()->text(), 'Newest first: page two starts at the fifth oldest.');
        $page = $owner->request('GET', '/admin/activity?action=no.such_thing');
        self::assertSame('What happened', trim($page->filter('#log-heading')->text()), 'An action the log does not hold is no filter.');
        self::assertSame('Everything', trim($page->filter('#activity-action option[selected]')->text()));
    }

    /**
     * Details in words: a key turned into words, an ISO 8601 time in the installation's time zone and in words, a date in
     * words, and the project's own words (ExampleDetailWords, a DetailWords) where it has them.
     */
    public function testTheLogsDetailsAreInWordsAndAProjectWordsItsOwn(): void
    {
        $this->start(['APP_TIMEZONE' => 'Africa/Johannesburg']);
        $owner = $this->signedIn('owner@example.com');
        $log = $this->container()->get(ActivityLog::class);
        self::assertInstanceOf(ActivityLog::class, $log);
        $log->record('example.note_written', ['due_at' => '2026-11-07T05:01:56+00:00', 'condition' => 'needs_repair', 'lent_on' => '2026-11-01', 'kind' => 'hand_tool', 'returned' => false]);
        $log->record('example.other_thing', ['due_at' => '2026-11-07T05:01:56Z', 'condition' => 'needs_repair', 'tags' => ['garden_tool', 'heavy'], 'code' => 'SD4JR2UH'], 'garden_tool', 'k7qxm2pa');

        $rows = $owner->request('GET', '/admin/activity')->filter('#activity-results tbody tr');
        $other = $rows->eq(0)->filter('td')->eq(4)->filter('div')->each(static fn (Crawler $line): string => $line->text());
        self::assertSame('Garden tool k7qxm2pa', $rows->eq(0)->filter('td')->eq(3)->text(), 'A project\'s own subject: its type in words and its reference.');
        self::assertSame('Details', $rows->eq(0)->filter('td')->eq(4)->attr('data-label'), 'Each cell names its column, for the cards on a phone.');
        $written = $rows->eq(1)->filter('td')->eq(4)->filter('div')->each(static fn (Crawler $line): string => $line->text());
        // In the order the database keeps a record's details (PostgreSQL's jsonb orders its keys).
        self::assertEqualsCanonicalizing(['Due back: 7 November 2026 at 07:01', 'Condition: Needs a repair first', 'Lent on: 1 November 2026', 'Kind: Hand tool', 'Returned: no'], $written, 'The project\'s words where it has them, the log\'s own for the rest.');
        self::assertEqualsCanonicalizing(['Due at: 7 November 2026 at 07:01', 'Condition: Needs repair', 'Tags: Garden tool, heavy', 'Code: SD4JR2UH'], $other, 'Another action keeps the log\'s own words.');
    }

    /**
     * The About column links to the record where the project's SubjectLinks (ExampleSubjectLinks) give its address,
     * escaped; an entry it has no address for, or an address that is not one, stays in words alone.
     */
    public function testWhatAnEntryIsAboutLinksToItsRecordWhereTheProjectGivesAnAddress(): void
    {
        $this->start();
        $owner = $this->signedIn('owner@example.com');
        $log = $this->container()->get(ActivityLog::class);
        self::assertInstanceOf(ActivityLog::class, $log);
        $log->record('example.other_thing', [], 'garden_tool', 'broken00');
        $log->record('example.other_thing', [], 'garden_tool', 'lost0000');
        $log->record('example.other_thing', [], 'garden_tool', 'k7qxm2pa');

        $page = $owner->request('GET', '/admin/activity?action=example.other_thing');
        $about = $page->filter('#activity-results tbody tr')->each(static fn (Crawler $row): Crawler => $row->filter('td')->eq(3));

        self::assertCount(3, $about);
        self::assertSame('Garden tool k7qxm2pa', $about[0]->text());
        self::assertSame('/garden-tools/k7qxm2pa?from=log&a="b"', $about[0]->filter('a')->attr('href'), 'Its record, linked.');
        self::assertStringContainsString('<a href="/garden-tools/k7qxm2pa?from=log&amp;a=&quot;b&quot;">Garden tool k7qxm2pa</a>', (string) $owner->getResponse()->getContent(), 'Escaped.');
        self::assertCount(0, $about[1]->filter('a'), 'No address: words alone.');
        self::assertSame('Garden tool lost0000', $about[1]->text());
        self::assertCount(0, $about[2]->filter('a'), 'An address that is neither a path on the site nor a web address is never linked.');
        self::assertSame('Garden tool broken00', $about[2]->text());
        // The accounts signing in made entries about: the project gives no address for them, so they stay words.
        self::assertCount(1, $owner->request('GET', '/admin/activity')->filter('#activity-results tbody td[data-label="About"] a'));
    }

    public function testAnAccountForgottenIsADeletedAccountInTheLogNeverTheSystem(): void
    {
        $this->start();
        $owner = $this->signedIn('owner@example.com');
        $log = $this->container()->get(ActivityLog::class);
        self::assertInstanceOf(ActivityLog::class, $log);
        $log->record('example.note_written', [], 'account', '2', new Actor('2', 'Anna Example'));
        $records = $this->container()->get(AccountRecords::class);
        self::assertInstanceOf(AccountRecords::class, $records);
        $records->forget('2', new Actor('1', 'Olive Owner'));

        $row = $owner->request('GET', '/admin/activity?action=example.note_written')->filter('#activity-results tbody tr')->first()->text();

        self::assertSame(2, substr_count($row, AccountRecords::FORGOTTEN), 'Who did it, and what it was about.');
        self::assertStringNotContainsString('The system', $row);
    }

    public function testTwoStepSignInByChoiceIsSetUpRenewedAndTurnedOff(): void
    {
        $this->start(['AUTH_TWO_STEP' => 'CHOICE']);
        $anna = $this->signedIn('anna@example.com');
        $page = $anna->request('GET', '/account/security');
        self::assertSame(200, $anna->getResponse()->getStatusCode());
        self::assertStringContainsString('Off', $page->filter('#two-step dl')->text());
        self::assertStringContainsString('You do', $page->filter('#two-step dl')->text());

        $page = $anna->request('GET', '/account/security/two-step');
        self::assertCount(1, $page->filter('.cl-two-step-qr svg'), 'The QR code, drawn on the server.');
        $secret = str_replace(' ', '', $page->filter('[data-two-step-key]')->text());
        $anna->submit($page->filter('form')->form(['code' => '000000']));
        $page = $anna->followRedirect();
        self::assertStringContainsString('That code is not right, so two-step sign-in is still off.', $page->filter('.cl-flash-stack')->text());
        self::assertSame('', (string) $page->filter('#set-up-code')->attr('value'), 'A code typed is never kept to be shown again.');
        $anna->submit($page->filter('form')->form(['code' => OneTimeCode::codeAt($secret, time())]));
        self::assertSame('/account/security/recovery-codes', $anna->getResponse()->headers->get('Location'));
        $codes = $anna->followRedirect()->filter('.cl-recovery-codes code')->each(static fn (Crawler $code): string => $code->text());
        self::assertCount(8, $codes);
        self::assertSame(['2', 'Anna Example'], [(string) $this->connection->fetchOne("SELECT actor_id FROM cattophp_activity WHERE action = 'two_step.set_up'"), (string) $this->connection->fetchOne("SELECT actor_name FROM cattophp_activity WHERE action = 'two_step.set_up'")], 'Her own doing, by her name.');

        $page = $anna->request('GET', '/account/security');
        self::assertStringContainsString('On since', $page->filter('#two-step dl')->text());
        self::assertStringContainsString('8', $page->filter('#two-step dl')->text(), 'Recovery codes left.');
        $anna->submit($page->filter('form[action="/account/security/recovery-codes"]')->form(['code' => '123456']));
        $page = $anna->followRedirect();
        self::assertStringContainsString('your recovery codes are as they were', $page->filter('.cl-flash-stack')->text());
        $anna->submit($page->filter('form[action="/account/security/recovery-codes"]')->form(['code' => $codes[0]]));
        $fresh = $anna->followRedirect()->filter('.cl-recovery-codes code')->each(static fn (Crawler $code): string => $code->text());
        self::assertCount(8, $fresh);
        self::assertNotContains($codes[1], $fresh, 'New ones, the old gone.');

        // Required of her now: it stays on.
        $this->settings()->set('AUTH_TWO_STEP', 'EVERYONE');
        $page = $anna->request('GET', '/account/security');
        self::assertStringContainsString('This site requires it of you, so it stays on.', $page->filter('#two-step')->text());
        self::assertCount(0, $page->filter('form[action="/account/security/two-step/off"]'));
        $anna->request('POST', '/account/security/two-step/off', ['_token' => $this->token($page), 'code' => $fresh[0]]);
        self::assertStringContainsString('requires two-step sign-in of you', $anna->followRedirect()->filter('.cl-flash-stack')->text());

        $this->settings()->set('AUTH_TWO_STEP', 'CHOICE');
        $page = $anna->request('GET', '/account/security');
        $anna->submit($page->filter('form[action="/account/security/two-step/off"]')->form(['code' => $fresh[1]]));
        $page = $anna->followRedirect();
        self::assertStringContainsString('Two-step sign-in is off.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('Off', $page->filter('#two-step dl')->text());
    }

    /**
     * Review finding 5: wrong codes on Sign-in and security are limited, counted for the account and kept in the activity
     * log; the last one allowed ends that sign-in, and the sign-in page says why. Two-step stays on.
     */
    public function testTooManyWrongCodesOnSignInAndSecurityEndThatSignIn(): void
    {
        $this->start(['AUTH_TWO_STEP' => 'CHOICE']);
        $anna = $this->signedIn('anna@example.com');
        $twoStep = $this->container()->get(TwoStep::class);
        self::assertInstanceOf(TwoStep::class, $twoStep);
        $twoStep->setUp('2', self::SECRET, OneTimeCode::codeAt(self::SECRET, time()));
        $page = $anna->request('GET', '/account/security');

        foreach (range(1, 4) as $try) {
            $form = $try % 2 === 0 ? '/account/security/recovery-codes' : '/account/security/two-step/off';
            $anna->submit($page->filter('form[action="' . $form . '"]')->form(['code' => '000000']));
            self::assertSame('/account/security#two-step', $anna->getResponse()->headers->get('Location'), 'Try ' . $try . ': back, to try again.');
            $page = $anna->followRedirect();
            self::assertStringContainsString('That code is not right', $page->filter('.cl-flash-stack')->text());
        }
        $anna->submit($page->filter('form[action="/account/security/two-step/off"]')->form(['code' => '000000']));
        self::assertSame('/sign-in', $anna->getResponse()->headers->get('Location'), 'The fifth: signed out.');
        self::assertSame('Too many wrong codes were typed on Sign-in and security, so you were signed out. Sign in again to carry on.', trim($anna->followRedirect()->filter('.cl-sign-in-note')->text()), 'The sign-in page says why.');
        $anna->request('GET', '/account/security');
        self::assertSame('/sign-in?return=/account/security', $anna->getResponse()->headers->get('Location'), 'That sign-in is ended.');

        $logged = $this->connection->fetchAllAssociative("SELECT actor_id, subject_id, details FROM cattophp_activity WHERE action = 'two_step.wrong_code' ORDER BY id");
        self::assertSame([5, 5], [SecurityController::WRONG_CODES_ALLOWED, count($logged)], 'Each one in the log.');
        self::assertSame(['2', '2'], [$logged[0]['actor_id'], $logged[0]['subject_id']], 'Her own doing, about her.');
        self::assertSame(['for' => 'turning two-step sign-in off', 'signed_out' => true], json_decode((string) $logged[count($logged) - 1]['details'], true));
        self::assertTrue($twoStep->isSetUp('2'), 'Two-step is still on.');
    }

    /**
     * Review finding 16: setting two-step up is refused while it is on, so a key left in the session (a set-up page opened
     * before) never replaces the key there is without a current code.
     */
    public function testSettingUpIsRefusedWhileTwoStepIsOn(): void
    {
        $this->start(['AUTH_TWO_STEP' => 'CHOICE']);
        $anna = $this->signedIn('anna@example.com');
        $page = $anna->request('GET', '/account/security/two-step');
        $left = str_replace(' ', '', $page->filter('[data-two-step-key]')->text());
        // Meanwhile it is set up in another of her browsers, with another key.
        $twoStep = $this->container()->get(TwoStep::class);
        self::assertInstanceOf(TwoStep::class, $twoStep);
        $twoStep->setUp('2', self::SECRET, OneTimeCode::codeAt(self::SECRET, time()));

        $anna->submit($page->filter('form')->form(['code' => OneTimeCode::codeAt($left, time())]));

        self::assertSame('/account/security#two-step', $anna->getResponse()->headers->get('Location'));
        self::assertStringContainsString('Two-step sign-in is already on.', $anna->followRedirect()->filter('.cl-flash-stack')->text());
        $store = $this->container()->get(TwoStepStore::class);
        self::assertInstanceOf(TwoStepStore::class, $store);
        self::assertSame(self::SECRET, $store->find('2')?->secret, 'The key she set up, not the one left in the session.');
    }

    public function testEachSignInIsKeptListedAndEndedAtOnce(): void
    {
        $this->start(signIns: true);
        $here = $this->signedIn('owner@example.com');
        $there = $this->signedIn('owner@example.com', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_sessions WHERE ended_at IS NULL'));
        self::assertNotContains('203.0.113.9', $this->connection->fetchFirstColumn('SELECT requested_from FROM cattophp_sessions'), 'Where from, only as a fingerprint.');

        $page = $here->request('GET', '/account/security');
        $rows = $page->filter('#sign-ins .cl-ui-card');
        self::assertCount(2, $rows, 'A card for each, every word in full at any width.');
        self::assertCount(0, $page->filter('#sign-ins table'), 'Not a table, whose columns are cut short.');
        self::assertStringContainsString('This browser', $rows->first()->filter('.cl-ui-card-title .cl-ui-badge')->text(), 'This browser first, marked in its title, where it always shows.');
        self::assertCount(1, $page->filter('#sign-ins .cl-ui-badge'));
        self::assertSame('none', $rows->first()->attr('data-picture'));
        self::assertStringContainsString('Firefox on Linux', $rows->first()->text());
        self::assertStringContainsString('Safari on iPhone, a phone', $rows->eq(1)->text());

        $here->submit($rows->eq(1)->filter('form')->form());
        self::assertStringContainsString('That sign-in is ended', $here->followRedirect()->filter('.cl-flash-stack')->text());
        $there->request('GET', '/settings');
        self::assertSame(303, $there->getResponse()->getStatusCode(), 'The other browser is signed out at its next page.');
        self::assertStringStartsWith('/sign-in', (string) $there->getResponse()->headers->get('Location'));
        $here->request('GET', '/settings');
        self::assertSame(200, $here->getResponse()->getStatusCode(), 'This one carries on.');

        $there = $this->signedIn('owner@example.com');
        $third = $this->signedIn('owner@example.com');
        $page = $here->request('GET', '/account/security');
        $here->submit($page->filter('form[action="/account/security/sign-ins/end-others"]')->form());
        self::assertStringContainsString('2 other sign-ins are ended. You are signed in only here now.', $here->followRedirect()->filter('.cl-flash-stack')->text());
        foreach ([$there, $third] as $other) {
            $other->request('GET', '/settings');
            self::assertSame(303, $other->getResponse()->getStatusCode());
        }

        $page = $here->request('GET', '/');
        $here->submit($page->filter('form.sign-out')->form());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_sessions WHERE ended_at IS NULL'), 'Signing out ends the last.');
        self::assertSame(['sign_in.session_ended', 'sign_in.sessions_ended'], $this->connection->fetchFirstColumn("SELECT action FROM cattophp_activity WHERE action LIKE 'sign_in.session%' ORDER BY id"));
    }

    public function testAProjectThatKeepsItsOwnSignInsHasNoneKeptTwice(): void
    {
        $this->start();
        $owner = $this->signedIn('owner@example.com');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_sessions'), 'Off unless switched on: nothing kept.');
        $page = $owner->request('GET', '/account/security');
        self::assertCount(0, $page->filter('#sign-ins'));
        $owner->request('POST', '/account/security/sign-ins/end-others', ['_token' => $this->token($owner->request('GET', '/'))]);
        self::assertSame(403, $owner->getResponse()->getStatusCode());
    }

    public function testSomeoneWhoMayJoinGetsAnAccountOnlyWhenTheyUseTheirLink(): void
    {
        $this->start(['AUTH_SIGNIN' => 'EVERYONE']);
        $accounts = $this->container()->get(ExampleAccounts::class);
        self::assertInstanceOf(ExampleAccounts::class, $accounts);
        $browser = $this->browser();
        $page = $browser->request('GET', '/sign-in');
        $browser->submit($page->filter('form')->form(['email' => 'New.Person@example.org']), [], ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame('/sign-in/sent', $browser->getResponse()->headers->get('Location'), 'The same answer as for anyone.');
        self::assertSame(0, $accounts->joined, 'Nothing is made before the link is used.');
        self::assertSame([['sign_in.joining_link_sent', null, null]], $this->connection->fetchAllNumeric("SELECT action, subject_type, subject_id FROM cattophp_activity"), 'Nobody named, no made-up account.');
        self::assertSame('new.person@example.org', $this->transport()->last()->getTo()[0]->getAddress());
        $link = $this->linkIn();

        $page = $browser->request('GET', $link);
        self::assertSame(0, $accounts->joined, 'Opening it makes nothing either.');
        $browser->submit($page->filter('form')->form());
        self::assertSame('/', $browser->getResponse()->headers->get('Location'));
        self::assertSame('Signed in as New.person', $browser->followRedirect()->filter('p')->text());
        self::assertSame(1, $accounts->joined);
        self::assertSame(['account.created', 'sign_in.signed_in'], $this->connection->fetchFirstColumn("SELECT action FROM cattophp_activity WHERE subject_id = '3' ORDER BY id"));

        $browser->request('GET', $link);
        self::assertStringContainsString('That link has been used or has run out', (string) $browser->getResponse()->getContent());
        $this->settings()->set('AUTH_SIGNIN', 'ADMIN');
        $page = $browser->request('GET', '/sign-in');
        $sent = count($this->transport()->sent);
        $browser->submit($page->filter('form')->form(['email' => 'another@example.org']), [], ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame('/sign-in/sent', $browser->getResponse()->headers->get('Location'));
        self::assertCount($sent, $this->transport()->sent, 'An address that may not join is sent nothing.');
    }

    /** @param array<string,string> $starting */
    private function start(array $starting = [], bool $signIns = false): void
    {
        $this->kernel = new TestKernel($this->folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), $starting + ['AUTH_TWO_STEP' => 'CHOICE', 'AUTH_SIGNIN' => 'EVERYONE'],
            stockPages: true, accounts: true, signIns: $signIns);
        $this->kernel->boot();
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function settings(): Settings
    {
        $settings = $this->container()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);
        $settings->refresh();

        return $settings;
    }

    private function transport(): RecordingTransport
    {
        $transport = $this->kernel->getContainer()->get(RecordingTransport::class);
        self::assertInstanceOf(RecordingTransport::class, $transport);

        return $transport;
    }

    private function browser(): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();

        return $browser;
    }

    /** A browser signed in as someone, by the emailed link, with no two-step set up. */
    private function signedIn(string $email, string $agent = self::BROWSER): KernelBrowser
    {
        $browser = $this->browser();
        $browser->setServerParameter('HTTP_USER_AGENT', $agent);
        $browser->setServerParameter('REMOTE_ADDR', '203.0.113.9');
        $page = $browser->request('GET', '/sign-in');
        $browser->submit($page->filter('form')->form(['email' => $email]));
        $page = $browser->request('GET', $this->linkIn());
        $browser->submit($page->filter('form')->form());
        self::assertSame('/', $browser->getResponse()->headers->get('Location'), 'Signed in.');

        return $browser;
    }

    private function linkIn(): string
    {
        self::assertSame(1, preg_match('#https://example\.com(/sign-in/link/[0-9a-f]{64})#', (string) $this->transport()->last()->getTextBody(), $found));

        return $found[1] ?? '';
    }

    private function form(Crawler $page, string $action): \Symfony\Component\DomCrawler\Form
    {
        return $page->filter('form[action="' . $action . '"]')->form();
    }

    private function help(Crawler $page, string $field): string
    {
        return $page->filter('#' . $field . '-help')->text();
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('input[name="_token"]')->first()->attr('value');
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
