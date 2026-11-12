<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Signup;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Signup\SignupCountries;
use CattoPHP\Signup\SignupSettingGroup;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The allowed and blocked countries: .env's lists, overridden in Settings, with a Reset. */
final class SignupCountriesTest extends TestCase
{
    public function testDotEnvGivesTheListsSettingsOverrideThemAndResetReturnsToDotEnv(): void
    {
        $site = new Foundations(['SIGNUP_ALLOWED_COUNTRIES' => 'za, na', 'SIGNUP_BLOCKED_COUNTRIES' => 'RU;IR xx1'], settingsProviders: [new SignupSettingGroup()]);
        $countries = new SignupCountries($site->settings);
        self::assertSame([['ZA', 'NA'], ['RU', 'IR'], 'environment'], [$countries->allowed(), $countries->blocked(), $countries->source()], 'Anything not two letters is left out.');

        $saved = $countries->save('ZA', 'RU, KP', new Actor('7', 'Ada'));
        self::assertSame(['allowed' => ['ZA'], 'blocked' => ['RU', 'KP']], $saved);
        self::assertSame([['ZA'], ['RU', 'KP'], 'database'], [$countries->allowed(), $countries->blocked(), $countries->source()]);
        self::assertSame(['allowed' => ['ZA', 'NA'], 'blocked' => ['RU', 'IR']], $countries->environmentLists());
        self::assertSame(['SIGNUP_BLOCKED_COUNTRIES', 'SIGNUP_ALLOWED_COUNTRIES'], array_map(static fn($entry): ?string => $entry->subjectId, $site->activityStore->recent(10, 'settings.changed')),
            'Each saved list is in the activity log, by the person who saved it.');
        self::assertSame('7', $site->activityStore->recent(1, 'settings.changed')[0]->actorId);

        $countries->reset();
        self::assertSame([['ZA', 'NA'], 'environment'], [$countries->allowed(), $countries->source()]);
    }

    public function testACountryOnBothListsOrACodeThatIsNotOneIsRefused(): void
    {
        $countries = new SignupCountries((new Foundations(settingsProviders: [new SignupSettingGroup()]))->settings);
        try {
            $countries->save('ZA, RU', 'RU');
            self::fail('A country on both lists was saved.');
        } catch (InvalidArgumentException $both) {
            self::assertSame('Russia is on both lists. Put it on one.', $both->getMessage());
        }
        $this->expectExceptionMessage('"ZZ" is not a country code. Use two letters, such as ZA for South Africa.');
        $countries->save('ZZ', '');
    }

    public function testWithoutSettingsTheListsAreDotEnvsAndCannotBeSaved(): void
    {
        $countries = new SignupCountries(null, new EnvironmentValues(['SIGNUP_ALLOWED_COUNTRIES' => 'ZA']));
        self::assertSame([['ZA'], []], [$countries->allowed(), $countries->blocked()]);
        $this->expectExceptionMessage('Settings cannot be saved here.');
        $countries->save('ZA', '');
    }
}
