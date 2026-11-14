<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Settings;

use CattoPHP\Activity\Actor;
use CattoPHP\Admin\AccountNames;
use CattoPHP\Admin\SettingsPage;
use CattoPHP\Foundation\Duration;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\Fact;
use CattoPHP\Settings\FactGroup;
use CattoPHP\Settings\FactsProvider;
use CattoPHP\Settings\FixedSettings;
use CattoPHP\Settings\FixedSettingsProvider;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Settings\Setting;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsRefused;
use CattoPHP\Settings\SettingType;
use CattoPHP\Tests\Support\Foundations;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Settings a project holds fixed, refused however a change is asked for; facts the Settings page shows; and a length of
 * time with a longest value.
 */
final class FixedSettingsTest extends TestCase
{
    private Foundations $site;
    private MemorySettingsStore $store;

    protected function setUp(): void
    {
        $this->store = new MemorySettingsStore();
        $this->site = new Foundations(settingsStore: $this->store);
    }

    /**
     * @param list<string> $fixed
     * @param list<string> $hidden
     * @param list<FixedSettingsProvider> $providers
     * @param array<string,string> $env
     */
    private function settings(array $fixed = [], array $hidden = [], array $providers = [], array $env = []): Settings
    {
        return new Settings($this->site->catalogue, $this->store, $this->site->cipher, new EnvironmentValues($env + [
            'APP_NAME' => 'Example Site', 'APP_URL' => 'https://example.com', 'APP_ADMIN_EMAIL' => 'owner@example.com', 'MAIL_FROM_ADDRESS' => 'site@example.com',
        ]), $this->site->activity, $this->site->clock, new FixedSettings($fixed, $hidden, $providers));
    }

    /**
     * @param array<string,string> $changes
     * @return array<string,string>
     */
    private static function form(Settings $settings, string $group, array $changes = []): array
    {
        $form = [];
        foreach ($settings->catalogue()->group($group)->settings as $name => $setting) {
            if (!$setting->secret && !$settings->isFixed($name)) {
                $form[$name] = $settings->get($name);
            }
        }

        return $changes + $form;
    }

    /**
     * The one item of a list whose $field is $value.
     *
     * @param mixed $items
     * @return array<string,mixed>
     */
    private static function named(mixed $items, string $field, string $value): array
    {
        self::assertIsArray($items);
        foreach ($items as $item) {
            if (is_array($item) && ($item[$field] ?? null) === $value) {
                return $item;
            }
        }
        self::fail('None is ' . $value . '.');
    }

