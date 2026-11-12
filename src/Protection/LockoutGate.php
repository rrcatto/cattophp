<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Http\VisitorAgent;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\Network\NoNetworkLookup;
use Closure;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Turns away a visitor who is timed out or locked out, before anything else: no session, no theme, a bare page that
 * says the site is not available and nothing more. Each refusal is counted, and the first of them in a day go into the
 * Site Log. A fault here never stops the site: it answers as if nothing were locked out. Webhooks are never refused, so
 * a payment provider is never shut out by a rule.
 *
 * A project whose every request passes through Symfony has it as a request listener (LockoutListener); a project that
 * starts its own session first calls it before that (`LockoutGate::beforeTheContainer(...)->refuses(...)`).
 */
class LockoutGate
{
    /** How many refused requests of one lockout the Site Log keeps in a day. The counter goes on counting. */
    public const LOGGED_A_DAY = 100;

    public const PAGE = "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex\"><title>Not available</title></head><body><p>This site is not available.</p></body></html>\n";

    /** @param ProtectionSettings|Closure():string $neverLockOut the settings, or what gives the Never lock out list as .env writes it */
    public function __construct(
        private readonly Lockouts $lockouts,
        private readonly SiteLog $log,
        private readonly NetworkLookup $network,
        private readonly PathMask $paths,
        private readonly ProtectionSettings|Closure $neverLockOut,
    ) {
    }

    /**
     * The gate with its records on a connection of its own, for a project that checks before its container is built:
     * the Never lock out list is what Settings saved, else what .env says.
     */
    public static function beforeTheContainer(Connection $connection, string $neverLockOutInEnv, NetworkLookup $network = new NoNetworkLookup(), PathMask $paths = new PathMask()): self
    {
        return new self(new Lockouts($connection), new SiteLog($connection), $network, $paths, static function () use ($connection, $neverLockOutInEnv): string {
            $saved = $connection->fetchOne("SELECT value FROM cattophp_settings WHERE name = 'SITE_NEVER_LOCK_OUT' AND NOT secret");

            return is_string($saved) ? $saved : $neverLockOutInEnv;
        });
    }

    /** Whether this request is refused, counted when it is. */
    public function refuses(string $address, string $path, string $method = 'GET', string $agent = ''): bool
    {
        if ($address === '' || str_starts_with($path, '/webhooks/')) return false;
        try {
            $lockout = $this->matching($address);
            if ($lockout === null) return false;
            if ($this->neverLockOut instanceof ProtectionSettings ? $this->neverLockOut->isNeverLockedOut($address) : Addresses::listHolds(($this->neverLockOut)(), $address)) return false;
            $this->count($lockout, $address, $path, $method, $agent);

            return true;
        } catch (Throwable $fault) {
            error_log('The lockout check failed: ' . $fault->getMessage());

            return false;
        }
    }

    /** The answer to a refused request. */
    public static function response(): Response
    {
        return new Response(self::PAGE, 403, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** Answers a refused request and ends it, for a project that checks before Symfony handles the request. */
    public static function refuse(): never
    {
        self::response()->send();
        exit;
    }

    private function matching(string $address): ?int
    {
        $rows = $this->lockouts->inForceOnAddressOrAnyOwner($address);
        if ($rows === []) return null;
        if ($rows[0]['asn'] === null) return $rows[0]['id'];
        $number = $this->network->facts($address)->asn;
        foreach ($rows as $row) {
            if ($number !== null && $row['asn'] === $number) return $row['id'];
        }

        return null;
    }

    private function count(int $lockout, string $address, string $path, string $method, string $agent): void
    {
        if ($this->lockouts->countRefused($lockout) > self::LOGGED_A_DAY) return;
        // What the log knows of this address from its last request, or its country and network from the lookup.
        $subject = Addresses::subject($address);
        $last = $this->log->lastKnown($subject);
        $facts = $last === null ? $this->network->facts($address) : null;
        $reading = VisitorAgent::read($agent);
        $this->log->recordRefused([
            'address' => $address, 'subject' => $subject, 'visitor' => $reading->isAutomated() ? 'automated' : 'person', 'kind' => $reading->kind, 'label' => $reading->label, 'device' => $reading->device, 'agent' => mb_substr($agent, 0, 500),
            'country' => $last !== null ? $last['country_code'] : ($facts?->countryCode !== '' ? $facts?->countryCode : null),
            'network_kind' => $last !== null ? $last['network_kind'] : (Addresses::isPrivate($address) ? 'private' : (string) $facts?->kind),
            'asn' => $last !== null ? $last['network_asn'] : $facts?->asn, 'network' => $last !== null ? $last['network_name'] : mb_substr((string) $facts?->networkName, 0, 200),
            'method' => mb_substr($method, 0, 10), 'path' => $this->paths->masked($path),
        ]);
    }
}
