<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Protection;

use CattoPHP\Http\VisitorAgent;
use CattoPHP\Protection\Addresses;
use CattoPHP\Protection\Arrival;
use CattoPHP\Protection\LockoutGate;
use CattoPHP\Protection\LockoutTarget;
use CattoPHP\Network\NoNetworkLookup;
use CattoPHP\Protection\PathMask;
use CattoPHP\Protection\ProtectionRules;
use CattoPHP\Protection\ProtectionSettings;
use CattoPHP\Protection\SearchEngineProof;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Site protection's facts, ported from cattolms's tests of them with every case they cover. */
final class ProtectionFactsTest extends TestCase
{
    private const SECRET_PATHS = ['/m/[a-z]/', '/join/', '/leaving/', '/certificates/', '/transcripts/'];

    public function testASecretInAnAddressIsNeverKept(): void
    {
        $mask = new PathMask(self::SECRET_PATHS);
        $hex = '0123456789abcdef0123456789abcdef';
        self::assertSame('/m/u/••••', $mask->masked('/m/u/' . $hex));
        self::assertSame('/m/c/••••/12', $mask->masked('/m/c/' . $hex . '/12'));
        self::assertSame('/m/o/••••.gif', $mask->masked('/m/o/' . $hex . '.gif'));
        self::assertSame('/join/••••', $mask->masked('/join/' . $hex));
        self::assertSame('/leaving/••••/private', $mask->masked('/leaving/abcDEF123/private'), 'a secret path hides even a short token');
        self::assertSame('/certificates/••••/certificate.pdf', $mask->masked('/certificates/' . $hex . '/certificate.pdf'));
        self::assertSame('/transcripts/••••', $mask->masked('/transcripts/' . $hex));
        self::assertSame('/auth/consume', $mask->masked('/auth/consume?token=' . $hex), 'nothing after a question mark is kept');
        self::assertSame('/courses/growing-vegetables', $mask->masked('/courses/growing-vegetables?utm_source=x'));
        self::assertSame('/whatever/••••/x', $mask->masked('/whatever/' . $hex . '/x'), 'anything shaped like a key, wherever it comes');
        self::assertSame('/courses/a-very-long-course-name-with-no-digits-in-it-at-all', $mask->masked('/courses/a-very-long-course-name-with-no-digits-in-it-at-all'));
        self::assertSame('/learn/x/assessment/session/••••', $mask->masked('/learn/x/assessment/session/550e8400-e29b-41d4-a716-446655440000'));
        self::assertSame('/x/••••', $mask->masked('/x/Ab3dE_fG-hIjK1mNoPqRsTuVwXyZ0123456789abcde'));
        self::assertSame('/x/••••', $mask->masked('/x/k7m2x9qa3f9a2c1b7d4e0f1a2b3c4d5e6f'));
        self::assertSame('/leaving/abcDEF123/private', (new PathMask())->masked('/leaving/abcDEF123/private'), 'without secret paths a short token is a page like any other');
        self::assertSame(PathMask::LONGEST, mb_strlen($mask->masked('/' . str_repeat('page/', 200))));
        self::assertSame('/', $mask->masked(''));
    }

    /** Record names in addresses are kept, so the Site Log says which page was asked for. */
    public function testTheKeysThatNameRecordsAreKept(): void
    {
        $mask = new PathMask(self::SECRET_PATHS);
        foreach ([
            '/admin/people/CL2026011510423Z',
            '/account/orders/CL-00000689/documents/INV-00000044',
            '/admin/certificates/CL-2026-3F9A2C1B7D4E',
            '/admin/messages/MSG-000043',
            '/admin/items/growing-vegetables-in-small-spaces-m1-presentation',
            '/admin/items/gdn-101-module-12-practical-work-and-model-answers/slides/s1a2b3',
            '/learn/growing-vegetables-in-small-spaces/module-12-practical-work-and-model-answers-2',
            '/admin/categories/garden-design',
        ] as $path) {
            self::assertSame($path, $mask->masked($path));
        }
    }

