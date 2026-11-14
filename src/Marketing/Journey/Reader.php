<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\TouchAgent;
use CattoPHP\Marketing\Visitor;

/**
 * Who is reading a page, worked out once for each request (Journey\Readers): what their browser is (TouchAgent's kind
 * and device), whether it asked not to be tracked, their country, the visitor its cookie names (none for anything but a
 * person, a browser asking not to be tracked or a visitor made anonymous), the signed-in account, and whether they are
 * followed: a person not asking not to be tracked whose cookie names a visitor or whose account some visitor is tied
 * to. Only a followed reader's actions become journey events. Their current campaign (the latest counted click of their
 * visitors within MARKETING_SALE_COUNTS_WITHIN) is read only when an event is about to be recorded.
 */
final class Reader
{
    private ?Touch $campaign = null;
    private bool $campaignRead = false;

    /**
     * @param list<int> $visitorIds the person's visitors: the cookie's and every one tied to the account
     * @param \Closure(list<int>):?Touch $campaignOf reads the current campaign of some visitors
     */
    public function __construct(
        public readonly string $agent,
        public readonly string $device,
        public readonly bool $doNotTrack,
        public readonly string $country,
        public readonly ?Visitor $visitor,
        public readonly ?string $account,
        public readonly bool $followed,
        public readonly array $visitorIds,
        private readonly \Closure $campaignOf,
    ) {
    }

    public function isPerson(): bool
    {
        return $this->agent === TouchAgent::PERSON;
    }

    /** The visitor of this browser's cookie, as an event names it. */
    public function visitorId(): ?int
    {
        return $this->doNotTrack ? null : $this->visitor?->id;
    }

    /** The account as an event names it: none for a browser asking not to be tracked. */
    public function eventAccount(): ?string
    {
        return $this->doNotTrack ? null : $this->account;
    }

    /** Who an idempotency key names: the account, else the visitor ("v" and its id), else null. */
    public function who(): ?string
    {
        return $this->eventAccount() ?? ($this->visitorId() === null ? null : 'v' . $this->visitorId());
    }

    /** The current campaign's click: the latest counted touch of the person's visitors within the sale's window, or null. */
    public function campaign(): ?Touch
    {
        if (!$this->campaignRead) {
            $this->campaign = $this->followed ? ($this->campaignOf)($this->visitorIds) : null;
            $this->campaignRead = true;
        }

        return $this->campaign;
    }
}
