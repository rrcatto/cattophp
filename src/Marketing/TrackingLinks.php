<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Installation\Installation;
use CattoPHP\Network\NetworkLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The tracking links: a publication's is /c/{campaign}/{publication}, the campaign's own is /c/{campaign} (which also reads
 * ?src= and the utm_* tags), the first part being the configured prefix. Each is given in three forms to copy. Opening
 * one finds where it leads (the publication's destination, else the campaign's; only a path on the site or an address a
 * manager typed), records the touch, and forwards with the query values it does not know carried through.
 *
 * Only a person (Http\VisitorAgent) arriving by a tracking link is given a visitor and a cookie holding a random token;
 * a browser that asks not to be tracked, with that setting on, is counted with its country alone. A second opening of the
 * same link by the same visitor within the same-visit setting is recorded as the same visit; a campaign that is not
 * running still forwards, its touches set aside with why. A link leading to a connected site forwards with a signed
 * reference for the click (Sites\SiteForward, by Pages\TrackingLinkController).
 */
final class TrackingLinks
{
    /** What a link reads and never carries on: the source, the UTM tags and the identifiers advertisers add to a click. */
    public const NOT_CARRIED = ['src', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid', 'ttclid', 'twclid',
        'li_fat_id', 'igshid', 'igsh', 'yclid', 'mc_cid', 'mc_eid'];

