<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use CattoPHP\Installation\Installation;
use CattoPHP\Runtime\SiteMode;

/** The settings of CattoPHP's own foundations: the installation, demo or live, mail and sign-in. */
final class FoundationSettings implements SettingsProvider
{
    public function __construct(private readonly bool $siteMode = true, private readonly bool $signIn = true)
    {
    }

    public function settingGroups(): iterable
    {
        yield new SettingGroup('installation', 'This installation', 'Its name, the organisation behind it, its address, its administrator, its time zone and its language.', [
            new Setting('APP_NAME', 'Name', help: 'Shown in page titles and emails.'),
            new Setting('APP_ORGANISATION', 'Organisation', optional: true),
            new Setting('APP_URL', 'Public address', SettingType::Address, help: 'The address people open it at, such as https://example.com. Links in emails start with it.'),
            new Setting('APP_ADMIN_EMAIL', 'Administrator\'s email address', SettingType::Email),
            new Setting('APP_TIMEZONE', 'Time zone', SettingType::TimeZone, 'UTC', 'Every time shown is in this time zone, such as Africa/Johannesburg.'),
            new Setting('APP_LOCALE', 'Language and region', default: Installation::LOCALE, help: 'How amounts of money are written and the language the pages say they are in, as a locale: en_GB, or en_ZA for South Africa.', longest: 20),
        ]);
        if ($this->siteMode) {
            yield new SettingGroup('site_mode', 'Demo or live', 'A demo site says so on every page and in every email, and asks search engines not to list it.', [
                new Setting('APP_SITE_MODE', 'Site mode', SettingType::Choice, SiteMode::DEMO, choices: [SiteMode::DEMO => 'Demo', SiteMode::LIVE => 'Live']),
                new Setting('APP_DEMO_HEADING', 'The heading on a demo site', default: SiteMode::HEADING, longest: 60),
                new Setting('APP_DEMO_NOTICE', 'The notice on every page of a demo site', SettingType::Paragraph, SiteMode::NOTICE, longest: 300),
                new Setting('APP_DEMO_EMAIL_NOTICE', 'The first line of every email from a demo site', SettingType::Paragraph, SiteMode::EMAIL_NOTICE, longest: 400),
            ]);
        }
        yield new SettingGroup('mail', 'Mail', 'How email is sent, and who it comes from. On a server it goes through the server\'s own mail system or a smarthost; a development machine catches it in Mailpit.', [
            new Setting('MAILER_DSN', 'Mail server', SettingType::MailTransport, help: 'Such as smtp://mail.example.com:587, with a user name and password when it needs them.', secret: true),
            new Setting('MAIL_FROM_ADDRESS', 'From address', SettingType::Email),
            new Setting('MAIL_FROM_NAME', 'From name', optional: true, help: 'The installation\'s name when blank.'),
            new Setting('MAIL_REPLY_TO', 'Reply-to address', SettingType::Email, optional: true),
        ]);
        if ($this->signIn) {
            yield new SettingGroup('sign_in', 'Sign-in', 'Emailed sign-in links, who may sign in, two-step sign-in and how long a sign-in lasts.', [
                new Setting('AUTH_SIGNIN', 'Who can sign in', SettingType::Choice, 'ADMIN', choices: ['ADMIN' => 'Administrators and the listed addresses', 'EVERYONE' => 'Everyone with an account']),
                new Setting('AUTH_SIGNIN_ALLOWED_EMAILS', 'Listed addresses', SettingType::Paragraph, optional: true, help: 'Email addresses that may sign in when only administrators can, separated by commas.'),
                new Setting('AUTH_TWO_STEP', 'Two-step sign-in', SettingType::Choice, 'CHOICE', choices: ['CHOICE' => 'Each person\'s choice', 'ADMINS' => 'Required of administrators', 'EVERYONE' => 'Required of everyone']),
                new Setting('AUTH_MAGIC_LINK_TTL', 'How long a sign-in link works', SettingType::Duration, '30m', shortest: '1m'),
                new Setting('AUTH_SESSION_TTL', 'How long a sign-in lasts', SettingType::Duration, '1d', help: 'A person is signed out after this long without a visit. Coming back sooner, or leaving a page open that refreshes itself, keeps them signed in; closing the browser does not sign them out.', shortest: '5m', longestTime: '365d'),
                new Setting('AUTH_REQUEST_LIMIT_EMAIL', 'Links one address may ask for', SettingType::Number, '5', min: 1, max: 1000),
                new Setting('AUTH_REQUEST_LIMIT_IP', 'Links one network address may ask for', SettingType::Number, '20', min: 1, max: 10000),
                new Setting('AUTH_REQUEST_WINDOW', 'In how long', SettingType::Duration, '15m', shortest: '1m'),
            ]);
        }
    }
}
