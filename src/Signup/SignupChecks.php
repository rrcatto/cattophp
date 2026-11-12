<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

use CattoPHP\Http\FormStamp;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\Network\NoNetworkLookup;
use CattoPHP\Network\ReputationLists;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The sign-up check: when a registration form is sent, gathers what is known (the network of the address, the email
 * domain, the browser, how long the form was open, the browser's time zone, the hidden field, earlier sign-ups from the
 * same address), has SignupRisk judge it against the country lists, and records the attempt with its outcome (link
 * sent, refused, caught by the hidden field), so an administrator sees every attempt, not only the accounts that were
 * completed. The form carries a signed stamp of when it was opened (stamp()), which is how its time open is known
 * without trusting the browser.
 *
 * The address is the web server's REMOTE_ADDR, never a forwarded header: a reverse proxy's addresses belong in the
 * server's configuration.
 */
class SignupChecks
{
    private readonly FormStamp $stamps;

    /** The hidden field people never see. */
    public const TRAP_FIELD = 'website';
    public const STAMP_FIELD = 'signup_opened';
    public const ZONE_FIELD = 'signup_time_zone';

    public function __construct(
        private readonly SignupAttempts $attempts,
        private readonly SignupCountries $countries,
        string $applicationKey,
        private readonly NetworkLookup $network = new NoNetworkLookup(),
        private readonly ?ReputationLists $lists = null,
        private readonly MailDomainCheck $mail = new MailDomainCheck(),
        private readonly ?RequestStack $requests = null,
    ) {
        $this->stamps = new FormStamp($applicationKey);
    }

    /** When the form was opened, signed so a browser cannot make it up (Http\FormStamp). */
    public function stamp(?int $now = null): string
    {
        return $this->stamps->stamp($now);
    }

    /** The seconds the form was open, or null without a genuine stamp. */
    public function secondsOpen(string $stamp, ?int $now = null): ?int
    {
        return $this->stamps->secondsOpen($stamp, $now);
    }

    /**
     * Judges a registration and records it: refused (a throwaway address, or $refusal given by another rule, such as who
     * may sign in), caught by the hidden field, or with its link sent.
     *
     * @param array<string,mixed> $form what the form sent: email aside, first_name, last_name and the three fields above are read
     * @param string $homeCountry the country the person says they live in, as a two-letter code, if the form asks
     * @param array<string,mixed> $details the project's own fields of the form, kept with the attempt
     * @return array{id:int,risk:RiskAssessment}
     */
    public function check(array $form, string $email, ?string $refusal = null, string $homeCountry = '', array $details = []): array
    {
        $address = $this->address();
        $network = $this->network->facts($address);
        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));
        $facts = new SignupFacts(
            $email,
            trim(self::text($form['first_name'] ?? '')),
            trim(self::text($form['last_name'] ?? '')),
            $this->userAgent(),
            $network,
            $this->secondsOpen(self::text($form[self::STAMP_FIELD] ?? '')),
            mb_substr(trim(self::text($form[self::ZONE_FIELD] ?? '')), 0, 64),
            trim(self::text($form[self::TRAP_FIELD] ?? '')) !== '',
            $domain !== '' && ($this->lists?->isDisposable($domain) ?? false),
            $domain !== '' ? $this->mail->receivesMail($domain) : null,
            $this->attempts->recentFromAddress($address),
        );
        $risk = SignupRisk::assess($facts, $this->countries->allowed(), $this->countries->blocked());
        $refusal ??= $risk->refusal;
        $id = $this->attempts->record([
            'email' => $email, 'first_name' => $facts->firstName, 'last_name' => $facts->lastName, 'details' => $details,
            'ip' => $address, 'country' => $network->countryCode, 'home_country' => preg_match('/^[A-Z]{2}$/', $homeCountry) === 1 ? $homeCountry : '',
            'asn' => $network->asn, 'network' => $network->networkName, 'agent' => $facts->userAgent, 'zone' => $facts->browserTimeZone,
            'seconds' => $facts->formSeconds, 'score' => $risk->score, 'band' => $risk->band, 'signals' => $risk->signals,
            'hold_reasons' => $risk->holdReasons, 'outcome' => $risk->trapped ? 'trapped' : ($refusal !== null ? 'refused' : 'link_sent'), 'refusal' => $refusal,
        ]);

        return ['id' => $id, 'risk' => $risk];
    }

    /** The country of an address, for a sign-in's record ('' when it is private or unknown). */
    public function countryOf(string $address): string
    {
        return $address === '' ? '' : $this->network->facts($address)->countryCode;
    }

    /** The registration was completed: the account exists, held for approval or not. */
    public function complete(int $attemptId, string $account, bool $held): void
    {
        $this->attempts->complete($attemptId, $account, $held);
    }

    private function address(): string
    {
        $address = trim(self::text($this->server()['REMOTE_ADDR'] ?? ''));

        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '';
    }

    private function userAgent(): string
    {
        return mb_substr(trim(self::text($this->server()['HTTP_USER_AGENT'] ?? 'Unknown browser')), 0, 500);
    }

    /** @return array<string,mixed> the request's server values, or the process's outside a request */
    private function server(): array
    {
        $request = $this->requests?->getCurrentRequest();

        return $request !== null ? $request->server->all() : $_SERVER;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