    public function testAFixedSettingKeepsItsConfigurationsValueAndIsRefusedEveryWay(): void
    {
        // Saved before the project held it fixed: no longer applies.
        $this->store->save('APP_URL', 'https://elsewhere.example.org', false, new DateTimeImmutable('2026-11-01 10:00:00 UTC'), null);
        $settings = $this->settings(['APP_URL', 'sign_in']);

        self::assertTrue($settings->isFixed('APP_URL'));
        self::assertTrue($settings->isFixed('AUTH_TWO_STEP'), 'A group stands for each of its settings.');
        self::assertFalse($settings->isFixed('APP_NAME'));
        self::assertFalse($settings->isHidden('APP_URL'));
        self::assertSame('https://example.com', $settings->get('APP_URL'), 'What was saved before is passed over.');
        self::assertFalse($settings->isOverridden('APP_URL'));
        self::assertSame(Settings::FROM_ENV, $settings->source('APP_URL'));

        // Saving the group as the page sends it (without the fixed field) saves the rest.
        self::assertSame(['APP_NAME'], $settings->save('installation', self::form($settings, 'installation', ['APP_NAME' => 'Example Hall'])));
        self::assertSame('https://example.com', $settings->get('APP_URL'));
        // Sent as it is: changes nothing, refused nothing.
        self::assertSame([], $settings->save('installation', self::form($settings, 'installation', ['APP_URL' => 'https://example.com'])));
        // Sent to change it: the whole group refused, saying why.
        try {
            $settings->save('installation', self::form($settings, 'installation', ['APP_URL' => 'https://attacker.example.net', 'APP_NAME' => 'Example Lodge']));
            self::fail('A fixed setting was changed by a form.');
        } catch (SettingsRefused $refused) {
            self::assertSame(['APP_URL' => 'Public address is fixed in this installation\'s configuration and cannot be changed here.'], $refused->errors());
        }
        self::assertSame('Example Hall', $settings->get('APP_NAME'), 'Nothing of the group saved.');

        foreach (['set' => static fn () => $settings->set('APP_URL', 'https://attacker.example.net'), 'clear' => static fn () => $settings->clear('APP_URL'),
            'reset' => static fn () => $settings->reset('APP_URL'), 'save of a group fixed whole' => static fn () => $settings->save('sign_in', ['AUTH_SESSION_TTL' => '30d']),
            'reset of a group fixed whole' => static fn () => $settings->resetGroup('sign_in')] as $how => $change) {
            try {
                $change();
                self::fail($how . ': a fixed setting was changed.');
            } catch (SettingsRefused $refused) {
                self::assertStringContainsString('is fixed in this installation\'s configuration and cannot be changed here.', $refused->getMessage(), $how);
            }
        }
        self::assertSame('1d', $settings->get('AUTH_SESSION_TTL'));
        self::assertTrue($settings->groupIsFixed('sign_in'));
        self::assertFalse($settings->groupIsFixed('installation'));

        // Reset of a group partly fixed resets the rest.
        $settings->resetGroup('installation', new Actor('1', 'Olive Owner'));
        self::assertSame('Example Site', $settings->get('APP_NAME'));
    }

