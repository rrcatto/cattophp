<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Network;

use CattoPHP\Network\GeoIp;
use CattoPHP\Network\GeoIpNetworkLookup;
use CattoPHP\Network\NetworkFacts;
use CattoPHP\Network\NoNetworkLookup;
use CattoPHP\Network\ReputationLists;
use Geocoder\Collection;
use Geocoder\Model\Address;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The network lookup without a MaxMind database or with a project's own Geocoder provider, and the published lists, ported from cattolms's GeoIP and sign-up checks. */
final class NetworkTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/cattophp-lists-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->folder);
    }

    public function testWithoutADatabaseAPrivateAddressIsSaidSoAndAPublicOneIsUnavailable(): void
    {
        $geo = new GeoIp('/nonexistent/GeoLite2-City.mmdb');
        self::assertFalse($geo->isAvailable());
        self::assertSame('Local/private', $geo->place('127.0.0.1'));
        self::assertSame('GeoIP unavailable', $geo->place('8.8.8.8'));
        self::assertSame('Invalid IP', $geo->place('not an address'));
        self::assertSame('', $geo->countryCode('8.8.8.8'));
        self::assertSame('🇿🇦', GeoIp::flag('ZA'));
        self::assertSame('🇬🇧', GeoIp::flag('gb'));
        self::assertSame('', GeoIp::flag('invalid'));
    }

    /** A project names its own Geocoder provider (a web service, a chain): the place and the country come from it. */
    public function testAProjectsOwnGeocoderProviderGivesThePlaceAndTheCountry(): void
    {
        $provider = new class implements Provider {
            public function geocodeQuery(GeocodeQuery $query): Collection
            {
                if ($query->getText() === '198.51.100.9') {
                    throw new RuntimeException('the service is down');
                }

                return new AddressCollection([Address::createFromArray(['providedBy' => 'example', 'locality' => 'Durban', 'adminLevels' => [['name' => 'KwaZulu-Natal', 'level' => 1]],
                    'country' => 'South Africa', 'countryCode' => 'ZA'])]);
            }

            public function reverseQuery(ReverseQuery $query): Collection
            {
                return new AddressCollection([]);
            }

            public function getName(): string
            {
                return 'example';
            }
        };
        $geo = new GeoIp('', $provider);
        self::assertTrue($geo->isAvailable(), 'Available without a database.');
        self::assertSame('Durban, KwaZulu-Natal, 🇿🇦 South Africa', $geo->place('203.0.113.9'));
        self::assertSame('ZA', $geo->countryCode('203.0.113.9'));
        self::assertSame(['Location unavailable', ''], [$geo->place('198.51.100.9'), $geo->countryCode('198.51.100.9')], 'A failed lookup never breaks a page.');
        self::assertSame(['Local/private', ''], [$geo->place('10.0.2.100'), $geo->countryCode('10.0.2.100')], 'A private address is never asked about.');
        self::assertSame('ZA', (new GeoIpNetworkLookup($geo))->facts('203.0.113.9')->countryCode);
    }

    public function testTheListsFindTorExitsVpnAndHostingRangesAndThrowawayDomains(): void
    {
        $lists = new ReputationLists($this->folder);
        self::assertFalse($lists->isTor('185.220.101.1'), 'A list not fetched yet finds nothing.');
        self::assertSame(2, $lists->store('tor', 'addresses', "# exits\n185.220.101.1\n185.220.101.2\n185.220.101.1\n"));
        self::assertSame(2, $lists->store('vpn', 'ranges', "45.9.20.0/24\n45.9.21.0/24\n10.0.0.0/8 # private\n not a range\n"), 'Touching ranges merge.');
        $lists->store('datacenter', 'ranges', "198.51.100.0/24\n");
        $lists->store('disposable', 'domains', "Mailinator.com\n");
        self::assertTrue($lists->isTor('185.220.101.2'));
        self::assertTrue($lists->isVpn('45.9.21.200'));
        self::assertFalse($lists->isVpn('45.9.22.1'));
        self::assertFalse($lists->isVpn('2001:db8::1'), 'Only IPv4 ranges are listed.');
        self::assertTrue($lists->isHosting('198.51.100.60'));
        self::assertTrue($lists->isDisposable('mailinator.com'));
        self::assertTrue($lists->isDisposable('eu.Mailinator.com'), 'A subdomain of a throwaway domain is one too.');
        self::assertFalse($lists->isDisposable('example.com'));
        self::assertSame(['label' => 'VPN networks', 'entries' => 2], array_intersect_key($lists->status()['vpn'], ['label' => 1, 'entries' => 1]));
        $this->expectExceptionMessage('the list was empty');
        $lists->store('tor', 'addresses', "# nothing\n");
    }

    public function testUpdatingFetchesWhatIsDueAndKeepsTheLastCopyOfWhatFails(): void
    {
        $asked = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$asked): MockResponse {
            $asked[] = $url;

            return str_contains($url, 'torproject') ? new MockResponse("185.220.101.1\n") : new MockResponse('', ['http_code' => 503]);
        });
        $lists = new ReputationLists($this->folder, $http);
        $done = $lists->update();
        self::assertSame('1 entries', $done['tor']);
        self::assertSame('not fetched (HTTP 503)', $done['vpn']);
        self::assertCount(4, $asked);
        self::assertSame('up to date', $lists->update()['tor'], 'A list fetched today is not fetched again.');
        self::assertSame('not fetched (no HTTP client)', (new ReputationLists($this->folder))->update(true)['tor']);
        self::assertTrue((new ReputationLists($this->folder))->isTor('185.220.101.1'), 'The copy kept is still read.');
    }

    public function testTheLookupTellsEachListAndTheOneKindTheSiteLogShows(): void
    {
        $lists = new ReputationLists($this->folder);
        $lists->store('tor', 'addresses', "198.51.100.7\n");
        $lists->store('datacenter', 'ranges', "198.51.100.0/24\n");
        $lookup = new GeoIpNetworkLookup(new GeoIp(''), $lists);
        $facts = $lookup->facts('198.51.100.7');
        self::assertSame(['tor', true, false, true, '198.51.100.7', false], [$facts->kind, $facts->tor, $facts->vpn, $facts->hosting, $facts->address, $facts->geoAvailable],
            'A Tor exit inside a hosting network is both, and Tor to the Site Log.');
        self::assertSame('hosting', $lookup->facts('198.51.100.8')->kind);
        self::assertSame('home', $lookup->facts('203.0.113.9')->kind);
        self::assertSame(['private', false], [$lookup->facts('10.0.2.100')->kind, $lookup->facts('10.0.2.100')->isPublic()]);
        self::assertSame('', $lookup->facts('')->kind);
        self::assertSame(['geo' => false, 'network' => false], $lookup->available());
        self::assertSame('203.0.113.0/24', $lookup->rangeOf('203.0.113.9'), 'Without the ASN database, its /24.');
        self::assertSame('2001:db8:1::/48', $lookup->rangeOf('2001:db8:1:2::5'));
        self::assertSame('', $lookup->rangeOf('192.168.1.1'));
        self::assertSame('GeoIP unavailable', $lookup->place('203.0.113.9'));
        self::assertSame('private', (new NoNetworkLookup())->facts('10.0.2.100')->kind);
        self::assertSame('', (new NoNetworkLookup())->facts('198.51.100.1')->kind);
        self::assertSame(['tor', 'vpn', 'hosting', 'home'], [NetworkFacts::kindOf(true, true, true), NetworkFacts::kindOf(false, true, true), NetworkFacts::kindOf(false, false, true), NetworkFacts::kindOf(false, false, false)]);
        self::assertSame('home', GeoIpNetworkLookup::at('', $this->folder)->facts('203.0.113.9')->kind, 'Made from places alone, before a container.');
    }
}
