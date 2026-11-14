<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Mail;

use CattoPHP\Installation\Installation;
use CattoPHP\Mailing\CampaignProject;
use CattoPHP\Mailing\Copies;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey\EventInput;
use CattoPHP\Marketing\Journey\JourneyRecorded;
use CattoPHP\Marketing\LinkOpening;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\TrackedOpening;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * The links in a copy of a mailing message counted for a campaign (Mail\MailingPublications), for the project's routes
 * to call beside the mail module's CopyLinks: only the click link and the opening picture. A followed link that leads to
 * a page on this site (a path, or this site's own host) is the campaign's touch, recorded as a tracking link's opening
 * is (TrackingLinks::recordOpening(), source email, the link's own utm_ values, the link's address as where it led), so
 * no link in the email is rewritten; the person it was sent to is tied to the visitor given or kept. An opening of the
 * email is recorded once for each copy, with the copy's account and no visitor (the request is the email app's). A link
 * to another site, a message counted for no campaign and a copy without an account record nothing, nor anything the
 * mail project's rules say is not tracked. The unsubscribe link, its one-click POST, the web copy and Forward to a
 * friend never call these. Recording never breaks the link: a failure is logged and the reader goes on.
 */
final class MailedLinks
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Copies $copies,
        private readonly MailingPublications $publications,
        private readonly Campaigns $campaigns,
        private readonly TrackingLinks $links,
        private readonly Visitors $visitors,
        private readonly Events $events,
        private readonly ?Installation $installation = null,
        private readonly ?CampaignProject $project = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * A link in a copy followed ($token the copy's, $url where it leads): the campaign's touch when the copy's message
     * counts for one and the link leads to this site, with the cookie to give the browser on the redirect (null when
     * none is given); null when nothing was recorded.
     */
    public function followed(string $token, string $url, Request $request): ?TrackedOpening
    {
        try {
            if ($this->project !== null && !$this->project->rules()->trackClicks) {
                return null;
            }
            $copy = $this->copies->byToken($token);
            $account = is_scalar($copy['account'] ?? null) ? (string) $copy['account'] : '';
            if ($copy === null || $account === '' || !$this->leadsHere($url, $request)) {
                return null;
            }
            $publication = $this->publications->forMessage((int) $copy['campaign_id']);
            $campaign = $publication === null ? null : $this->campaigns->byId($publication->campaignId);
            if ($publication === null || $campaign === null) {
                return null;
            }
            $tracked = $this->links->recordOpening(new LinkOpening($campaign, $publication, 'email', self::utm($url), $url), $request);
            if ($tracked->visitorId !== null) {
                $this->visitors->identify($tracked->visitorId, $account);
            }
            if ($publication->publishedOn === null) {
                $this->publications->refresh($publication);
            }

            return $tracked;
        } catch (\Throwable $failure) {
            $this->logger->error('A click on a mailing message\'s link could not be recorded for its campaign; the reader went on all the same.', ['exception' => $failure]);

            return null;
        }
    }

    /** A copy's opening picture shown: email_opened recorded once for the copy, when its message counts for a campaign. */
    public function opened(string $token): void
    {
        try {
            if ($this->project !== null && !$this->project->rules()->trackOpens) {
                return;
            }
            $copy = $this->copies->byToken($token);
            $account = is_scalar($copy['account'] ?? null) ? (string) $copy['account'] : '';
            if ($copy === null || $account === '') {
                return;
            }
            $publication = $this->publications->forMessage((int) $copy['campaign_id']);
            if ($publication === null) {
                return;
            }
            $id = $this->events->record(new EventInput(Events::EMAIL_OPENED, $this->clock->now(), null, $account, $publication->campaignId, $publication->id,
                idempotencyKey: 'email_opened:' . (int) $copy['id']));
            $event = $id === null ? null : $this->events->byId($id);
            if ($event !== null) {
                $this->dispatcher?->dispatch(new JourneyRecorded($event));
            }
        } catch (\Throwable $failure) {
            $this->logger->error('An opening of a mailing message could not be recorded for its campaign; the picture was shown all the same.', ['exception' => $failure]);
        }
    }

    /** Whether a link leads to a page on this site: a path, or an address on this site's own host. */
    private function leadsHere(string $url, Request $request): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $own = array_filter([$this->installation?->domain(), strtolower($request->getHost())], static fn(?string $known): bool => $known !== null && $known !== '');

        return in_array($host, $own, true);
    }

    /**
     * The link's own utm_ values, by their names.
     *
     * @return array<string,?string>
     */
    private static function utm(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $utm = [];
        foreach (Touches::UTM as $name) {
            $value = $query[$name] ?? null;
            $utm[$name] = is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 64) : null;
        }

        return $utm;
    }
}