    public function testHiddenSettingsAndAProjectsOwnProviderAndAMistakenName(): void
    {
        $provider = new class implements FixedSettingsProvider {
            public function fixedSettings(): iterable
            {
                yield 'APP_ADMIN_EMAIL';
            }

            public function hiddenSettings(): iterable
            {
                return ['MAILER_DSN'];
            }
        };
        $settings = $this->settings(hidden: ['site_mode'], providers: [$provider]);
        self::assertTrue($settings->isFixed('APP_ADMIN_EMAIL'));
        self::assertFalse($settings->isHidden('APP_ADMIN_EMAIL'));
        self::assertTrue($settings->isHidden('MAILER_DSN'));
        self::assertTrue($settings->isFixed('MAILER_DSN'), 'Hidden is fixed too.');
        self::assertTrue($settings->isHidden('APP_SITE_MODE'));

        $page = new SettingsPage($settings, $this->site->installation, new AccountNames());
        $groups = $page->groups(static fn (): array => [], []);
        $keys = array_column($groups, 'key');
        self::assertNotContains('site_mode', $keys, 'A group hidden whole is not drawn.');
        self::assertFalse($page->shows('site_mode'));
        $mail = self::named($groups, 'key', 'mail');
        self::assertNotContains('MAILER_DSN', array_column($mail['settings'], 'name'), 'A hidden setting is not drawn.');
        $installation = self::named($groups, 'key', 'installation');
        $admin = self::named($installation['settings'], 'name', 'APP_ADMIN_EMAIL');
        self::assertTrue($admin['fixed']);
        self::assertStringStartsWith('Fixed in this installation\'s configuration: it cannot be changed here. From .env (APP_ADMIN_EMAIL).', $admin['help']);
        self::assertFalse($installation['fixed'], 'The rest of the group can be saved.');
        self::assertFalse($page->inGroup('mail', 'MAILER_DSN'));

        $mistaken = $this->settings(['APP_ADRESS']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"APP_ADRESS" is held fixed, but it is no setting or group of settings');
        $mistaken->isFixed('APP_NAME');
    }

    public function testAProjectsFactsAreDrawnAfterTheSettings(): void
    {
        $facts = new class implements FactsProvider {
            public function settingsFacts(): iterable
            {
                yield new FactGroup('protected_networks', 'Protected networks', 'Never refused; set in the configuration.', [new Fact('Office', '192.0.2.0/24')]);
            }
        };
        $page = new SettingsPage($this->settings(), $this->site->installation, new AccountNames(), [$facts]);
        self::assertSame([[
            'key' => 'protected_networks',
            'heading' => 'Protected networks',
            'explanation' => 'Never refused; set in the configuration.',
            'summary' => 'Shown only: 1 fact this page cannot change.',
            'items' => [['label' => 'Office', 'value' => '192.0.2.0/24']],
        ]], $page->facts());

        $this->expectException(InvalidArgumentException::class);
        new FactGroup('Protected networks', 'Protected networks', '', []);
    }

    public function testALengthOfTimeHasALongestValueOnSaveAndWhenItsStartingValueIsRead(): void
    {
        $settings = $this->settings(env: ['AUTH_SESSION_TTL' => '2y']);
        self::assertSame('365d', $settings->get('AUTH_SESSION_TTL'), '.env\'s longer value is read as the longest.');
        self::assertSame(365 * 86400, $settings->duration('AUTH_SESSION_TTL')->lengthInSeconds());
        self::assertTrue($settings->isCutToLongest('AUTH_SESSION_TTL'));
        try {
            $settings->set('AUTH_SESSION_TTL', '400d');
            self::fail('A length over the longest was saved.');
        } catch (SettingsRefused $refused) {
            self::assertSame(['AUTH_SESSION_TTL' => 'How long a sign-in lasts must be at most 365 days.'], $refused->errors());
        }
        self::assertTrue($settings->set('AUTH_SESSION_TTL', '30d'));
        self::assertSame('30d', $settings->get('AUTH_SESSION_TTL'));
        self::assertFalse($settings->isCutToLongest('AUTH_SESSION_TTL'));
        self::assertTrue($settings->set('AUTH_SESSION_TTL', '365d'), 'The longest itself is allowed.');

        $page = new SettingsPage($this->settings(env: ['AUTH_SESSION_TTL' => '2y']), $this->site->installation, new AccountNames());
        $settings = $this->settings(env: ['AUTH_SESSION_TTL' => '2y']);
        $settings->reset('AUTH_SESSION_TTL');
        $signIn = $page->group('sign_in', [], []);
        $ttl = self::named($signIn['settings'], 'name', 'AUTH_SESSION_TTL');
        self::assertSame('365d', $ttl['value']);
        self::assertStringContainsString('It may be from 5 minutes to 365 days.', $ttl['help']);
        self::assertStringContainsString('.env (AUTH_SESSION_TTL) gives "2y", longer than the longest it may be, so 365 days applies.', $ttl['help']);

        // A setting without one is as before: any length.
        $plain = new Setting('NOTES_KEPT_FOR', 'How long notes are kept', SettingType::Duration, '1y');
        self::assertSame('10y', $settings->checked($plain, '10y'));
    }

    public function testALongestValueIsCheckedWhenTheSettingIsMade(): void
    {
        foreach ([
            'no length of time' => static fn () => new Setting('EXAMPLE_KEPT_FOR', 'Kept for', SettingType::Duration, '1d', longestTime: 'a year'),
            'shortest longer than longest' => static fn () => new Setting('EXAMPLE_KEPT_FOR', 'Kept for', SettingType::Duration, '1d', shortest: '2w', longestTime: '1w'),
            'default longer than longest' => static fn () => new Setting('EXAMPLE_KEPT_FOR', 'Kept for', SettingType::Duration, '2y', longestTime: '1y'),
            'a project default longer than longest' => static fn () => (new Setting('EXAMPLE_KEPT_FOR', 'Kept for', SettingType::Duration, '1d', longestTime: '1y'))->withDefault('3y'),
        ] as $what => $make) {
            try {
                $make();
                self::fail($what . ' was accepted.');
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('EXAMPLE_KEPT_FOR', $refused->getMessage(), $what);
            }
        }
        self::assertSame('1 year', Duration::parse((string) (new Setting('EXAMPLE_KEPT_FOR', 'Kept for', SettingType::Duration, '1d', longestTime: '1y'))->withDefault('2w')->longestTime)->words(), 'withDefault keeps the longest.');
    }
}
