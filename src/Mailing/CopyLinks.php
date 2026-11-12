<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/**
 * What the links in a copy do, for the project's routes. The opening picture records an opening and a tracked link a
 * click (each only when the project's rules record them; a click counts as an opening too) before sending the reader on.
 * A copy that was sent is found by its token for its web copy and Forward to a friend. A copy has stopped its lists when
 * its unsubscribe link or the email app's button was used and its list no longer has its person.
 */
final class CopyLinks
{
    public function __construct(
        private readonly Copies $copies,
        private readonly Links $links,
        private readonly Campaigns $campaigns,
        private readonly Subscriptions $subscriptions,
        private readonly CampaignProject $project,
    ) {
    }

    /** The opening picture was shown. */
    public function opened(string $token): void
    {
        $copy = $this->copies->byToken($token);
        if ($copy !== null && $this->project->rules()->trackOpens) $this->copies->recordOpen((int) $copy['id']);
    }

    /** A tracked link was followed: where it goes, or null when the copy has no such link. */
    public function clicked(string $token, int $linkId): ?string
    {
        $copy = $this->copies->byToken($token);
        $url = $copy === null ? null : $this->links->url((int) $copy['campaign_id'], $linkId);
        if ($copy === null || $url === null) return null;
        if ($this->project->rules()->trackClicks) $this->links->recordClick((int) $copy['id'], $linkId);

        return $url;
    }

    /**
     * A copy that was sent, with its campaign, by its token: null when it is not known, was not sent yet, or its person's
     * account is gone.
     *
     * @return array{copy:array<string,mixed>,campaign:array<string,mixed>}|null
     */
    public function sent(string $token): ?array
    {
        $copy = $this->copies->byToken($token);
        if ($copy === null || ($copy['handed_at'] ?? null) === null || ($copy['account'] ?? null) === null) return null;
        $campaign = $this->campaigns->find((int) $copy['campaign_id']);

        return $campaign === null ? null : ['copy' => $copy, 'campaign' => $campaign];
    }

    /**
     * Whether a copy has stopped what brought it already: its unsubscribe link or the email app's button was used, and its
     * list (if it had one) no longer has its person.
     *
     * @param array<string,mixed> $copy
     */
    public function stopped(array $copy): bool
    {
        if (($copy['unsubscribed_at'] ?? null) === null) return false;
        if (($copy['list_id'] ?? null) === null || ($copy['account'] ?? null) === null) return true;

        return !in_array((int) $copy['list_id'], $this->subscriptions->subscribedListIds((string) $copy['account']), true);
    }
}
