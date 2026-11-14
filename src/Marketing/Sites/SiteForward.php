<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\LinkOpening;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\TouchAgent;
use CattoPHP\Marketing\TrackedOpening;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The signed forward from a tracking link to a connected site, and reading one on arrival.
 *
 * On the sending site: when the address a tracking link forwards a person to is on a connected site (its host is the
 * site's), two query values are added: cattophp_from, the payload (base64url JSON: version 1, this site's address, the
 * campaign's code and name, the publication's code, its channel and the click's reference), and cattophp_sig, its
 * signature (Webhooks\WebhookSignature, with the current and previous secrets, at now). A preview's or a bot's opening,
 * an opening not recorded, and a site whose secret cannot be read forward plainly; nothing about a person, an IP address
 * or anything a visitor typed is ever in the payload.
 *
 * On the selling site: read() finds the sending site by the address in the payload and checks the signature and its
 * time (Sites::verify()); ArrivalListener records what it gives.
 */
final class SiteForward
{
    /** The query value holding the payload. */
    public const FROM = 'cattophp_from';

    /** The query value holding its signature. */
    public const SIGNATURE = 'cattophp_sig';

    /** The form of the payload this class writes and reads. */
    public const VERSION = 1;

    public function __construct(
        private readonly Sites $sites,
        private readonly Touches $touches,
        private readonly ClockInterface $clock,
        private readonly ?Installation $installation = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Where a tracking link's opening forwards: its address, signed for the connected site it is on when a person's
     * opening was recorded; otherwise as it was.
     */
    public function forward(string $address, LinkOpening $opening, ?TrackedOpening $tracked): string
    {
        if ($tracked === null || $tracked->agent !== TouchAgent::PERSON || !str_starts_with(strtolower($address), 'https://')) {
            return $address;
        }
        $site = $this->sites->forHost((string) parse_url($address, PHP_URL_HOST));
        $from = $this->installation?->address() ?? '';
        if ($site === null || $from === '') {
            return $address;
        }
        try {
            $click = $this->touches->byId($tracked->touchId)?->reference;
            if ($click === null) {
                return $address;
            }
            $payload = self::payload(['v' => self::VERSION, 'site' => $from, 'campaign' => $opening->campaign->code, 'name' => $opening->campaign->name,
                'publication' => $opening->publication?->code, 'channel' => $opening->publication->channelKey ?? ($opening->source !== '' ? $opening->source : null), 'click' => $click]);
            $signature = $this->sites->sign($site, $payload, $this->clock->now());
        } catch (\Throwable $failure) {
            $this->logger->error('A tracking link to a connected site forwarded without its signed reference: ' . $failure->getMessage(), ['site' => $site->key, 'exception' => $failure]);

            return $address;
        }

        return self::withValues($address, [self::FROM => $payload, self::SIGNATURE => $signature]);
    }

    /**
     * An arrival's two values read and checked: the site that sent it, and what it says.
     *
     * @throws SiteRefused saying why (for this site's administrator only), with the site when it named a connected one
     */
    public function read(string $from, string $signature, DateTimeImmutable $now): Arrival
    {
        $values = self::decode($from);
        if ($values === null || ($values['v'] ?? null) !== self::VERSION || !is_string($values['site'] ?? null)) {
            throw new SiteRefused('its reference was not one a connected site makes');
        }
        $site = $this->sites->byAddress($values['site']);
        if ($site === null) {
            throw new SiteRefused(sprintf('no connected site has the address it gave, %s', mb_substr($values['site'], 0, 120)));
        }
        $this->sites->verify($site, $signature, $from, $now);
        $campaign = is_string($values['campaign'] ?? null) ? strtolower($values['campaign']) : '';
        $publication = is_string($values['publication'] ?? null) ? strtolower($values['publication']) : null;
        $channel = is_string($values['channel'] ?? null) ? strtolower($values['channel']) : '';
        $click = is_string($values['click'] ?? null) ? $values['click'] : '';
        if (preg_match(Campaigns::CODE, $campaign) !== 1 || ($publication !== null && preg_match(Publications::CODE, $publication) !== 1)
            || ($channel !== '' && preg_match(Channels::KEY, $channel) !== 1) || preg_match(Touches::REFERENCE, $click) !== 1) {
            throw new SiteRefused('its reference was signed but did not hold a campaign, publication and click as a connected site gives them', $site);
        }
        $name = is_string($values['name'] ?? null) ? mb_substr(trim($values['name']), 0, 120) : '';

        return new Arrival($site, $campaign, $name === '' ? $campaign : $name, $publication, $channel, $click);
    }

    /**
     * The payload's values as base64url JSON.
     *
     * @param array<string,mixed> $values
     */
    public static function payload(array $values): string
    {
        return rtrim(strtr(base64_encode(json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @return array<mixed>|null the payload's values, or null when it is not base64url JSON */
    public static function decode(string $payload): ?array
    {
        if ($payload === '' || strlen($payload) > 4000 || preg_match('/^[A-Za-z0-9_-]+$/', $payload) !== 1) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if (!is_string($json)) {
            return null;
        }
        try {
            $values = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($values) ? $values : null;
    }

    /**
     * An address with query values added, a fragment kept at the end.
     *
     * @param array<string,string> $values
     */
    private static function withValues(string $address, array $values): string
    {
        $fragment = '';
        if (($hash = strpos($address, '#')) !== false) {
            $fragment = substr($address, $hash);
            $address = substr($address, 0, $hash);
        }

        return $address . (str_contains($address, '?') ? '&' : '?') . http_build_query($values, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }
}
