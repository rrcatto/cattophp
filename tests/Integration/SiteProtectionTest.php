<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Http\LiveRefresh;
use CattoPHP\Http\VisitorAgent;
use CattoPHP\Protection\Addresses;
use CattoPHP\Protection\Flags;
use CattoPHP\Protection\FormGuard;
use CattoPHP\Protection\LockoutGate;
use CattoPHP\Protection\Lockouts;
use CattoPHP\Protection\LockoutService;
use CattoPHP\Protection\LockoutTarget;
use CattoPHP\Network\NetworkFacts;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\Protection\PathMask;
use CattoPHP\Protection\ProtectionRetention;
use CattoPHP\Protection\ProtectionRules;
use CattoPHP\Protection\ProtectionSettingGroup;
use CattoPHP\Protection\ProtectionSettings;
use CattoPHP\Protection\RefusedEmails;
use CattoPHP\Protection\RequestRecorder;
use CattoPHP\Protection\SearchEngineProof;
use CattoPHP\Protection\ServerLink;
use CattoPHP\Protection\SiteLog;
use CattoPHP\Protection\VisitorAccounts;
use CattoPHP\Settings\DbalSettingsStore;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** The Site Log, the rules, flags, time-outs and lockouts, the gate, the form guard and the firewall link, ported from cattolms's tests. */
final class SiteProtectionTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36';

    private Foundations $site;
    private ProtectionSettings $settings;
    private SiteLog $log;
    private Flags $flags;
    private Lockouts $lockouts;
    private ProtectionRules $rules;
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations(settingsStore: new DbalSettingsStore($this->connection), settingsProviders: [new ProtectionSettingGroup()]);
        $this->settings = new ProtectionSettings($this->site->settings);
        $this->log = new SiteLog($this->connection);
        $this->flags = new Flags($this->connection);
        $this->lockouts = new Lockouts($this->connection);
        $this->folder = Foundations::folder();
        $this->rules = new ProtectionRules($this->log, $this->settings, $this->flags, $this->lockouts, new ServerLink($this->folder . '/server-firewall'), new class extends SearchEngineProof {
            public function proves(string $address, string $engine): bool
            {
                return $address === '66.249.66.1';
            }
        });
    }

    protected function tearDown(): void
    {
        Foundations::remove($this->folder);
        parent::tearDown();
    }

    public function testPagesThatDoNotExistFlagAndTimeOutAnAddress(): void
    {
        $this->missing('198.51.100.23', 19);
        $this->rules->run();
        self::assertNull($this->flags->findBySubject('198.51.100.23'), 'one under the limit does nothing');

        $this->request('198.51.100.23', '/old/last', 404);
        self::assertSame('0 flagged, 1 timed out, 0 locked out, 0 released at the firewall, 0 search engines checked.', $this->rules->run(), 'flagged and timed out, counted as a time-out');
        $flag = $this->flags->findBySubject('198.51.100.23');
        self::assertNotNull($flag);
        self::assertSame('high', $flag['level']);
        self::assertSame('Asked for 20 pages that do not exist in 10 minutes.', Flags::why($flag)[0]['words']);
        $force = $this->lockouts->inForceFor('198.51.100.23', null);
        self::assertSame('timeout', $force['which'] ?? null);
        self::assertTrue($force['automatic']);
        self::assertEqualsWithDelta(3600, strtotime((string) $force['until_at']) - strtotime((string) $force['started_at']), 5);
        self::assertSame('waiting', $this->stateOf('198.51.100.23'));

        $this->rules->run();
        self::assertSame(1, $this->inForce('198.51.100.23'), 'the same requests are not counted twice');
    }

    public function testEachNewTimeOutDoublesAndThreeLockItOut(): void
    {
        $this->missing('198.51.100.24');
        $this->rules->run();
        $this->endEverythingOn('198.51.100.24');
        $this->missing('198.51.100.24');
        $this->rules->run();
        $second = $this->lockouts->inForceFor('198.51.100.24', null);
        self::assertSame('timeout', $second['which'] ?? null);
        self::assertEqualsWithDelta(7200, strtotime((string) $second['until_at']) - strtotime((string) $second['started_at']), 5, 'it came back, so the time doubled');
        self::assertStringContainsString('came back', (string) $second['why']);

        $this->endEverythingOn('198.51.100.24');
        $this->missing('198.51.100.24');
        $this->rules->run();
        $third = $this->lockouts->inForceFor('198.51.100.24', null);
        self::assertSame('lockout', $third['which'] ?? null);
        self::assertTrue($third['automatic']);
        self::assertSame('Timed out 3 times within 1 week.', $third['why']);
        self::assertEqualsWithDelta(30 * 86400, strtotime((string) $third['until_at']) - strtotime((string) $third['started_at']), 5);
        self::assertSame('locked', $this->stateOf('198.51.100.24'));
    }

    public function testTheLongestTimeOutIsNeverPassed(): void
    {
        $form = $this->formFrom($this->settings->values());
        $form['longest'] = '90m';
        $form['lockout_count'] = '9';
        $this->settings->save($form);
        for ($round = 0; $round < 3; $round++) {
            $this->missing('198.51.100.25');
            $this->rules->run();
            $force = (array) $this->lockouts->inForceFor('198.51.100.25', null);
            self::assertLessThanOrEqual(5400 + 5, strtotime((string) $force['until_at']) - strtotime((string) $force['started_at']));
            $this->endEverythingOn('198.51.100.25');
        }
    }

    public function testASignedInPersonsAddressIsFlaggedAndNeverTimedOut(): void
    {
        $this->request('203.0.113.140', '/account', 200, 'GET', self::BROWSER, ['account_id' => '41', 'visitor' => 'signed_in']);
        $this->missing('203.0.113.140');
        $this->rules->run();
        self::assertSame(0, $this->inForce('203.0.113.140'), 'mobile networks share addresses among many people');
        $flag = $this->flags->findBySubject('203.0.113.140');
        self::assertNotNull($flag);
        self::assertStringContainsString('only flagged, not timed out', Flags::why($flag)[0]['words']);
    }

    public function testAdministratorsTheNeverListProvedSearchEnginesAndPrivateAddressesAreLeftAlone(): void
    {
        $this->request('203.0.113.141', '/admin', 200, 'GET', self::BROWSER, ['by_administrator' => true]);
        $this->missing('203.0.113.141');
        $form = $this->formFrom($this->settings->values());
        $form['never_lock_out'] = "203.0.113.128/28\n2001:db8:10::/48";
        $this->settings->save($form);
        $this->missing('203.0.113.130');
        $this->missing('203.0.113.142');
        $this->connection->executeStatement("UPDATE cattophp_site_requests SET agent_kind = 'search', agent_label = 'Googlebot', agent_proved = TRUE WHERE address = '203.0.113.142'");
        $this->missing('10.0.2.100');
        $this->rules->run();
        foreach (['203.0.113.141', '203.0.113.130', '203.0.113.142', '10.0.2.100'] as $address) {
            self::assertSame(0, $this->inForce($address), $address);
            self::assertNull($this->flags->findBySubject($address), $address);
        }
    }

    public function testAnAddressCallingItselfASearchEngineIsAskedAboutOnce(): void
    {
        $googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $this->request('66.249.66.1', '/', 200, 'GET', $googlebot);
        $this->request('203.0.113.9', '/', 200, 'GET', $googlebot);
        self::assertStringEndsWith('2 search engines checked.', $this->rules->run());
        self::assertTrue($this->log->isProvedSearchEngine('66.249.66.1'));
        self::assertFalse($this->log->isProvedSearchEngine('203.0.113.9'), 'a script calling itself Googlebot');
        self::assertFalse($this->log->earlierProof('203.0.113.9'));
        self::assertStringEndsWith('0 search engines checked.', $this->rules->run(), 'asked once');
    }

    public function testRulesThatOnlyFlagAndRulesTurnedOff(): void
    {
        for ($i = 0; $i < 3; $i++) $this->request('203.0.113.60', '/register', 302, 'POST', self::BROWSER, ['form' => 'register']);
        $this->rules->run();
        self::assertSame(0, $this->inForce('203.0.113.60'));
        self::assertSame('Tried to register 3 times in 1 hour.', Flags::why((array) $this->flags->findBySubject('203.0.113.60'))[0]['words']);
        $this->rules->run();
        self::assertCount(1, Flags::why((array) $this->flags->findBySubject('203.0.113.60')), 'a rule still firing inside its window adds nothing more');

        $form = $this->formFrom($this->settings->values());
        unset($form['rule']['missing']['on']);
        $this->settings->save($form);
        $this->missing('203.0.113.61', 40);
        $this->rules->run();
        self::assertNull($this->flags->findBySubject('203.0.113.61'));
    }

    public function testSignInLinksForDifferentAddressesAndFormsFromBots(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $who) $this->request('203.0.113.70', '/login', 302, 'POST', self::BROWSER, ['form' => 'sign_in', 'form_email_hash' => hash('sha256', $who)]);
        foreach (['a', 'a', 'a', 'a', 'a', 'a'] as $who) $this->request('203.0.113.71', '/login', 302, 'POST', self::BROWSER, ['form' => 'sign_in', 'form_email_hash' => hash('sha256', $who)]);
        $this->rules->run();
        self::assertNull($this->flags->findBySubject('203.0.113.70'), 'four addresses is under the limit');
        self::assertNull($this->flags->findBySubject('203.0.113.71'), 'one address again and again is someone waiting for their email');
        $this->request('203.0.113.70', '/login', 302, 'POST', self::BROWSER, ['form' => 'sign_in', 'form_email_hash' => hash('sha256', 'e')]);
        $this->request('203.0.113.72', '/contact', 302, 'POST', 'Go-http-client/1.1', ['form' => 'contact', 'form_signal' => 'trap', 'form_refused' => true, 'form_email' => 'x@example.net']);
        $this->request('203.0.113.73', '/login', 302, 'POST', 'curl/8.5.0', ['form' => 'sign_in', 'form_signal' => 'script', 'form_refused' => true]);
        $this->request('203.0.113.75', '/login', 302, 'POST', self::BROWSER, ['form' => 'sign_in', 'form_signal' => 'suspect_network', 'form_refused' => true]);
        $this->rules->run();
        self::assertSame(1, $this->inForce('203.0.113.70'));
        self::assertEqualsWithDelta(86400, $this->lengthOf('203.0.113.72'), 5, 'the hidden field: a day');
        self::assertSame(0, $this->inForce('203.0.113.73'), 'a script: flagged only');
        self::assertNotNull($this->flags->findBySubject('203.0.113.73'));
        self::assertNotNull($this->flags->findBySubject('203.0.113.75'), 'a network the server marked counts as a script');
    }

    public function testFalseFormTokensAreCounted(): void
    {
        for ($i = 0; $i < 10; $i++) $this->request('203.0.113.74', '/login', 400, 'POST');
        $this->rules->run();
        self::assertSame(1, $this->inForce('203.0.113.74'));
        self::assertStringContainsString('form token', (string) ($this->lockouts->inForceFor('203.0.113.74', null)['why'] ?? ''));
    }

    public function testNotAProblemLeavesAnAddressAlone(): void
    {
        $this->missing('198.51.100.30');
        $this->rules->run();
        $this->service()->markNotAProblem('198.51.100.30', new Actor('7', 'Ada'));
        self::assertSame(0, $this->inForce('198.51.100.30'), 'what the site did by itself ends');
        self::assertSame('fine', $this->stateOf('198.51.100.30'));
        self::assertSame('7', $this->flags->findBySubject('198.51.100.30')['decided_by'] ?? null);
        self::assertSame(['address' => '198.51.100.30'], $this->site->activity->recent(1, 'site.flag_not_a_problem')[0]->details);
        $this->missing('198.51.100.30');
        $this->rules->run();
        self::assertSame(0, $this->inForce('198.51.100.30'));
        self::assertTrue($this->flags->isFine('198.51.100.30', ProtectionSettings::FINE_DAYS));
        $this->connection->executeStatement("UPDATE cattophp_site_flags SET decided_at = NOW() - INTERVAL '31 days' WHERE subject = '198.51.100.30'");
        $this->rules->run();
        self::assertSame(1, $this->inForce('198.51.100.30'), 'after the days have passed it can be flagged again');
        self::assertNotSame('fine', $this->stateOf('198.51.100.30'));
    }

    public function testTheGateRefusesEveryPageButNeverAWebhookAndCountsWithoutFillingTheLog(): void
    {
        $this->lock(['network' => '198.51.100.40']);
        $range = $this->lock(['kind' => 'range', 'network' => '203.0.113.0/28']);
        $six = $this->lock(['network' => '2001:db8:1:2::/64', 'which' => 'timeout', 'rule_key' => 'missing', 'automatic' => true, 'until' => new DateTimeImmutable('+1 hour')]);
        $gate = LockoutGate::beforeTheContainer($this->connection, '', paths: new PathMask(['/m/[a-z]/']));

        self::assertTrue($gate->refuses('198.51.100.40', '/', 'GET', 'curl/8.5.0'));
        self::assertTrue($gate->refuses('198.51.100.40', '/admin', 'POST', ''));
        self::assertFalse($gate->refuses('198.51.100.41', '/'), 'another address');
        self::assertFalse($gate->refuses('198.51.100.40', '/webhooks/payments', 'POST'), 'a webhook carries its own signature');
        self::assertFalse($gate->refuses('', '/'), 'no address is no visitor');
        self::assertTrue($gate->refuses('203.0.113.9', '/m/u/abc'), 'inside the range');
        self::assertFalse($gate->refuses('203.0.113.16', '/courses'), 'outside the range');
        self::assertTrue($gate->refuses('2001:db8:1:2:aaaa::1', '/'), 'anywhere in the /64');
        self::assertFalse($gate->refuses('2001:db8:1:3::1', '/'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT refused FROM cattophp_site_lockouts WHERE id = :id', ['id' => $range]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT refused FROM cattophp_site_lockouts WHERE id = :id', ['id' => $six]));
        self::assertSame(['403', '/m/u/••••', 'automated'], array_map('strval', array_values($this->connection->fetchAssociative("SELECT status, path, visitor FROM cattophp_site_requests WHERE address = '203.0.113.9' AND refused") ?: [])));

        // A flood: the counter goes on, the log stops.
        $this->connection->executeStatement('UPDATE cattophp_site_lockouts SET logged_day = CURRENT_DATE, logged_on_day = :limit WHERE id = :id', ['limit' => LockoutGate::LOGGED_A_DAY, 'id' => $range]);
        $before = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_site_requests');
        self::assertTrue($gate->refuses('203.0.113.9', '/courses'));
        self::assertSame($before, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_site_requests'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT refused FROM cattophp_site_lockouts WHERE id = :id', ['id' => $range]));

        // On Never lock out, not refused whatever is in force: .env's list, then what Settings saved.
        self::assertFalse(LockoutGate::beforeTheContainer($this->connection, '198.51.100.0/24')->refuses('198.51.100.40', '/'));
        $form = $this->formFrom($this->settings->values());
        $form['never_lock_out'] = '198.51.100.40';
        $this->settings->save($form);
        self::assertFalse($gate->refuses('198.51.100.40', '/'));
        self::assertFalse((new LockoutGate($this->lockouts, $this->log, $this->network(), new PathMask(), $this->settings))->refuses('198.51.100.40', '/'), 'the gate with the settings, as the container gives it');
    }

    public function testANetworkOwnerIsRefusedByItsNumberAndTheLogKeepsWhatItKnows(): void
    {
        $this->lock(['kind' => 'owner', 'network' => null, 'asn' => 64511, 'label' => 'Example Hosting BV']);
        $gate = new LockoutGate($this->lockouts, $this->log, $this->network(), new PathMask(), $this->settings);
        self::assertTrue($gate->refuses('198.51.100.200', '/', 'GET', self::BROWSER), 'an address of that owner');
        self::assertFalse($gate->refuses('203.0.113.200', '/'), 'another owner\'s');
        self::assertSame(['NL', 'hosting', '64511', 'Example Hosting BV'], array_map('strval', array_values($this->connection->fetchAssociative("SELECT country_code, network_kind, network_asn, network_name FROM cattophp_site_requests WHERE address = '198.51.100.200'") ?: [])), 'from the network lookup, the log knowing nothing of it');
    }

    public function testAnEndedOrLiftedLockoutNoLongerRefuses(): void
    {
        $gate = LockoutGate::beforeTheContainer($this->connection, '');
        $ended = ['network' => '198.51.100.50', 'which' => 'timeout', 'rule_key' => 'missing', 'automatic' => true, 'until' => new DateTimeImmutable('-1 minute')];
        $this->lock($ended);
        self::assertFalse($gate->refuses('198.51.100.50', '/'));
        $id = $this->lock(['until' => null] + $ended);
        self::assertTrue($gate->refuses('198.51.100.50', '/'));
        self::assertTrue($this->lockouts->lift($id, null));
        self::assertFalse($gate->refuses('198.51.100.50', '/'));
        self::assertFalse($this->lockouts->lift($id, null), 'lifted once only');
    }

    public function testYouCannotLockOutYourselfOrYourOwnConnections(): void
    {
        $service = $this->service('198.51.100.60');
        foreach (['198.51.100.60', '198.51.100.0/24'] as $own) {
            $this->refusedWith('You are using', fn() => $service->lockOut(LockoutTarget::read($own), '1d', 'Testing', false, new Actor('7', 'Ada')));
        }
        $this->refusedWith('You are using an address of this network owner now', fn() => $service->lockOut(LockoutTarget::read('AS64511'), '1d', 'Testing', false, new Actor('7', 'Ada')));
        $form = $this->formFrom($this->settings->values());
        $form['never_lock_out'] = '203.0.113.0/28';
        $this->settings->save($form);
        foreach (['203.0.113.5', '203.0.113.0/24'] as $listed) {
            $this->refusedWith('is on your Never lock out list', fn() => $service->lockOut(LockoutTarget::read($listed), '1d', 'Testing', false, new Actor('7', 'Ada')));
        }
        $this->refusedWith('Say why', fn() => $service->lockOut(LockoutTarget::read('198.51.101.1'), '1d', ' ', false, new Actor('7', 'Ada')));
        $this->refusedWith('Choose how long', fn() => $service->lockOut(LockoutTarget::read('198.51.101.1'), '2d', 'Testing', false, new Actor('7', 'Ada')));
        $this->refusedWith('That lockout could not be found.', fn() => $service->lift(999999, new Actor('7', 'Ada')));
    }

    public function testLockingOutByHandAddressRangeAndOwner(): void
    {
        $service = $this->service('192.0.2.1');
        $service->lockOut(LockoutTarget::read('198.51.100.70'), '1h', 'Probing', false, new Actor('7', 'Ada'));
        $force = (array) $this->lockouts->inForceFor('198.51.100.70', null);
        self::assertSame(['lockout', false, '7'], [$force['which'], $force['automatic'], $force['created_by']]);
        self::assertSame('locked', $this->stateOf('198.51.100.70'));
        self::assertSame('Locked out by hand: Probing', Flags::why((array) $this->flags->findBySubject('198.51.100.70'))[0]['words']);

        // A new lockout of the same thing replaces the one in force.
        $service->lockOut(LockoutTarget::read('198.51.100.70'), 'good', 'For good now', false, new Actor('7', 'Ada'));
        self::assertSame(1, $this->inForce('198.51.100.70'));
        self::assertNull(((array) $this->lockouts->inForceFor('198.51.100.70', null))['until_at']);
        $range = $service->lockOut(LockoutTarget::read('203.0.113.77/24'), '1w', 'A whole range', false, new Actor('7', 'Ada'));
        $owner = $service->lockOut(LockoutTarget::read('AS64512', 'Another Network'), '30d', 'A whole network', false, new Actor('7', 'Ada'));
        self::assertSame('203.0.113.0/24', $this->lockouts->find($range)['network_text'] ?? null);
        self::assertSame(['owner', 64512, 'Another Network'], [$this->lockouts->find($owner)['kind'] ?? null, (int) ($this->lockouts->find($owner)['asn'] ?? 0), $this->lockouts->find($owner)['label'] ?? null]);
        self::assertSame(['force' => 3, 'auto' => 0, 'you' => 3, 'ended' => 1], $this->lockouts->counts());
        self::assertCount(1, $this->lockouts->page('ended', 10, 0));
        self::assertCount(2, $this->lockouts->forSubject('198.51.100.70'));
        $service->lift($range, new Actor('8', 'Bea'));
        self::assertSame('8', $this->lockouts->find($range)['lifted_by'] ?? null);
        self::assertSame(['site.locked_out', 'site.locked_out', 'site.locked_out', 'site.locked_out', 'site.lockout_lifted'], array_reverse(array_map(static fn($entry): string => $entry->action, $this->site->activity->recent(10))));
        $this->refusedWith('That lockout has already ended.', fn() => $service->lift($range, new Actor('8', 'Bea')));
    }

    public function testAFirewallTickWritesARequestAndALiftReleasesIt(): void
    {
        $server = new ServerLink($this->folder . '/server-firewall');
        $service = $this->service('192.0.2.1', $server);
        // Not switched on: the tick does nothing.
        $service->lockOut(LockoutTarget::read('198.51.100.80'), '1h', 'Testing', true, new Actor('7', 'Ada'));
        self::assertFileDoesNotExist($this->folder . '/server-firewall/requests.log');
        $form = $this->formFrom($this->settings->values());
        $form['firewall_link'] = '1';
        $this->settings->save($form);
        $id = $service->lockOut(LockoutTarget::read('198.51.100.81'), '1h', 'Testing', true, new Actor('7', 'Ada'));
        $service->lockOut(LockoutTarget::read('AS64512'), '1h', 'Testing', true, new Actor('7', 'Ada'));
        self::assertSame([['ban', '198.51.100.81']], $this->firewallRequests(), 'a firewall knows nothing of network owners');
        $service->lift($id, new Actor('7', 'Ada'));
        self::assertSame(['lift', '198.51.100.81'], array_slice($this->firewallRequests(), -1)[0]);
        self::assertSame('released', $this->lockouts->find($id)['firewall'] ?? null);

        // A ban whose time ran out is released by the rules.
        $timed = $service->lockOut(LockoutTarget::read('198.51.100.82'), '1h', 'Testing', true, new Actor('7', 'Ada'));
        $this->connection->executeStatement("UPDATE cattophp_site_lockouts SET until_at = NOW() - INTERVAL '1 minute' WHERE id = :id", ['id' => $timed]);
        self::assertStringContainsString('1 released at the firewall', $this->rules->run());
        self::assertSame(['lift', '198.51.100.82'], array_slice($this->firewallRequests(), -1)[0]);
        self::assertSame('released', $this->lockouts->find($timed)['firewall'] ?? null);

        // What the server says.
        file_put_contents($this->folder . '/server-firewall/view.json', json_encode(['written_at' => gmdate(DATE_ATOM), 'probes_today' => 47, 'bans' => [['address' => '198.51.100.0/24', 'why' => 'Probing', 'since' => '']], 'probes' => [['at' => gmdate(DATE_ATOM), 'address' => '198.51.100.9', 'path' => '/.env?x=1']]], JSON_THROW_ON_ERROR));
        $view = $server->view();
        self::assertSame([true, false, 47, '/.env'], [$view['available'], $view['stale'], $view['probes_today'], $view['probes'][0]['path']]);
        self::assertTrue($server->isBanned('198.51.100.99'));
        self::assertFalse($server->isBanned('198.51.101.1'));
        file_put_contents($this->folder . '/server-firewall/view.json', json_encode(['written_at' => gmdate(DATE_ATOM, time() - 3600)], JSON_THROW_ON_ERROR));
        self::assertTrue($server->view()['stale'], 'an hour old');
        file_put_contents($this->folder . '/server-firewall/view.json', '{broken');
        self::assertFalse($server->view()['available'], 'a broken view is no view');
    }

    public function testAFormFromABotIsRefusedAndTheListHolds(): void
    {
        $emails = new RefusedEmails($this->connection);
        $emails->add('Seo-Offers.Example', 'Selling search engine work', '7');
        $emails->add('someone@example.net', '', '7');
        foreach (['not an address', 'a@'] as $wrong) {
            $this->refusedWith('Give an email address', fn() => $emails->add($wrong, '', '7'));
        }
        $stack = new RequestStack();
        $guard = new FormGuard($stack, $emails, $this->settings, Foundations::KEY);
        $form = function (string $name, array $post, string $email, ?string $agent, string $address = '198.51.100.100') use ($stack, $guard): array {
            while ($stack->pop() !== null);
            $request = Request::create('/x', 'POST', $post, server: ['REMOTE_ADDR' => $address]);
            if ($agent === null) $request->headers->remove('User-Agent'); else $request->headers->set('User-Agent', $agent);
            $stack->push($request);
            $refused = $guard->refuses($name, $post, $email);
            return [$refused, $request->attributes->get(FormGuard::ATTRIBUTE)];
        };
        [$refused, $note] = $form(FormGuard::SIGN_IN, [], 'Person@Example.org', self::BROWSER);
        self::assertFalse($refused);
        self::assertNull($note['signal']);
        self::assertSame(hash_hmac('sha256', 'person@example.org', Foundations::KEY), $note['email_hash']);
        foreach ([[['website' => 'http://spam'], self::BROWSER, 'trap'], [[], null, 'no_browser'], [[], 'Mozilla/5.0 (Windows NT 6.1) Chrome/61.0.3163.100 Safari/537.36', 'outdated'], [[], 'curl/8.5.0', 'script']] as [$post, $agent, $signal]) {
            [$refused, $note] = $form(FormGuard::SIGN_IN, $post, 'person@example.org', $agent);
            self::assertTrue($refused, $signal);
            self::assertSame([$signal, 'person@example.org', FormGuard::SIGN_IN], [$note['signal'], $note['email'], $note['form']]);
        }
        foreach ([FormGuard::SIGN_IN, FormGuard::REGISTER, FormGuard::CONTACT] as $name) {
            self::assertTrue($form($name, [], 'anyone@seo-offers.example', self::BROWSER)[0], $name);
            self::assertTrue($form($name, [], 'SOMEONE@example.net', self::BROWSER)[0], $name);
        }
        self::assertSame(['someone@example.net' => 3, 'seo-offers.example' => 3], array_column(array_map(static fn(array $row): array => ['v' => $row['value'], 'n' => (int) $row['refused']], $emails->all()), 'n', 'v'));
        // Register keeps its own check: a script is noted, not refused here.
        [$refused, $note] = $form(FormGuard::REGISTER, [], 'person@example.org', 'curl/8.5.0');
        self::assertFalse($refused);
        self::assertSame('script', $note['signal']);
        // A private address, and the Never lock out list: never refused, so local checks and the owner's tools keep working.
        self::assertFalse($form(FormGuard::SIGN_IN, [], 'person@example.org', 'curl/8.5.0', '10.0.2.100')[0]);
        $post = $this->formFrom($this->settings->values());
        $post['never_lock_out'] = '198.51.100.100';
        $this->settings->save($post);
        self::assertFalse($form(FormGuard::SIGN_IN, [], 'person@example.org', 'curl/8.5.0')[0]);
        self::assertNull($form(FormGuard::SIGN_IN, [], 'person@example.org', 'curl/8.5.0')[1]['signal'], 'nothing noted against the owner\'s own address');
        $first = $emails->all()[0];
        self::assertSame($first['value'], $emails->remove((int) $first['id']));
        self::assertNull($emails->remove((int) $first['id']));
    }

    public function testSettingsAreCheckedSavedAndReset(): void
    {
        $form = $this->formFrom($this->settings->values());
        foreach ([['log_kept_for', '120 days'], ['longest', 'soon'], ['lockout_count', '1'], ['never_lock_out', 'somewhere']] as [$field, $wrong]) {
            $bad = $form;
            $bad[$field] = $wrong;
            try {
                $this->settings->save($bad);
                self::fail($field . ' took ' . $wrong);
            } catch (InvalidArgumentException) {
                self::assertSame('environment', $this->settings->source());
            }
        }
        $bad = $form;
        $bad['rule']['missing']['within'] = 'soon';
        $this->refusedWith('Asks for pages that do not exist: within', fn() => $this->settings->save($bad));
        $form['log_kept_for'] = '1y';
        $form['rule']['missing']['count'] = '5';
        $this->settings->save($form, new Actor('7', 'Ada'));
        self::assertSame('database', $this->settings->source());
        self::assertSame('1y', $this->settings->values()['log_kept_for']);
        self::assertSame(5, $this->settings->values()['rules']['missing']['count']);
        self::assertSame('on,5,10m,timeout,1h', $this->connection->fetchOne("SELECT value FROM cattophp_settings WHERE name = 'SITE_RULE_MISSING'"), 'kept as .env writes it');
        $this->settings->reset();
        self::assertSame('environment', $this->settings->source());
        self::assertSame(20, $this->settings->values()['rules']['missing']['count']);
        $environment = $this->settings->environment();
        self::assertSame(['120d', '1y', 'timeout', 'flag'], [$environment['log_kept_for'], $environment['flags_kept_for'], $environment['rules']['flood']['then'], $environment['rules']['register']['then']]);
        $fromEnv = new ProtectionSettings((new Foundations(['SITE_RULE_FLOOD' => 'off,50,1m,flag,10m', 'SITE_NEVER_LOCK_OUT' => '198.51.100.0/24'], settingsProviders: [new ProtectionSettingGroup()]))->settings);
        self::assertSame(['on' => false, 'count' => 50, 'within' => '1m', 'then' => 'flag', 'length' => '10m'], $fromEnv->values()['rules']['flood']);
        self::assertTrue($fromEnv->isNeverLockedOut('198.51.100.7'));
    }

    public function testTheCleanUpDeletesOnlyWhatIsPastItsTime(): void
    {
        $this->request('198.51.100.110');
        $this->request('198.51.100.111');
        $this->connection->executeStatement("UPDATE cattophp_site_requests SET at = NOW() - INTERVAL '121 days' WHERE address = '198.51.100.110'");
        $old = $this->lock(['network' => '198.51.100.112', 'which' => 'timeout', 'rule_key' => 'missing', 'automatic' => true, 'until' => new DateTimeImmutable('-400 days')]);
        $forGood = $this->lock(['network' => '198.51.100.113']);
        $banned = $this->lock(['network' => '198.51.100.115', 'until' => new DateTimeImmutable('-400 days'), 'firewall' => 'banned']);
        $this->flags->raise('198.51.100.114', 'medium', 'trap', 'Testing', 60);
        $this->flags->raise('198.51.100.113', 'high', 'by_hand', 'Testing', 60);
        $this->connection->executeStatement("UPDATE cattophp_site_flags SET last_flagged_at = NOW() - INTERVAL '400 days'");
        $deleted = (new ProtectionRetention($this->settings, $this->log, $this->flags, $this->lockouts))->run(new DateTimeImmutable());
        self::assertSame(['site_requests' => 1, 'site_flags' => 1, 'site_lockouts' => 1], $deleted);
        self::assertSame(['198.51.100.111'], $this->connection->fetchFirstColumn('SELECT host(address) FROM cattophp_site_requests'));
        self::assertNull($this->lockouts->find($old));
        self::assertNotNull($this->lockouts->find($forGood), 'in force, so kept');
        self::assertNotNull($this->lockouts->find($banned), 'the firewall still holds it');
        self::assertNull($this->flags->findBySubject('198.51.100.114'));
        self::assertNotNull($this->flags->findBySubject('198.51.100.113'), 'kept out now, so its flag stays');
    }

    public function testTheRecorderWritesEachRequestAsItEnds(): void
    {
        $visitors = new class implements VisitorAccounts {
            /** @var array{id:string,administrator:bool}|null */
            public ?array $account = null;

            public function current(): ?array
            {
                return $this->account;
            }
        };
        $recorder = new RequestRecorder($this->log, $visitors, $this->network(), new PathMask(['/m/[a-z]/']), 'prod');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $end = function (Request $request, Response $response) use ($recorder, $kernel): void {
            $recorder(new TerminateEvent($kernel, $request, $response));
        };
        $request = Request::create('/m/u/0123456789abcdef?utm_source=whatsapp&utm_medium=share', 'GET', server: ['REMOTE_ADDR' => '198.51.100.200', 'HTTP_USER_AGENT' => self::BROWSER]);
        $end($request, new Response('<p>Hello</p>'));
        $visitors->account = ['id' => '41', 'administrator' => true];
        $form = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '2001:db8:1:2::9', 'HTTP_USER_AGENT' => 'curl/8.5.0', 'HTTP_REFERER' => 'https://www.google.com/search?q=x']);
        $form->attributes->set(FormGuard::ATTRIBUTE, ['form' => 'sign_in', 'signal' => 'script', 'refused' => true, 'email' => 'bot@example.net', 'email_hash' => str_repeat('a', 64)]);
        $end($form, new Response('', 302));
        $live = Request::create('/admin', 'GET', server: ['REMOTE_ADDR' => '198.51.100.200', 'HTTP_X_LIVE_REFRESH' => '1', 'HTTP_HX_REQUEST' => 'true']);
        LiveRefresh::notAVisit($live);
        $end($live, new Response('<p>Live</p>'));
        (new RequestRecorder($this->log, $visitors, $this->network(), new PathMask(), 'test'))(new TerminateEvent($kernel, Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.201']), new Response()));

        $rows = $this->connection->fetchAllAssociative('SELECT host(address) AS address, abbrev(subject) AS subject, account_id, by_administrator, visitor, agent_kind, device, country_code, network_kind, network_asn, network_name, method, path, status, is_page, came_from, source, form, form_signal, form_refused, form_email, form_email_hash FROM cattophp_site_requests ORDER BY id');
        self::assertCount(2, $rows, 'a live refresh is not a visit, and a test run writes nothing');
        self::assertSame(['198.51.100.200', '198.51.100.200', null, false, 'person', 'browser', 'computer', 'NL', 'hosting', 64511, 'Example Hosting BV', 'GET', '/m/u/••••', 200, true, 'whatsapp', 'share', null, null, false, null, null], array_values($this->typed($rows[0])));
        self::assertSame(['2001:db8:1:2::9', '2001:db8:1:2::/64', '41', true, 'signed_in', 'script', '', null, '', null, '', 'POST', '/login', 302, false, 'google.com', 'search', 'sign_in', 'script', true, 'bot@example.net', str_repeat('a', 64)], array_values($this->typed($rows[1])));
    }

    /** cattolms's records, moved in as they stand by its upgrade migration, are read as site protection writes its own. */
    public function testAnApplicationsOwnRecordsAreReadAsItWroteThem(): void
    {
        $this->connection->executeStatement("INSERT INTO cattophp_site_requests (id, at, address, subject, account_id, by_administrator, visitor, agent_kind, agent_label, device, user_agent, country_code, network_kind, method, path, status, is_page, source)
            VALUES (85480, NOW() - INTERVAL '1 minute', '198.51.100.9', '198.51.100.9', '12', FALSE, 'signed_in', 'browser', 'Chrome on Windows', 'computer', 'Mozilla/5.0', 'ZA', 'home', 'GET', '/courses', 200, TRUE, 'direct')");
        $this->connection->executeStatement("INSERT INTO cattophp_site_flags (id, subject, level, why, decided_by) VALUES (3, '198.51.100.9', 'high', '[{\"at\": \"2026-11-04T10:00:00+00:00\", \"rule\": \"missing\", \"words\": \"Asked for 20 pages that do not exist in 10 minutes.\"}]', NULL)");
        $this->connection->executeStatement("INSERT INTO cattophp_site_lockouts (id, kind, network, label, which, why, rule_key, automatic, created_by, until_at) VALUES (5, 'address', '198.51.100.9', '198.51.100.9', 'timeout', 'Asked for 20 pages that do not exist in 10 minutes.', 'missing', TRUE, NULL, NOW() + INTERVAL '1 hour')");
        foreach (['cattophp_site_requests', 'cattophp_site_flags', 'cattophp_site_lockouts'] as $table) {
            $this->connection->executeStatement("SELECT setval(pg_get_serial_sequence('$table', 'id'), (SELECT MAX(id) FROM $table))");
        }
        $flag = $this->flags->all()[0];
        self::assertSame(['198.51.100.9', 'waiting', 1, 'ZA'], [$flag['subject'], $flag['state'], (int) $flag['requests'], $flag['country_code']]);
        self::assertSame('Asked for 20 pages that do not exist in 10 minutes.', $flag['why_list'][0]['words']);
        self::assertSame(5, (int) ($this->lockouts->inForceFor('198.51.100.9', null)['id'] ?? 0));
        self::assertTrue($this->log->usedBySomeoneSignedIn('198.51.100.9', 30));
        $this->request('198.51.100.10');
        self::assertSame(85481, (int) $this->connection->fetchOne('SELECT MAX(id) FROM cattophp_site_requests'), 'and carried on from them');
    }

    /** @param array<string,mixed> $extra */
    private function request(string $address, string $path = '/', int $status = 200, string $method = 'GET', string $agent = 'Go-http-client/1.1', array $extra = []): void
    {
        $reading = VisitorAgent::read($agent);
        $this->log->record($extra + ['address' => $address, 'subject' => Addresses::subject($address), 'account_id' => null, 'by_administrator' => false,
            'visitor' => $reading->isAutomated() ? 'automated' : 'person', 'agent_kind' => $reading->kind, 'agent_label' => $reading->label, 'device' => $reading->device, 'user_agent' => $agent,
            'country_code' => 'NL', 'network_kind' => 'hosting', 'network_asn' => 64511, 'network_name' => 'Example Hosting BV', 'method' => $method, 'path' => $path, 'status' => $status,
            'is_page' => $method === 'GET' && $status < 400, 'came_from' => '', 'source' => 'direct']);
    }

    private function missing(string $address, int $count = 20): void
    {
        for ($i = 0; $i < $count; $i++) $this->request($address, '/old/' . $i, 404);
    }

    /** @param array<string,mixed> $lockout */
    private function lock(array $lockout): int
    {
        return $this->lockouts->add($lockout + ['kind' => 'address', 'network' => null, 'asn' => null, 'label' => (string) ($lockout['network'] ?? 'a lockout'), 'which' => 'lockout', 'why' => 'Testing', 'rule_key' => null, 'automatic' => false, 'created_by' => null, 'until' => null, 'firewall' => 'no']);
    }

    private function network(): NetworkLookup
    {
        return new class implements NetworkLookup {
            public function facts(string $address): NetworkFacts
            {
                return str_starts_with($address, '198.51.100.') ? new NetworkFacts('NL', 64511, 'Example Hosting BV', 'hosting') : new NetworkFacts();
            }
        };
    }

    private function service(string $ownAddress = '192.0.2.1', ?ServerLink $server = null): LockoutService
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/admin', 'POST', server: ['REMOTE_ADDR' => $ownAddress]));

        return new LockoutService($this->lockouts, $this->flags, $this->settings, $server ?? new ServerLink($this->folder . '/server-firewall'), new class implements NetworkLookup {
            public function facts(string $address): NetworkFacts
            {
                return new NetworkFacts(asn: $address === '198.51.100.60' ? 64511 : null);
            }
        }, $this->site->activity, $requests);
    }

    private function refusedWith(string $words, callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Not refused: ' . $words);
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString($words, $refused->getMessage());
        }
    }

    /** @return list<array{0:string,1:string}> */
    private function firewallRequests(): array
    {
        return array_map(static function (string $line): array {
            $request = (array) json_decode($line, true);
            return [(string) $request['do'], (string) $request['network']];
        }, file($this->folder . '/server-firewall/requests.log', FILE_IGNORE_NEW_LINES) ?: []);
    }

    private function stateOf(string $subject): string
    {
        foreach ($this->flags->all() as $flag) {
            if ($flag['subject'] === $subject) return (string) $flag['state'];
        }

        return '';
    }

    private function inForce(string $subject): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_site_lockouts l WHERE l.network = CAST(:subject AS cidr) AND ' . Lockouts::IN_FORCE, ['subject' => $subject]);
    }

    private function lengthOf(string $subject): int
    {
        $force = (array) $this->lockouts->inForceFor($subject, null);

        return (int) strtotime((string) $force['until_at']) - (int) strtotime((string) $force['started_at']);
    }

    /** Ends a time-out as time would: its end passes, and the requests before it are behind it. */
    private function endEverythingOn(string $subject): void
    {
        $this->connection->executeStatement("UPDATE cattophp_site_lockouts SET until_at = started_at - INTERVAL '2 seconds', started_at = started_at - (until_at - started_at) - INTERVAL '2 seconds' WHERE network = CAST(:subject AS cidr) AND lifted_at IS NULL", ['subject' => $subject]);
        $this->connection->executeStatement("UPDATE cattophp_site_requests SET at = at - INTERVAL '3 seconds' WHERE subject = CAST(:subject AS inet)", ['subject' => $subject]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function typed(array $row): array
    {
        foreach (['by_administrator', 'is_page', 'form_refused'] as $flag) $row[$flag] = $row[$flag] === true || $row[$flag] === 't';
        foreach (['network_asn', 'status'] as $number) $row[$number] = $row[$number] === null ? null : (int) $row[$number];

        return $row;
    }

    /**
     * The settings as a page would send them.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function formFrom(array $values): array
    {
        $rules = [];
        foreach ($values['rules'] as $key => $rule) {
            $rules[$key] = ['count' => (string) $rule['count'], 'within' => $rule['within'], 'then' => $rule['then'], 'length' => $rule['length']] + ($rule['on'] ? ['on' => '1'] : []);
        }

        return ['rule' => $rules, 'longest' => $values['longest'], 'lockout_count' => (string) $values['lockout_count'], 'lockout_within' => $values['lockout_within'], 'lockout_length' => $values['lockout_length'],
            'never_lock_out' => implode("\n", $values['never_lock_out']), 'log_kept_for' => $values['log_kept_for'], 'flags_kept_for' => $values['flags_kept_for']]
            + array_filter(['doubles' => $values['doubles'], 'spare_administrators' => $values['spare_administrators'], 'spare_people' => $values['spare_people'], 'spare_search_engines' => $values['spare_search_engines'], 'leave_out_administrators' => $values['leave_out_administrators'], 'firewall_link' => $values['firewall_link']]);
    }
}
