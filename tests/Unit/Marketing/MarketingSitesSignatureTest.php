<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\MarketingSettingGroup;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\Sites\SiteForward;
use CattoPHP\Marketing\Sites\SiteRefused;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Settings\SettingType;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Webhooks\WebhookSignature;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * What two connected sites sign and check, needing no database: the forward's payload, a signature with the current or
 * the previous secret, the time allowed, a changed byte refused with why; an address as a site's is kept; a secret made
 * here; and the first wait between tries being a setting.
 */
final class MarketingSitesSignatureTest extends TestCase
{
    private const SECRET = 'a-shared-secret-of-thirty-two-or-more-characters';
    private const NEW_SECRET = 'another-shared-secret-of-thirty-two-or-more-chars';

    private Foundations $site;
    private Sites $sites;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->site = new Foundations(settingsProviders: [new MarketingSettingGroup()]);
        // A connection never opened: checking a signature writes nothing unless a new secret is confirmed.
        $this->sites = new Sites(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'never_opened']), $this->site->cipher,
            new MarketingSettings($this->site->settings), $this->site->clock);
        $this->now = new DateTimeImmutable('2026-11-14 09:30:00 UTC');
    }

    public function testThePayloadIsBase64urlJsonThatReadsBackAndRefusesAnythingElse(): void
    {
        $payload = SiteForward::payload(['v' => 1, 'site' => 'https://example.com', 'campaign' => 'law-nov26', 'name' => 'Law, November', 'publication' => 'em1', 'channel' => 'email',
            'click' => 'k2m9q4d7xa']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $payload, 'Safe in an address with no escaping.');
        self::assertSame(['v' => 1, 'site' => 'https://example.com', 'campaign' => 'law-nov26', 'name' => 'Law, November', 'publication' => 'em1', 'channel' => 'email',
            'click' => 'k2m9q4d7xa'], SiteForward::decode($payload));
        foreach (['', 'not base64!', base64_encode('not json'), SiteForward::payload(['nested' => [[['deep']]]]) . '=', str_repeat('a', 4001)] as $wrong) {
            self::assertNull(SiteForward::decode($wrong), $wrong);
        }
    }

    public function testASignatureChecksWithTheSecretOrThePreviousOneWithinTheTimeAllowed(): void
    {
        $site = $this->connected(self::SECRET);
        $body = SiteForward::payload(['v' => 1, 'click' => 'k2m9q4d7xa']);
        $this->sites->verify($site, WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp()), $body, $this->now);
        $this->sites->verify($site, WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp() - 299), $body, $this->now);
        $this->sites->verify($site, WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp() + 299), $body, $this->now);
        self::assertSame('its time was 7 minutes from this site\'s', $this->refusal($site, WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp() - 420), $body));
        self::assertSame('its time was 7 minutes from this site\'s', $this->refusal($site, WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp() + 400), $body),
            'Ahead or behind, rounded to the minute.');

        // While a new secret is being put in, the previous one is still accepted (the other site may not have the new one yet).
        $changing = $this->connected(self::NEW_SECRET, self::SECRET);
        $this->sites->verify($changing, WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp()), $body, $this->now);
        self::assertSame([self::NEW_SECRET, self::SECRET], $this->sites->secrets($changing), 'Signing uses both, the new one first.');
        self::assertSame([self::SECRET], $this->sites->secrets($site));
        $both = $this->sites->sign($changing, $body, $this->now);
        self::assertSame(2, substr_count($both, 'v1='));
        $this->sites->verify($site, $both, $body, $this->now);
    }

    public function testAChangedByteOrAnotherSecretIsRefusedAndSaysWhyForTheAdministratorOnly(): void
    {
        $site = $this->connected(self::SECRET);
        $body = SiteForward::payload(['v' => 1, 'campaign' => 'law-nov26', 'click' => 'k2m9q4d7xa']);
        $signature = WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp());
        $changed = $body;
        $changed[10] = $changed[10] === 'A' ? 'B' : 'A';
        self::assertSame('its signature did not match', $this->refusal($site, $signature, $changed));
        self::assertSame('its signature did not match', $this->refusal($site, WebhookSignature::sign($body, ['someone-elses-secret-of-thirty-two-characters'], $this->now->getTimestamp()), $body));
        self::assertSame('its signature did not match', $this->refusal($site, '', $body), 'No signature at all.');
        self::assertSame('its signature did not match', $this->refusal($site, 't=' . $this->now->getTimestamp(), $body));
        $tampered = (string) preg_replace('/v1=([0-9a-f])/', 'v1=' . ($signature[strpos($signature, 'v1=') + 3] === 'a' ? 'b' : 'a'), $signature);
        self::assertSame('its signature did not match', $this->refusal($site, $tampered, $body));
        self::assertSame('its signature did not match', $this->refusal($site, WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp() - 3600) . ',t=' . $this->now->getTimestamp(), $body),
            'A second time cannot make an old signature new.');
        self::assertSame('its signature did not match', $this->refusal($site, 't=' . $this->now->getTimestamp() . ',' . WebhookSignature::sign($body, [self::SECRET], $this->now->getTimestamp() - 3600), $body),
            'Nor a fresh time put in front of it.');

        $unreadable = new Site('example-org', 'Example Org', 'https://example.org', 'v1:' . base64_encode(str_repeat('x', 40)));
        self::assertSame('its shared secret here could not be read, so put the secret in again', $this->refusal($unreadable, $signature, $body));
        $this->expectException(\RuntimeException::class);
        $this->sites->secrets($unreadable);
    }

    public function testAnAddressIsKeptAsTheSiteNamesItselfAndASecretIsMadeToCopy(): void
    {
        self::assertSame('https://example.org', Sites::normalise(' https://Example.ORG/ '));
        self::assertSame('https://example.org:8443/shop', Sites::normalise('https://example.org:8443/shop/'));
        foreach (['http://example.org', 'https://', 'https://example.org/?x=1', 'https://example.org/#top', 'https://user:pass@example.org', 'https://exa mple.org', 'example.org',
            'https://example.org/' . str_repeat('a', 300)] as $wrong) {
            self::assertNull(Sites::normalise($wrong), $wrong);
        }
        $made = Sites::makeSecret();
        self::assertMatchesRegularExpression(Sites::SECRET, $made);
        self::assertSame(43, strlen($made));
        self::assertNotSame($made, Sites::makeSecret());
        self::assertSame(['40 seconds', '7 minutes', '2 hours 5 minutes', 'no time'], [Sites::apart(40), Sites::apart(-415), Sites::apart(7500), Sites::apart(0)]);
        self::assertSame(['1 report', '1,200 reports'], [Sites::reports(1), Sites::reports(1200)]);
    }

    public function testTheFirstWaitBetweenTriesIsASettingAsTheOtherLengthsOfTimeAre(): void
    {
        $settings = new MarketingSettings($this->site->settings);
        self::assertSame(['1m', '1h', '5m', '30d'], [$settings->siteRetryFirst()->text, $settings->siteRetryLongest()->text, $settings->siteTolerance()->text,
            $settings->siteReportsKept()->text]);
        self::assertSame('1m', (new MarketingSettings())->siteRetryFirst()->text, 'Without Settings, its starting value.');
        $env = new MarketingSettings((new Foundations(['MARKETING_SITE_RETRY_FIRST' => '30s'], settingsProviders: [new MarketingSettingGroup()]))->settings);
        self::assertSame(30, $env->siteRetryFirst()->lengthInSeconds());
        $group = iterator_to_array((new MarketingSettingGroup())->settingGroups(), false)[0];
        $setting = $group->settings['MARKETING_SITE_RETRY_FIRST'];
        self::assertSame([SettingType::Duration, '1m', '10s'], [$setting->type, $setting->default, $setting->shortest]);
        self::assertContains('MARKETING_SITE_RETRY_FIRST', MarketingSettings::NAMES);
        $this->site->settings->set('MARKETING_SITE_RETRY_FIRST', '2m');
        self::assertSame('2m', $settings->siteRetryFirst()->text);
        $this->expectException(\InvalidArgumentException::class);
        $this->site->settings->set('MARKETING_SITE_RETRY_FIRST', '5s');
    }

    private function connected(string $secret, ?string $previous = null): Site
    {
        return new Site('example-org', 'Example Org', 'https://example.org', $this->site->cipher->encrypt($secret), $previous === null ? null : $this->site->cipher->encrypt($previous));
    }

    private function refusal(Site $site, string $signature, string $body): string
    {
        try {
            $this->sites->verify($site, $signature, $body, $this->now);
        } catch (SiteRefused $refused) {
            self::assertSame($site, $refused->site);

            return $refused->getMessage();
        }
        self::fail('It was accepted.');
    }
}
