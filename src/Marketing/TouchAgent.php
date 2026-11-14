<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Http\VisitorAgent;

/**
 * What opened a tracking link, read from its browser's description by Http\VisitorAgent: a person, a link preview (the
 * program that draws a shared link's card in WhatsApp, Facebook, LinkedIn...), a search engine, an AI's crawler or another
 * bot. The programs that open the links in an email before its reader does (a mail server's link scanner, a mail app's
 * picture or link checker) are bots, however much their description reads as a browser's. Only a person's opening is a
 * click; the others are recorded and set aside. A person's device is read too.
 */
final class TouchAgent
{
    public const PERSON = 'person';
    public const PREVIEW = 'preview';
    public const SEARCH = 'search';
    public const AI = 'ai';
    public const BOT = 'bot';

    public const WORDS = [self::PERSON => 'A person', self::PREVIEW => 'Link preview', self::SEARCH => 'Search engine', self::AI => 'AI crawler', self::BOT => 'Other bot'];

    /** The programs that draw a shared link's preview, which VisitorAgent counts among crawlers. */
    private const PREVIEWS = '/facebookexternalhit|facebookcatalog|meta-externalfetcher|Twitterbot|LinkedInBot|Slackbot|TelegramBot|Discordbot|Pinterest|SkypeUriPreview|redditbot|Embedly|Iframely|vkShare|Viber|Snapchat|Google-PageRenderer|preview/i';

    /**
     * The email link scanners and mail apps' fetchers that name themselves: they open every link in a message as it
     * arrives, so their openings are never a reader's click.
     */
    private const SCANNERS = '/Barracuda|Mimecast|Proofpoint|MessageLabs|Symantec|Trend ?Micro|Forcepoint|IronPort|Cisco[ -]?(Secure|Email)|Sophos|Avanan|Check ?Point|Abnormal ?Security|FireEye|Trellix|Zscaler|SafeLinks|Safe ?Links|Microsoft Office (Protocol|Existence) Discovery|GoogleImageProxy|YahooMailProxy|Google-Safety|Hornetsecurity|SpamTitan|Libraesva|Vade ?Secure|Cloudmark/i';

    private function __construct(public readonly string $kind, public readonly string $device)
    {
    }

    public static function read(string $agent): self
    {
        if (preg_match(self::SCANNERS, $agent) === 1) {
            return new self(self::BOT, 'unknown');
        }
        $read = VisitorAgent::read($agent);
        if (!$read->isAutomated()) {
            return new self(self::PERSON, match ($read->device) {
                'computer' => 'desktop',
                'phone' => 'phone',
                'tablet' => 'tablet',
                default => 'unknown',
            });
        }
        $kind = match (true) {
            $read->kind === VisitorAgent::SEARCH => self::SEARCH,
            str_starts_with($read->label, 'Link preview') || preg_match(self::PREVIEWS, $agent) === 1 => self::PREVIEW,
            str_starts_with($read->label, 'AI crawler') => self::AI,
            default => self::BOT,
        };

        return new self($kind, 'unknown');
    }

    public function isPerson(): bool
    {
        return $this->kind === self::PERSON;
    }
}