    public function testAnIpv6MachineCountsAsItsWholeSlash64(): void
    {
        self::assertSame('198.51.100.23', Addresses::subject('198.51.100.23'));
        self::assertSame('2001:db8:1:2::/64', Addresses::subject('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame(Addresses::subject('2001:db8:1:2::1'), Addresses::subject('2001:db8:1:2:ffff::9'));
        self::assertSame('not an address', Addresses::subject('not an address'));
    }

    public function testRangesHoldTheirAddresses(): void
    {
        self::assertTrue(Addresses::contains('198.51.100.0/24', '198.51.100.200'));
        self::assertFalse(Addresses::contains('198.51.100.0/24', '198.51.101.1'));
        self::assertTrue(Addresses::contains('198.51.100.128/25', '198.51.100.200'));
        self::assertFalse(Addresses::contains('198.51.100.128/25', '198.51.100.20'));
        self::assertTrue(Addresses::contains('2001:db8::/32', '2001:db8:1:2::9'));
        self::assertFalse(Addresses::contains('2001:db8::/32', '198.51.100.1'));
        self::assertTrue(Addresses::contains('198.51.100.23', '198.51.100.23'));
        self::assertTrue(Addresses::contains('2001:db8:1:2::/64', '2001:db8:1:2::/64'), 'a /64 holds itself');
        self::assertTrue(Addresses::listHolds("198.51.100.0/24\n2001:db8::/32, nonsense", '2001:db8:5::1'));
        self::assertFalse(Addresses::listHolds('', '198.51.100.1'));
        self::assertSame(['198.51.100.0/24', '2001:db8::/32'], Addresses::lines("198.51.100.0/24\n2001:db8::/32, nonsense 300.1.1.1"));
        foreach (['198.51.100.1', '198.51.100.0/24', '2001:db8::/128'] as $good) self::assertTrue(Addresses::isAddressOrRange($good), $good);
        foreach (['198.51.100.0/33', '198.51.100.0/0', 'example.com', '198.51.100.0/x'] as $bad) self::assertFalse(Addresses::isAddressOrRange($bad), $bad);
    }

    public function testALocalOrProxyAddressIsPrivateAndADocumentationOneIsNot(): void
    {
        foreach (['127.0.0.1', '10.0.2.100', '192.168.1.1', '172.16.0.1', '::1', 'fd00::1'] as $address) self::assertTrue(Addresses::isPrivate($address), $address);
        foreach (['198.51.100.23', '203.0.113.1', '8.8.8.8', '2001:db8::1', ''] as $address) self::assertFalse(Addresses::isPrivate($address), $address);
        self::assertSame('private', (new NoNetworkLookup())->facts('10.0.2.100')->kind);
        self::assertSame('', (new NoNetworkLookup())->facts('198.51.100.1')->kind);
    }

    public function testWhatALockoutTargetIsReadAs(): void
    {
        self::assertSame(['kind' => 'range', 'network' => '198.51.100.0/24', 'asn' => null, 'label' => '198.51.100.0/24'], LockoutTarget::read('198.51.100.77/24')->toArray(), 'a range starts at its first address');
        self::assertSame(['kind' => 'address', 'network' => '2001:db8:1:2::/64', 'asn' => null, 'label' => '2001:db8:1:2::/64'], LockoutTarget::read('2001:db8:1:2::9')->toArray());
        self::assertSame(['kind' => 'owner', 'network' => null, 'asn' => 64500, 'label' => 'AS64500'], LockoutTarget::read('as64500')->toArray());
        self::assertSame('Example Hosting', LockoutTarget::read('AS64500', 'Example Hosting')->label);
        self::assertSame(['kind' => 'address', 'network' => '198.51.100.7/32', 'asn' => null, 'label' => '198.51.100.7'], LockoutTarget::read('198.51.100.7/32')->toArray());
        foreach (['not an address' => 'Give an address', '198.51.100.1/4' => 'That range is too wide', 'AS' => 'Give an address', '2001:db8::/16' => 'That range is too wide'] as $wrong => $words) {
            try {
                LockoutTarget::read($wrong);
                self::fail('It took ' . $wrong);
            } catch (InvalidArgumentException $refused) {
                self::assertStringStartsWith($words, $refused->getMessage());
            }
        }
    }

    public function testBrowsersAreToldApartFromProgramsByWhatTheySay(): void
    {
        $cases = [
            ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36', VisitorAgent::BROWSER, 'Chrome on Android', 'phone'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', VisitorAgent::BROWSER, 'Safari on iPhone', 'phone'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0', VisitorAgent::BROWSER, 'Firefox on Windows', 'computer'],
            ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36 Edg/139.0.0.0', VisitorAgent::BROWSER, 'Edge on Windows', 'computer'],
            ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1', VisitorAgent::BROWSER, 'Safari on iPad', 'tablet'],
            ['Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 Chrome/61.0.3163.100 Safari/537.36', VisitorAgent::OUTDATED, 'Outdated: Chrome 61', 'computer'],
            ['Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)', VisitorAgent::OUTDATED, 'Outdated: Internet Explorer', 'computer'],
            ['Go-http-client/1.1', VisitorAgent::SCRIPT, 'Script: Go-http-client', ''],
            ['python-requests/2.32.3', VisitorAgent::SCRIPT, 'Script: python-requests', ''],
            ['curl/8.5.0', VisitorAgent::SCRIPT, 'Script: curl', ''],
            ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/139.0.0.0 Safari/537.36', VisitorAgent::SCRIPT, 'Script: HeadlessChrome', ''],
            ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', VisitorAgent::SEARCH, 'Googlebot', ''],
            ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', VisitorAgent::SEARCH, 'bingbot', ''],
            ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)', VisitorAgent::CRAWLER, 'AI crawler: GPTBot', ''],
            ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', VisitorAgent::CRAWLER, 'Crawler: AhrefsBot', ''],
            ['', VisitorAgent::NONE, 'No browser named', ''],
        ];
        foreach ($cases as [$agent, $kind, $label, $device]) {
            $read = VisitorAgent::read($agent);
            self::assertSame($kind, $read->kind, $agent);
            self::assertSame($label, $read->label, $agent);
            self::assertSame($device, $read->device, $agent);
        }
        self::assertTrue(VisitorAgent::read('curl/8.5.0')->isAutomated());
        self::assertFalse(VisitorAgent::read($cases[0][0])->isAutomated());
        self::assertFalse(VisitorAgent::read($cases[5][0])->isAutomated(), 'an old browser is still taken for a person');
    }

    public function testWhereAVisitorCameFromIsTheSiteAlone(): void
    {
        self::assertSame(['google.com', 'search'], Arrival::cameFrom('https://www.google.com/search?q=secret', 'example.test'));
        self::assertSame(['facebook.com', 'social'], Arrival::cameFrom('https://www.facebook.com/some/page', 'example.test'));
        self::assertSame(['mail.example.org', 'email'], Arrival::cameFrom('https://mail.example.org/inbox', 'example.test'));
        self::assertSame(['example.org', 'site'], Arrival::cameFrom('https://www.example.org/a', 'example.test'));
        self::assertSame(['', 'inside'], Arrival::cameFrom('https://example.test/courses', 'example.test'));
        self::assertSame(['', 'direct'], Arrival::cameFrom('', 'example.test'));
    }

    public function testASharedLinkSaysWhereItWasShared(): void
    {
        self::assertSame(['whatsapp', 'share'], Arrival::sharedVia(['utm_medium' => 'share', 'utm_campaign' => 'share', 'utm_source' => 'whatsapp']));
        self::assertSame(['copied_link', 'share'], Arrival::sharedVia(['utm_source' => 'copied_link', 'utm_medium' => 'share']));
        self::assertNull(Arrival::sharedVia(['utm_source' => 'whatsapp', 'utm_medium' => 'social']), 'another campaign is an ordinary visit');
        self::assertNull(Arrival::sharedVia(['utm_source' => 'myspace', 'utm_medium' => 'share']), 'only where a site shares');
        self::assertNull(Arrival::sharedVia(['utm_source' => ['whatsapp'], 'utm_medium' => 'share']));
        self::assertNull(Arrival::sharedVia([]));
        foreach (Arrival::SHARED_ON as $source => $words) self::assertMatchesRegularExpression('/^[a-z_]+$/', $source, $words);
    }

    public function testTheRefusalSaysNothingAtAll(): void
    {
        self::assertStringContainsString('This site is not available.', LockoutGate::PAGE);
        self::assertSame(1, substr_count(strip_tags(LockoutGate::PAGE), 'not available') + substr_count(strip_tags(LockoutGate::PAGE), 'locked'), 'only one sentence, no reason');
        self::assertStringNotContainsString('<link', LockoutGate::PAGE);
        self::assertStringNotContainsString('<script', LockoutGate::PAGE);
        $response = LockoutGate::response();
        self::assertSame([403, 'no-store, private', 'noindex'], [$response->getStatusCode(), $response->headers->get('Cache-Control'), $response->headers->get('X-Robots-Tag')]);
    }

    public function testASearchEngineIsProvedOnlyByItsNameLeadingBack(): void
    {
        $proof = new class extends SearchEngineProof {
            /** @var array<string,string> */
            public array $hosts = ['66.249.66.1' => 'crawl-66-249-66-1.googlebot.com', '203.0.113.5' => 'crawl.googlebot.com.example.net', '203.0.113.6' => 'crawl-6.googlebot.com'];
            /** @var array<string,list<string>> */
            public array $back = ['crawl-66-249-66-1.googlebot.com' => ['66.249.66.1'], 'crawl-6.googlebot.com' => ['198.51.100.6']];

            protected function hostOf(string $address): ?string
            {
                return $this->hosts[$address] ?? null;
            }

            protected function addressesOf(string $host, bool $v6): array
            {
                return $this->back[$host] ?? [];
            }
        };
        self::assertTrue($proof->proves('66.249.66.1', 'Googlebot'));
        self::assertFalse($proof->proves('203.0.113.5', 'Googlebot'), 'a name that only looks like the engine\'s');
        self::assertFalse($proof->proves('203.0.113.6', 'Googlebot'), 'a name that leads to another address');
        self::assertFalse($proof->proves('203.0.113.7', 'Googlebot'), 'no name at all');
        self::assertFalse($proof->proves('66.249.66.1', 'NoSuchEngine'));
    }

    public function testEachRuleSaysWhyItFired(): void
    {
        self::assertSame('Asked for 20 pages that do not exist in 10 minutes.', ProtectionRules::words('missing', 20, '10m'));
        self::assertSame('300 requests in 1 minute.', ProtectionRules::words('flood', 300, '1m'));
        self::assertSame('Tried to register 3 times in 1 hour.', ProtectionRules::words('register', 3, '1h'));
        self::assertSame('Sent a form from a browser years out of date, or from no browser at all.', ProtectionRules::words('old', 1, '10m'));
    }

    /** The starting values a site begins with when .env says nothing, and a broken line falls back to them. */
    public function testTheStartingValues(): void
    {
        $values = ProtectionSettings::fromText(static fn(string $name): string => $name === 'SITE_RULE_FLOOD' ? 'on,many,1m,timeout,10m' : '');
        self::assertSame('120d', $values['log_kept_for']);
        self::assertSame('1y', $values['flags_kept_for']);
        self::assertSame(['on' => true, 'count' => 300, 'within' => '1m', 'then' => 'timeout', 'length' => '10m'], $values['rules']['flood'], 'a broken rule line starts again');
        self::assertSame('flag', $values['rules']['register']['then']);
        self::assertSame([3, '1w', '30d'], [$values['lockout_count'], $values['lockout_within'], $values['lockout_length']]);
        self::assertSame([true, true, true, true, false], [$values['doubles'], $values['spare_administrators'], $values['spare_people'], $values['spare_search_engines'], $values['firewall_link']]);
        self::assertSame(ProtectionSettings::toText($values), ProtectionSettings::toText(ProtectionSettings::fromText(static fn(string $name): string => ProtectionSettings::toText($values)[$name] ?? '')), 'written and read back the same');
        self::assertCount(19, ProtectionSettings::names());
    }
}
