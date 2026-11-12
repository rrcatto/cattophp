<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Network\NetworkFacts;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\Network\ReputationLists;
use CattoPHP\Signup\MailDomainCheck;
use CattoPHP\Signup\SignupAttempts;
use CattoPHP\Signup\SignupChecks;
use CattoPHP\Signup\SignupCountries;
use CattoPHP\Signup\SignupRisk;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The sign-up check judging and recording registrations, ported from cattolms's SignupCheckIntegrationTest. */
final class SignupTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    private SignupAttempts $attempts;
    private RequestStack $requests;
    private string $folder;
    /** @var array<string,NetworkFacts> what each address says */
    private array $networks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->attempts = new SignupAttempts($this->connection);
        $this->requests = new RequestStack();
        $this->folder = sys_get_temp_dir() . '/cattophp-signup-' . bin2hex(random_bytes(4));
        $this->networks = [
            '41.0.0.1' => new NetworkFacts('ZA', 36994, 'Vodacom', 'home', address: '41.0.0.1'),
            '81.2.69.142' => new NetworkFacts('GB', 2856, 'British Telecommunications', 'home', address: '81.2.69.142'),
            '5.8.18.7' => new NetworkFacts('RU', 12389, 'Rostelecom', 'home', address: '5.8.18.7'),
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->folder);
        parent::tearDown();
    }

    public function testARegistrationIsJudgedRecordedAndCompleted(): void
    {
        $checks = $this->checks();
        $this->from('41.0.0.1');
        $found = $checks->check($this->form() + [SignupChecks::ZONE_FIELD => 'Africa/Johannesburg'], 'thandi.nkosi@example.com', null, 'ZA', ['account_type' => 'individual']);
        self::assertSame([0, 'low', false], [$found['risk']->score, $found['risk']->band, $found['risk']->holds()]);
        $attempt = $this->attempts->find($found['id']) ?? [];
        self::assertSame(['link_sent', 'ZA', 'ZA', 'Vodacom', 36994, '41.0.0.1', 'Africa/Johannesburg', 40, ['account_type' => 'individual'], self::BROWSER],
            [$attempt['outcome'], $attempt['country_code'], $attempt['home_country'], $attempt['network_name'], $attempt['network_asn'], $attempt['ip_address'], $attempt['browser_time_zone'],
                $attempt['form_seconds'], $attempt['details'], $attempt['user_agent']]);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $attempt['public_id']);

        $checks->complete($found['id'], '42', false);
        $checks->complete($found['id'], '43', true);
        $account = $this->attempts->forAccount('42') ?? [];
        self::assertSame(['completed', false, '42'], [$account['outcome'], $account['held'], $account['account']], 'An attempt becomes one account, once.');
        self::assertNull($this->attempts->forAccount('43'));
        self::assertSame(['42' => ['id' => $found['id'], 'band' => 'low', 'score' => 0, 'country_code' => 'ZA']], $this->attempts->forAccounts(['42', '99', '']));
        self::assertSame('ZA', $checks->countryOf('41.0.0.1'));
        self::assertSame('', $checks->countryOf(''));
    }

    /** Someone abroad registers with a warning sign; a blocked country is held, never told why. */
    public function testAbroadIsAWarningSignAndABlockedCountryIsHeld(): void
    {
        $checks = $this->checks();
        $this->from('81.2.69.142');
        $abroad = $checks->check($this->form(), 'abroad@example.com', null, 'ZA');
        self::assertSame(['medium', []], [$abroad['risk']->band, $abroad['risk']->holdReasons]);
        self::assertSame(['GB', 'ZA'], [($this->attempts->find($abroad['id']) ?? [])['country_code'], ($this->attempts->find($abroad['id']) ?? [])['home_country']], 'Where they were, and where they say they live.');
        $this->from('5.8.18.7');
        $blocked = $checks->check($this->form(), 'blocked@example.com');
        self::assertSame(['high', ['country_blocked', 'high_risk'], null], [$blocked['risk']->band, $blocked['risk']->holdReasons, $blocked['risk']->refusal]);
        self::assertSame(['link_sent', ['country_blocked', 'high_risk']], [($this->attempts->find($blocked['id']) ?? [])['outcome'], ($this->attempts->find($blocked['id']) ?? [])['hold_reasons']]);
    }

    public function testAThrowawayAddressIsRefusedABotCaughtAndAnotherRulesRefusalKept(): void
    {
        $checks = $this->checks();
        $this->from('41.0.0.1');
        $throwaway = $checks->check($this->form(), 'someone@mailinator.com');
        self::assertSame(['refused', SignupRisk::REFUSED_DISPOSABLE], [($this->attempts->find($throwaway['id']) ?? [])['outcome'], ($this->attempts->find($throwaway['id']) ?? [])['refusal']], 'Kept, so the administrator sees it.');
        $bot = $checks->check($this->form() + [SignupChecks::TRAP_FIELD => 'https://spam.example'], 'bot@example.com');
        self::assertSame(['trapped', 100, null], [($this->attempts->find($bot['id']) ?? [])['outcome'], ($this->attempts->find($bot['id']) ?? [])['score'], $bot['risk']->refusal], 'A bot is told nothing.');
        $uninvited = $checks->check($this->form(), 'uninvited@example.com', 'Only invited people can register.');
        self::assertSame(['refused', 'Only invited people can register.'], [($this->attempts->find($uninvited['id']) ?? [])['outcome'], ($this->attempts->find($uninvited['id']) ?? [])['refusal']]);
        $again = $checks->check($this->form(), 'fourth@example.com');
        self::assertSame(['key' => 'same_ip', 'points' => 60], array_intersect_key(array_values(array_filter($again['risk']->signals, static fn(array $signal): bool => $signal['key'] === 'same_ip'))[0], ['key' => 1, 'points' => 1]),
            'Three other sign-ups from the address in the last day: at most 60.');
    }

    public function testTheFormStampIsSignedAndOnlyBelievedForADay(): void
    {
        $checks = $this->checks();
        $now = 1774970000;
        self::assertSame(40, $checks->secondsOpen($checks->stamp($now - 40), $now));
        self::assertNull($checks->secondsOpen($checks->stamp($now + 5), $now), 'A stamp from the future.');
        self::assertNull($checks->secondsOpen($checks->stamp($now - 86400), $now), 'A day old.');
        self::assertNull($checks->secondsOpen(($now - 40) . '.0000000000000000', $now), 'Made up.');
        self::assertNull($this->checks('another-key')->secondsOpen($checks->stamp($now - 40), $now), 'Signed with another key.');
        self::assertNull($checks->secondsOpen('', $now));
        $this->from('41.0.0.1', '');
        $found = $checks->check(['first_name' => ['not', 'text']], 'nobrowser@example.com');
        self::assertSame(['browser_missing'], array_column($found['risk']->signals, 'key'), 'No stamp is not a signal; no browser is.');
        self::assertNull(($this->attempts->find($found['id']) ?? [])['form_seconds']);
    }

    public function testRowsWrittenBeforeReadTheSameAndTheCleanUpKeepsAccounts(): void
    {
        // As a project's upgrade migration writes what it kept: its own form fields as details, the person's account in text.
        $this->connection->executeStatement("INSERT INTO cattophp_signup_attempts (public_id, created_at, email, details, ip_address, country_code, score, band, signals, hold_reasons, outcome, account, held, completed_at)
            VALUES ('8d0f6a8e-5d0c-4c39-9d5a-2f3b7a1c9e11', NOW() - INTERVAL '6 years', 'kept@example.com', '{\"account_type\":\"company\"}', '41.0.0.9', 'ZA', 30, 'medium',
                    '[{\"key\":\"vpn\",\"points\":30,\"words\":\"The IP address belongs to a VPN provider.\"}]', '[]', 'completed', '12', false, NOW() - INTERVAL '6 years')");
        $this->connection->executeStatement("INSERT INTO cattophp_signup_attempts (email, created_at, score, band, outcome) VALUES ('old@example.com', NOW() - INTERVAL '6 years', 0, 'low', 'link_sent'), ('new@example.com', NOW(), 70, 'high', 'link_sent')");
        $kept = $this->attempts->forAccount('12') ?? [];
        self::assertSame(['8d0f6a8e-5d0c-4c39-9d5a-2f3b7a1c9e11', ['account_type' => 'company'], 'The IP address belongs to a VPN provider.', '41.0.0.9'],
            [$kept['public_id'], $kept['details'], $kept['signals'][0]['words'], $kept['ip_address']]);
        self::assertSame(['low' => 0, 'medium' => 0, 'high' => 1, 'all' => 1], $this->attempts->lastMonth());
        self::assertSame(1, $this->attempts->completedBetween(new DateTimeImmutable('-7 years'), new DateTimeImmutable('-5 years')));
        self::assertSame(1, $this->attempts->deleteUnclaimedBefore(new DateTimeImmutable('-5 years')), 'Only the old attempt that never became an account.');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_signup_attempts'));
    }

    /** @return array<string,string> */
    private function form(): array
    {
        return ['first_name' => 'Thandi', 'last_name' => 'Nkosi', SignupChecks::STAMP_FIELD => $this->checks()->stamp(time() - 40)];
    }

    private function from(string $address, string $browser = self::BROWSER): void
    {
        while ($this->requests->pop() !== null) {
        }
        $this->requests->push(new Request(server: array_filter(['REMOTE_ADDR' => $address, 'HTTP_USER_AGENT' => $browser], static fn(string $value): bool => $value !== '')));
    }

    private function checks(string $key = Foundations::KEY): SignupChecks
    {
        $lists = new ReputationLists($this->folder);
        if (!is_file($this->folder . '/disposable.txt')) {
            $lists->store('disposable', 'domains', "mailinator.com\n");
        }
        $networks = $this->networks;
        $lookup = new class ($networks) implements NetworkLookup {
            /** @param array<string,NetworkFacts> $networks */
            public function __construct(private readonly array $networks)
            {
            }

            public function facts(string $address): NetworkFacts
            {
                return $this->networks[$address] ?? NetworkFacts::unknown($address);
            }
        };
        $mail = new class extends MailDomainCheck {
            public function receivesMail(string $domain): ?bool
            {
                return true;
            }
        };
        $countries = new class extends SignupCountries {
            public function allowed(): array
            {
                return ['ZA'];
            }

            public function blocked(): array
            {
                return ['RU'];
            }
        };

        return new SignupChecks($this->attempts, $countries, $key, $lookup, $lists, $mail, $this->requests);
    }
}
