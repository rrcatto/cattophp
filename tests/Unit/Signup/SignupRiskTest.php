<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Signup;

use CattoPHP\Network\NetworkFacts;
use CattoPHP\Signup\RiskAssessment;
use CattoPHP\Signup\SignupCountries;
use CattoPHP\Signup\SignupFacts;
use CattoPHP\Signup\SignupRisk;
use PHPUnit\Framework\TestCase;

/** A registration judged, ported from cattolms's SignupRiskTest with every case it covers. */
final class SignupRiskTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    public function testAnOrdinarySignUpFromAnAllowedCountryIsLowRiskAndNotHeld(): void
    {
        $risk = SignupRisk::assess($this->facts(), ['ZA'], ['RU']);
        self::assertSame([0, RiskAssessment::LOW, [], false, null, false], [$risk->score, $risk->band, $risk->signals, $risk->holds(), $risk->refusal, $risk->trapped]);
    }

    /** Being abroad is a warning sign, never a hold; a blocked country is held, never told why. */
    public function testACountryOnTheBlockedListIsHeldAndOneOutsideTheAllowedListIsAWarningSign(): void
    {
        $abroad = SignupRisk::assess($this->facts(network: new NetworkFacts('GB', address: '81.2.69.142', kind: 'home')), ['ZA'], ['RU']);
        self::assertSame([30, RiskAssessment::MEDIUM, [], null], [$abroad->score, $abroad->band, $abroad->holdReasons, $abroad->refusal], 'Amber, not held.');
        self::assertStringContainsString('United Kingdom, outside your allowed countries (South Africa (ZA))', $abroad->signals[0]['words']);
        $blocked = SignupRisk::assess($this->facts(network: new NetworkFacts('RU', address: '5.8.18.7', kind: 'home')), ['ZA'], ['RU']);
        self::assertSame([60, RiskAssessment::HIGH, ['country_blocked', 'high_risk'], null], [$blocked->score, $blocked->band, $blocked->holdReasons, $blocked->refusal], 'Held, red, never told why.');
        $unknown = SignupRisk::assess($this->facts(network: NetworkFacts::unknown('10.0.2.100')), ['ZA'], []);
        self::assertSame([], $unknown->holdReasons, 'A country that cannot be found is a warning sign too.');
        self::assertSame('country_not_allowed', $unknown->signals[0]['key']);
        self::assertFalse(SignupRisk::assess($this->facts(network: new NetworkFacts('GB', address: '81.2.69.142', kind: 'home')), [], [])->holds(), 'No allowed list: every country.');
    }

    public function testManySmallSignalsAddUpToHighRiskWhichIsHeld(): void
    {
        $risk = SignupRisk::assess($this->facts(
            email: 'xkqzwvrt@example.test', agent: 'python-requests/2.32', network: new NetworkFacts('ZA', 9009, 'M247 Europe SRL', 'vpn', vpn: true, address: '45.9.20.1'),
            seconds: 1, zone: 'Asia/Kolkata', others: 2, first: 'Test', last: 'test'
        ), ['ZA'], []);
        self::assertSame(['vpn', 'email_random', 'browser_script', 'too_fast', 'time_zone', 'same_ip', 'name_odd'], array_column($risk->signals, 'key'));
        self::assertSame(100, $risk->score, 'At most 100.');
        self::assertSame(['high_risk'], $risk->holdReasons);
        self::assertSame('The IP address belongs to a VPN provider (M247 Europe SRL).', $risk->signals[0]['words']);
        self::assertSame(40, $risk->signals[5]['points'], 'Two other sign-ups from the same address.');
        self::assertSame('The form was sent 1 second after it opened: people take longer.', $risk->signals[3]['words']);
        self::assertSame(['High risk', 'danger'], [RiskAssessment::label($risk->band), RiskAssessment::tone($risk->band)]);
    }

    public function testAThrowawayAddressIsRefusedAndTheHiddenFieldIsCaught(): void
    {
        $throwaway = SignupRisk::assess($this->facts(email: 'thandi@mailinator.com', disposable: true), ['ZA'], []);
        self::assertSame(SignupRisk::REFUSED_DISPOSABLE, $throwaway->refusal);
        self::assertSame('mailinator.com is a throwaway email service.', $throwaway->signals[0]['words']);
        $bot = SignupRisk::assess($this->facts(trap: true), ['ZA'], []);
        self::assertTrue($bot->trapped);
        self::assertSame([100, RiskAssessment::HIGH], [$bot->score, $bot->band]);
        self::assertNull($bot->refusal, 'A bot is shown the usual page, not a refusal.');
        $noMail = SignupRisk::assess($this->facts(email: 'thandi@no-mail.example', receives: false), ['ZA'], []);
        self::assertSame(['mail_domain_none', 40], [$noMail->signals[0]['key'], $noMail->score]);
    }

    public function testOrdinaryNamesBrowsersAndAddressesAreNotFlagged(): void
    {
        foreach ([['Thandi', 'Nkosi'], ['Johan', 'van der Merwe'], ['Lwazi', 'Mthembu'], ['Ngozi', 'Okafor'], ['Sipho', 'Dlamini']] as [$first, $last]) {
            self::assertSame(0, SignupRisk::assess($this->facts(first: $first, last: $last), ['ZA'], [])->score, $first . ' ' . $last);
        }
        foreach (['thandi.nkosi@example.test', 'jsmith@example.test', 'john1985@example.test', 'info+courses@example.test'] as $email) {
            self::assertSame(0, SignupRisk::assess($this->facts(email: $email), ['ZA'], [])->score, $email);
        }
        self::assertSame(0, SignupRisk::assess($this->facts(zone: 'Africa/Johannesburg', seconds: 45), ['ZA'], [])->score, 'Its own time zone, at a person\'s pace.');
    }

    public function testCountryNamesAndCodes(): void
    {
        self::assertSame('South Africa', SignupCountries::name('za'));
        self::assertSame('South Africa (ZA), Russia (RU)', SignupCountries::names(['ZA', 'RU']));
        self::assertSame('none', SignupCountries::names([]));
        self::assertSame(['low', 'medium', 'medium', 'high'], [RiskAssessment::band(24), RiskAssessment::band(25), RiskAssessment::band(59), RiskAssessment::band(60)]);
    }

    private function facts(
        string $email = 'thandi.nkosi@example.test', string $agent = self::BROWSER, ?NetworkFacts $network = null, ?int $seconds = 20,
        string $zone = '', bool $trap = false, bool $disposable = false, ?bool $receives = true, int $others = 0,
        string $first = 'Thandi', string $last = 'Nkosi'
    ): SignupFacts {
        return new SignupFacts($email, $first, $last, $agent, $network ?? new NetworkFacts('ZA', 36937, 'Neotel', 'home', address: '41.0.0.1'), $seconds, $zone, $trap, $disposable, $receives, $others);
    }
}
