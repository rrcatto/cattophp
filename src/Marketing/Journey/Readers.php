<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use CattoPHP\Activity\NamedAccount;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\TouchAgent;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Network\NetworkLookup;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Works out who is reading (a Journey\Reader) once for each request and keeps it in the request's attributes: one read
 * of the visitor by the cookie's token, and for a signed-in reader one read of the visitors tied to their account. The
 * account is the one given, else the signed-in reader's (the token storage's). After a visitor is tied to an account in
 * the same request, forget() makes the next read fresh.
 */
final class Readers
{
    /** The request attribute the readers of a request are kept in, by account. */
    public const ATTRIBUTE = '_cattophp_marketing_readers';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Visitors $visitors,
        private readonly Touches $touches,
        private readonly MarketingSettings $settings,
        private readonly NetworkLookup $network,
        private readonly ?TokenStorageInterface $tokens = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    public function read(Request $request, ?string $account = null): Reader
    {
        $account ??= $this->account();
        $kept = $request->attributes->get(self::ATTRIBUTE);
        $kept = is_array($kept) ? $kept : [];
        $key = $account ?? '';
        if (($kept[$key] ?? null) instanceof Reader) {
            return $kept[$key];
        }
        $reader = $this->fresh($request, $account);
        $kept[$key] = $reader;
        $request->attributes->set(self::ATTRIBUTE, $kept);

        return $reader;
    }

    /** Forgets what was worked out for a request, after one of its visitors was tied to an account. */
    public function forget(Request $request): void
    {
        $request->attributes->remove(self::ATTRIBUTE);
    }

    /** The signed-in reader's account as the activity log names it, or null. */
    public function account(): ?string
    {
        $user = $this->tokens?->getToken()?->getUser();
        if ($user === null) {
            return null;
        }

        return $user instanceof NamedAccount ? $user->accountId() : $user->getUserIdentifier();
    }

    /**
     * The current campaign of some visitors: their latest counted click (a person's, not the same visit, while the
     * campaign ran) at or before now and within MARKETING_SALE_COUNTS_WITHIN of it, or null.
     *
     * @param list<int> $visitorIds
     */
    public function campaignOf(array $visitorIds): ?Touch
    {
        $now = $this->clock->now();
        $since = $this->settings->saleCountsWithin()->before($now);
        $latest = null;
        foreach ($this->touches->forVisitors($visitorIds) as $touch) {
            if ($touch->counted && $touch->at >= $since && $touch->at <= $now) {
                $latest = $touch;
            }
        }

        return $latest;
    }

    private function fresh(Request $request, ?string $account): Reader
    {
        $agent = TouchAgent::read((string) $request->headers->get('User-Agent', ''));
        $doNotTrack = $agent->isPerson() && $this->settings->honoursDoNotTrack() && ($request->headers->get('DNT') === '1' || $request->headers->get('Sec-GPC') === '1');
        $ip = $request->getClientIp();
        $country = $ip !== null && $ip !== '' ? $this->network->facts($ip)->countryCode : '';
        $visitor = null;
        $ids = [];
        if ($agent->isPerson() && !$doNotTrack) {
            $token = $request->cookies->get(Visitors::COOKIE);
            $visitor = is_string($token) ? $this->visitors->byToken($token) : null;
            if ($visitor !== null) {
                $ids[] = $visitor->id;
            }
            if ($account !== null) {
                foreach ($this->visitors->ofAccount($account) as $tied) {
                    $ids[] = $tied->id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return new Reader($agent->kind, $agent->device, $doNotTrack, $country, $visitor, $account, $ids !== [], $ids, $this->campaignOf(...));
    }
}
