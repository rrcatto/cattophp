<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Settings;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsCatalogue;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingsRefused;
use CattoPHP\Settings\SettingType;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private Foundations $site;
    private MemorySettingsStore $store;

    protected function setUp(): void
    {
        $this->store = new MemorySettingsStore();
        $this->site = new Foundations(['AUTH_MAGIC_LINK_TTL' => '15m', 'APP_SITE_MODE' => 'live'], $this->store, [new ExampleSettings()]);
    }

    public function testValueComesFromSettingsThenEnvThenTheDefault(): void
    {
        $settings = $this->site->settings;
        self::assertSame('15m', $settings->get('AUTH_MAGIC_LINK_TTL'));
        self::assertSame(Settings::FROM_ENV, $settings->source('AUTH_MAGIC_LINK_TTL'));
        self::assertSame('1d', $settings->get('AUTH_SESSION_TTL'));
        self::assertSame(Settings::FROM_DEFAULT, $settings->source('AUTH_SESSION_TTL'));

        $settings->save('sign_in', $this->signInForm(['AUTH_MAGIC_LINK_TTL' => '45m']));
        self::assertSame('45m', $settings->get('AUTH_MAGIC_LINK_TTL'));
        self::assertSame(Settings::FROM_SETTINGS, $settings->source('AUTH_MAGIC_LINK_TTL'));
        self::assertSame(45 * 60, $settings->duration('AUTH_MAGIC_LINK_TTL')->lengthInSeconds());

        $settings->reset('AUTH_MAGIC_LINK_TTL');
        self::assertSame('15m', $settings->get('AUTH_MAGIC_LINK_TTL'));
        self::assertSame(Settings::FROM_ENV, $settings->source('AUTH_MAGIC_LINK_TTL'));
    }

    public function testSavingWhatEnvGivesKeepsNoOverride(): void
    {
        $this->site->settings->save('sign_in', $this->signInForm(['AUTH_MAGIC_LINK_TTL' => '15m']));

        self::assertFalse($this->site->settings->isOverridden('AUTH_MAGIC_LINK_TTL'));
        self::assertSame([], $this->store->all(), 'a value equal to .env leaves .env in charge');
    }

    public function testEveryChangeIsInTheActivityLogWithItsOldAndNewValues(): void
    {
        $changed = $this->site->settings->save('sign_in', $this->signInForm(['AUTH_REQUEST_LIMIT_EMAIL' => '7']), new Actor('42', 'Ada'));

        self::assertSame(['AUTH_REQUEST_LIMIT_EMAIL'], $changed);
        $entry = $this->site->activity->recent(1)[0];
        self::assertSame('settings.changed', $entry->action);
        self::assertSame(['setting' => 'AUTH_REQUEST_LIMIT_EMAIL', 'old' => '5', 'new' => '7'], $entry->details);
        self::assertSame('42', $entry->actorId);
        self::assertSame('42', $this->store->all()['AUTH_REQUEST_LIMIT_EMAIL']->updatedBy);
    }

    public function testASecretIsStoredEncryptedAndItsValueNeverLogged(): void
    {
        $settings = $this->site->settings;
        $settings->save('mail', ['MAILER_DSN' => 'smtp://user:hunter2@mail.example.com:587', 'MAIL_FROM_ADDRESS' => 'site@example.com']);

        $stored = $this->store->all()['MAILER_DSN'];
        self::assertTrue($stored->secret);
        self::assertStringStartsWith('v1:', $stored->value);
        self::assertStringNotContainsString('hunter2', $stored->value);
        self::assertSame('smtp://user:hunter2@mail.example.com:587', $settings->get('MAILER_DSN'));
        $entry = $this->site->activity->recent(1, 'settings.changed', 'setting', 'MAILER_DSN')[0];
        self::assertSame(['setting' => 'MAILER_DSN', 'secret_changed' => true], $entry->details);
    }

    public function testASecretLeftBlankKeepsTheOneThereIs(): void
    {
        $settings = $this->site->settings;
        $settings->save('mail', ['MAILER_DSN' => 'smtp://user:hunter2@mail.example.com:587', 'MAIL_FROM_ADDRESS' => 'site@example.com']);
        $settings->save('mail', ['MAILER_DSN' => '', 'MAIL_FROM_ADDRESS' => 'news@example.com']);

        self::assertSame('smtp://user:hunter2@mail.example.com:587', $settings->get('MAILER_DSN'));
        self::assertSame('news@example.com', $settings->get('MAIL_FROM_ADDRESS'));
    }

    public function testASecretThatCannotBeDecryptedIsAnErrorNotAQuietFallback(): void
    {
        $this->store->save('MAILER_DSN', 'v1:' . base64_encode(random_bytes(40)), true, $this->site->clock->now(), null);

        self::assertNotNull($this->site->settings->secretProblem('MAILER_DSN'));
        $this->expectException(\RuntimeException::class);
        $this->site->settings->get('MAILER_DSN');
    }

    public function testNothingOfAGroupIsSavedWhenOneValueIsRefused(): void
    {
        try {
            $this->site->settings->save('sign_in', $this->signInForm(['AUTH_REQUEST_LIMIT_EMAIL' => '9', 'AUTH_SESSION_TTL' => '2 days']));
            self::fail('A length of time written another way must be refused.');
        } catch (SettingsRefused $refused) {
            self::assertArrayHasKey('AUTH_SESSION_TTL', $refused->errors());
            self::assertStringContainsString('1h30m', $refused->errors()['AUTH_SESSION_TTL']);
        }
        self::assertSame([], $this->store->all());
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function refusedValues(): iterable
    {
        yield 'not an email' => ['EXAMPLE_EMAIL', 'not-an-address', 'must be an email address'];
        yield 'http where https is required' => ['EXAMPLE_ADDRESS', 'http://example.com', 'starting with https://'];
        yield 'below the least' => ['EXAMPLE_NUMBER', '0', 'from 1 to 10'];
        yield 'above the most' => ['EXAMPLE_NUMBER', '11', 'from 1 to 10'];
        yield 'not a whole number' => ['EXAMPLE_NUMBER', '2.5', 'whole number'];
        yield 'shorter than the shortest' => ['EXAMPLE_LENGTH', '30s', 'at least 1 minute'];
        yield 'not a choice' => ['EXAMPLE_CHOICE', 'maybe', 'choose one of'];
        yield 'not a time zone' => ['EXAMPLE_ZONE', 'Mars/Olympus', 'time zone'];
        yield 'not a mail server' => ['EXAMPLE_MAIL', 'mail.example.com', 'mail server'];
        yield 'too long' => ['EXAMPLE_TEXT', str_repeat('a', 21), 'at most 20 characters'];
        yield 'required and empty' => ['EXAMPLE_TEXT', '', 'cannot be empty'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedValues')]
    public function testEachKindOfValueIsCheckedInPlainWords(string $name, string $typed, string $why): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($why);
        $this->site->settings->checked($this->site->catalogue->setting($name), $typed);
    }

    public function testValuesArePutInTheirOneForm(): void
    {
        $settings = $this->site->settings;
        self::assertSame('true', $settings->checked($this->site->catalogue->setting('EXAMPLE_SWITCH'), 'on'));
        self::assertSame('false', $settings->checked($this->site->catalogue->setting('EXAMPLE_SWITCH'), null), 'an unticked box sends nothing');
        self::assertSame('7', $settings->checked($this->site->catalogue->setting('EXAMPLE_NUMBER'), ' 07 '));
        self::assertSame('one line', $settings->checked($this->site->catalogue->setting('EXAMPLE_TEXT'), "one \n line"));
        self::assertSame('1h30m', $settings->checked($this->site->catalogue->setting('EXAMPLE_LENGTH'), '1h30m'));
        self::assertSame('5', $settings->checked($this->site->catalogue->setting('EXAMPLE_NUMBER'), ''), 'blank is the default');
    }

    public function testAnInstallationWithoutADatabaseSavesNothing(): void
    {
        $site = new Foundations([], new MemorySettingsStore(false));

        self::assertFalse($site->settings->canSave());
        $this->expectException(SettingsRefused::class);
        $this->expectExceptionMessage('change them there');
        $site->settings->save('sign_in', $this->signInForm([]));
    }

    public function testStartingValuesFromTheProjectsConfigurationComeBeforeEnv(): void
    {
        $environment = new \CattoPHP\Settings\EnvironmentValues(['APP_NAME' => 'From env', 'APP_URL' => 'https://env.example.com'], ['APP_NAME' => 'Fixed Name', 'APP_URL' => '']);

        self::assertSame('Fixed Name', $environment->get('APP_NAME'));
        self::assertSame('https://env.example.com', $environment->get('APP_URL'), 'an empty configured value leaves .env\'s');
    }

    public function testAnUnknownNameIsAMistakeNotAnEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No setting is called APP_NAEM');
        $this->site->settings->get('APP_NAEM');
    }

    public function testASettingInTwoGroupsIsRefusedWhenTheCatalogueIsMade(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SettingsCatalogue([new ExampleSettings(), new class implements SettingsProvider {
            public function settingGroups(): iterable
            {
                yield new SettingGroup('again', 'Again', '', [new Setting('EXAMPLE_TEXT', 'Text')]);
            }
        }]);
    }

    public function testGroupsResetTogether(): void
    {
        $settings = $this->site->settings;
        $settings->save('sign_in', $this->signInForm(['AUTH_REQUEST_LIMIT_EMAIL' => '9', 'AUTH_REQUEST_LIMIT_IP' => '99']));
        self::assertCount(2, $this->store->all());

        $settings->resetGroup('sign_in');
        self::assertSame([], $this->store->all());
        self::assertSame(5, $settings->int('AUTH_REQUEST_LIMIT_EMAIL'));
        self::assertCount(2, $this->site->activity->recent(10, 'settings.reset'));
    }

    public function testABlankSavedOverridesEnvForASettingThatMayBeBlank(): void
    {
        $site = new Foundations(['AUTH_SIGNIN_ALLOWED_EMAILS' => 'listed@example.org', 'APP_ORGANISATION' => 'From .env'], $this->store);

        $site->settings->set('AUTH_SIGNIN_ALLOWED_EMAILS', '');
        self::assertSame('', $site->settings->get('AUTH_SIGNIN_ALLOWED_EMAILS'), 'no addresses listed, whatever .env lists');
        self::assertSame(Settings::FROM_SETTINGS, $site->settings->source('AUTH_SIGNIN_ALLOWED_EMAILS'));
        $site->settings->reset('AUTH_SIGNIN_ALLOWED_EMAILS');
        self::assertSame('listed@example.org', $site->settings->get('AUTH_SIGNIN_ALLOWED_EMAILS'), 'Reset returns to .env');
        $site->settings->set('AUTH_REQUEST_LIMIT_EMAIL', '');
        self::assertSame(5, $site->settings->int('AUTH_REQUEST_LIMIT_EMAIL'), 'a number left blank takes its default');
        self::assertFalse($site->settings->isOverridden('AUTH_REQUEST_LIMIT_EMAIL'));
    }

    public function testOneSettingIsSavedOnItsOwn(): void
    {
        $settings = $this->site->settings;

        self::assertTrue($settings->set('AUTH_REQUEST_LIMIT_EMAIL', '8'));
        self::assertSame(8, $settings->int('AUTH_REQUEST_LIMIT_EMAIL'));
        self::assertSame(['AUTH_REQUEST_LIMIT_EMAIL'], array_keys($this->store->all()), 'nothing else of its group is saved');
        self::assertFalse($settings->set('AUTH_REQUEST_LIMIT_EMAIL', '8'), 'the same value again changes nothing');
        self::assertTrue($settings->set('AUTH_REQUEST_LIMIT_EMAIL', '5'), 'back to the default');
        self::assertSame([], $this->store->all(), 'the default is no override');
        try {
            $settings->set('AUTH_REQUEST_LIMIT_EMAIL', 'many');
            self::fail('A word is no number.');
        } catch (SettingsRefused $refused) {
            self::assertSame(['AUTH_REQUEST_LIMIT_EMAIL'], array_keys($refused->errors()));
        }
        $settings->set('MAILER_DSN', 'smtp://user:hunter2@mail.example.com:587');
        self::assertFalse($settings->set('MAILER_DSN', ''), 'a secret left blank keeps the one there is');
        self::assertSame('smtp://user:hunter2@mail.example.com:587', $settings->get('MAILER_DSN'));
    }

    public function testWhatAnotherProcessSavedIsSeenWithinSeconds(): void
    {
        $worker = new Settings($this->site->catalogue, $this->store, $this->site->cipher, new \CattoPHP\Settings\EnvironmentValues(['APP_SITE_MODE' => 'live']), $this->site->activity, $this->site->clock);
        self::assertSame('live', $worker->get('APP_SITE_MODE'), 'the worker reads it once');

        $this->site->settings->set('APP_SITE_MODE', 'demo');
        self::assertSame('live', $worker->get('APP_SITE_MODE'), 'and trusts it for a few seconds');
        $this->site->clock->modify('+' . Settings::FRESH_FOR . ' seconds');
        self::assertSame('demo', $worker->get('APP_SITE_MODE'), 'then reads it again');

        $this->site->settings->set('APP_SITE_MODE', 'live');
        $worker->refresh();
        self::assertSame('live', $worker->get('APP_SITE_MODE'), 'or at once, when told');
    }

    public function testAProjectsOwnDefaultComesAfterEnvAndTheSettingsPage(): void
    {
        $catalogue = new SettingsCatalogue([new \CattoPHP\Settings\FoundationSettings()], ['APP_DEMO_NOTICE' => 'Nothing here is for sale yet.', 'AUTH_SESSION_TTL' => '12h']);
        $settings = static fn (array $env): Settings => new Settings($catalogue, new MemorySettingsStore(), (new Foundations())->cipher, new \CattoPHP\Settings\EnvironmentValues($env), (new Foundations())->activity, (new Foundations())->clock);

        self::assertSame('Nothing here is for sale yet.', $settings([])->get('APP_DEMO_NOTICE'));
        self::assertSame('12h', $settings([])->get('AUTH_SESSION_TTL'));
        self::assertSame('From .env.', $settings(['APP_DEMO_NOTICE' => 'From .env.'])->get('APP_DEMO_NOTICE'), '.env comes before the project\'s default');
        $own = $settings([]);
        $own->set('APP_DEMO_NOTICE', 'Saved on the page.');
        self::assertSame('Saved on the page.', $own->get('APP_DEMO_NOTICE'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A default is given for APP_DEMO_NOTCE, which is no setting.');
        new SettingsCatalogue([new \CattoPHP\Settings\FoundationSettings()], ['APP_DEMO_NOTCE' => 'A typing mistake.']);
    }

    /**
     * The sign-in group as its form sends it, with changes.
     *
     * @param array<string,string> $changes
     * @return array<string,string>
     */
    private function signInForm(array $changes): array
    {
        $form = [];
        foreach ($this->site->catalogue->group('sign_in')->settings as $name => $setting) {
            $form[$name] = $this->site->settings->get($name);
        }

        return array_merge($form, $changes);
    }
}

final class ExampleSettings implements SettingsProvider
{
    public function settingGroups(): iterable
    {
        yield new SettingGroup('example', 'Example', 'Every kind of value.', [
            new Setting('EXAMPLE_TEXT', 'Some text', longest: 20),
            new Setting('EXAMPLE_EMAIL', 'An email', SettingType::Email),
            new Setting('EXAMPLE_ADDRESS', 'An address', SettingType::Address, httpsOnly: true),
            new Setting('EXAMPLE_NUMBER', 'A number', SettingType::Number, '5', min: 1, max: 10),
            new Setting('EXAMPLE_SWITCH', 'A switch', SettingType::Switch, 'false'),
            new Setting('EXAMPLE_LENGTH', 'A length', SettingType::Duration, '5m', shortest: '1m'),
            new Setting('EXAMPLE_CHOICE', 'A choice', SettingType::Choice, 'a', choices: ['a' => 'A', 'b' => 'B']),
            new Setting('EXAMPLE_ZONE', 'A zone', SettingType::TimeZone, 'UTC'),
            new Setting('EXAMPLE_MAIL', 'A mail server', SettingType::MailTransport, optional: true),
        ]);
    }
}
