<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a public form that sends something (a sign-in link, a contact message) was sent by a bot: the hidden field
 * people never see was filled in, the server in front marked the network, no browser was named, or the browser is years
 * out of date or a script. The controller then shows the usual answer and sends nothing, so the bot learns nothing.
 * A form from a private or loopback address is never refused: that is the site's own tools and test sites.
 *
 * Every such form carries the trap, hidden from people and from screen readers:
 *
 *     <div class="cl-trap" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
 */
final class BotCheck
{
    public const TRAP_FIELD = 'website';

    /** Set to 1 by a server in front for networks it distrusts. A visitor who sends it only refuses themselves. */
    public const SUSPECT_HEADER = 'X-Suspect-Network';

    public const SIGNALS = [
        'trap' => 'The hidden field was filled in',
        'suspect_network' => 'A network the server marked',
        'no_browser' => 'No browser named',
        'outdated' => 'A browser years out of date',
        'script' => 'A script, not a browser',
    ];

    /** Why the form is taken for a bot's (a key of SIGNALS), or null for a person's. */
    public static function signal(Request $request): ?string
    {
        if (self::isPrivate((string) $request->getClientIp())) {
            return null;
        }
        $trap = $request->request->get(self::TRAP_FIELD);
        if (is_scalar($trap) && trim((string) $trap) !== '') {
            return 'trap';
        }
        if (trim((string) $request->headers->get(self::SUSPECT_HEADER)) === '1') {
            return 'suspect_network';
        }

        return match (VisitorAgent::read((string) $request->headers->get('User-Agent', ''))->kind) {
            VisitorAgent::NONE => 'no_browser',
            VisitorAgent::OUTDATED => 'outdated',
            VisitorAgent::SCRIPT, VisitorAgent::CRAWLER, VisitorAgent::SEARCH => 'script',
            default => null,
        };
    }

    /** A loopback or private address, or none at all: never a stranger on the internet. */
    public static function isPrivate(string $address): bool
    {
        $address = (string) strtok($address, '/');
        if ($address === '') {
            return true;
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($address === '::1' || str_starts_with($address, '127.')) {
            return true;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }
}