    public const PREFIX = '/^[a-z0-9][a-z0-9-]{0,19}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly Campaigns $campaigns,
        private readonly Publications $publications,
        private readonly Channels $channels,
        private readonly Visitors $visitors,
        private readonly Touches $touches,
        private readonly MarketingSettings $settings,
        private readonly NetworkLookup $network,
        private readonly ?Installation $installation = null,
        private readonly string $prefix = 'c',
    ) {
        if (preg_match(self::PREFIX, $prefix) !== 1) {
            throw new \InvalidArgumentException('cattophp.marketing.link_prefix is small letters, digits and hyphens, up to 20, such as c or go.');
        }
    }

    /** The first part of a tracking link's path, such as c. */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /** A tracking link's path: /c/spring-sale, or /c/spring-sale/fb1 for a publication. */
    public function path(Campaign $campaign, ?Publication $publication = null): string
    {
        return '/' . $this->prefix . '/' . $campaign->code . ($publication === null ? '' : '/' . $publication->code);
    }

    /** A campaign's own tracking link, as a whole address. */
    public function campaignLink(Campaign $campaign): string
    {
        return $this->address($this->path($campaign));
    }

    /** A publication's tracking link in its three forms: the short link, ?src= and UTM tags, each a whole address. */
    public function forms(Campaign $campaign, Publication $publication): TrackingLink
    {
        $channel = $this->channels->get($publication->channelKey);
        $bare = $this->address($this->path($campaign));
        $utm = http_build_query(['utm_source' => $publication->channelKey, 'utm_medium' => $channel !== null ? Rows::slug($channel->label, 40) : $publication->channelKey,
            'utm_campaign' => $campaign->code, 'utm_content' => $publication->code], '', '&', PHP_QUERY_RFC3986);

        return new TrackingLink($this->address($this->path($campaign, $publication)), $bare . '?src=' . rawurlencode($publication->code), $bare . '?' . $utm);
    }

    /**
     * A tracking link being opened: its campaign and publication found, and where it leads; null when the campaign or the
     * publication its path names is unknown (the site's ordinary not found).
     */
    public function open(string $campaignCode, ?string $publicationCode, Request $request): ?LinkOpening
    {
        $campaign = $this->campaigns->get(strtolower($campaignCode));
        if ($campaign === null) {
            return null;
        }
        $publication = null;
        if ($publicationCode !== null && $publicationCode !== '') {
            $publication = $this->publications->get($campaign, strtolower($publicationCode));
            if ($publication === null) {
                return null;
            }
        }
        $src = self::value($request->query->all()['src'] ?? null);
        $utm = [];
        foreach (Touches::UTM as $name) {
            $utm[$name] = self::value($request->query->all()[$name] ?? null);
        }
        // The campaign's own link names a publication by ?src= or utm_content, as the forms to copy give it.
        if ($publication === null) {
            foreach ([$src, $utm['utm_content']] as $named) {
                if ($named !== null && preg_match(Publications::CODE, strtolower($named)) === 1) {
                    $publication = $this->publications->get($campaign, strtolower($named));
                    if ($publication !== null) {
                        break;
                    }
                }
            }
        }
        $source = $publication->channelKey ?? $src ?? $utm['utm_source'] ?? '';

        return new LinkOpening($campaign, $publication, $source, $utm, $this->forwardAddress($publication->destination ?? $campaign->destination, $request));
    }

    /**
     * Records an opening: the visitor (a person's, kept or new), the touch with whether it counts, and the cookie to give
     * the browser, or null when none is given. It throws when the records cannot be written; the visit goes on all the same.
     */
    public function record(LinkOpening $opening, Request $request): ?Cookie
    {
        return $this->recordOpening($opening, $request)->cookie;
    }

    /**
     * Records an opening as record() does, and gives what was recorded: the touch, the visitor (a person's) and the cookie,
     * so a landing page drawn in the same answer records its visit against the same touch and visitor. An arrival by a
     * connected site's link (Sites\ArrivalListener) is recorded the same way, with that site's key, its click's reference
     * and the publication's code there.
     */
    public function recordOpening(LinkOpening $opening, Request $request, ?string $originSite = null, ?string $originReference = null, ?string $originPublication = null): TrackedOpening
    {
        $now = $this->campaigns->now();
        $agent = TouchAgent::read((string) $request->headers->get('User-Agent', ''));
        $ip = $request->getClientIp();
        $country = '';
        if ($ip !== null && $ip !== '') {
            $country = $this->network->facts($ip)->countryCode;
        }
        $doNotTrack = $agent->isPerson() && $this->settings->honoursDoNotTrack() && ($request->headers->get('DNT') === '1' || $request->headers->get('Sec-GPC') === '1');
        $referrer = parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);
        $referrerHost = is_string($referrer) && $referrer !== '' ? strtolower($referrer) : null;
        $campaign = $opening->campaign;
        $publicationId = $opening->publication?->id;

        return $this->connection->transactional(function () use ($opening, $request, $now, $agent, $ip, $country, $doNotTrack, $referrerHost, $campaign, $publicationId, $originSite,
            $originReference, $originPublication): TrackedOpening {
            $visitor = null;
            $cookie = null;
            $first = false;
            if ($agent->isPerson() && !$doNotTrack) {
                $token = $request->cookies->get(Visitors::COOKIE);
                [$visitor] = $this->visitors->arrive(is_string($token) ? $token : null, $country, $agent->device, $now);
                $first = !$this->touches->visitorSeenBefore($visitor->id);
                $cookie = Cookie::create(Visitors::COOKIE, (string) $visitor->token, $this->settings->visitorRemembered()->after($now), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
            }
            $because = match (true) {
                !$agent->isPerson() => $agent->kind,
                $campaign->notCountedBecause($now) !== null => $campaign->notCountedBecause($now),
                $visitor !== null && $this->touches->sameLinkSince($visitor->id, $campaign->id, $publicationId, $this->settings->sameVisit()->before($now)) => 'same_visit',
                default => null,
            };
            $touch = $this->touches->record($campaign->id, $publicationId, $visitor?->id, $opening->source, $opening->utm, $now, $doNotTrack ? null : $ip, $country, $referrerHost,
                $agent->kind, $because, $first, $doNotTrack, $opening->address, $originSite, $originReference, $originPublication);

            return new TrackedOpening($touch, $visitor?->id, $publicationId, $cookie, $agent->kind, $country, $doNotTrack);
        });
    }

    /**
     * Where a link forwards: its destination (a path on the site, or an address a manager typed; anything else the front
     * page) with the query values the link does not know carried through, the destination's own winning.
     */
    public function forwardAddress(string $destination, Request $request): string
    {
        if (Destination::problem($destination) !== null) {
            $destination = '/';
        }
        $carried = array_diff_key($request->query->all(), array_flip(self::NOT_CARRIED));
        if ($carried === []) {
            return $destination;
        }
        $fragment = '';
        if (($hash = strpos($destination, '#')) !== false) {
            $fragment = substr($destination, $hash);
            $destination = substr($destination, 0, $hash);
        }
        [$base, $query] = array_pad(explode('?', $destination, 2), 2, '');
        parse_str($query, $own);
        $merged = $own + $carried;

        return $base . '?' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }

    /** A path as a whole address on the site, or the path alone when the site's address is not known. */
    private function address(string $path): string
    {
        return $this->installation?->addressOf($path) ?? $path;
    }

    /** A query value as text, cut to 64 characters, or null when none or not text. */
    private static function value(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', '', $value));

        return $value === '' ? null : mb_substr($value, 0, 64);
    }
}
