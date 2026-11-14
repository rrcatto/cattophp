<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\NamedAccount;
use CattoPHP\Marketing\Journey\EventInput;
use CattoPHP\Marketing\Journey\JourneyRecorded;
use CattoPHP\Marketing\Journey\Reader;
use CattoPHP\Marketing\Journey\Readers;
use CattoPHP\Marketing\Landing\Events\LandingOfferPressed;
use CattoPHP\Marketing\Landing\Events\LandingPageOpened;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingView;
use CattoPHP\Network\NetworkLookup;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A visitor's journey through the site, as events (Marketing\Events), as the project reports it.
 *
 * Step 2's two, recorded for every person so they can be counted: a landing page opened (the visit, never for a preview)
 * and an offer's button pressed. Each holds the visitor (from the cookie, or the tracking link opened in the same
 * request), the signed-in reader's account, the campaign and publication, the touch of the same request, the page, the
 * offer and the country, and is announced afterwards (LandingPageOpened, LandingOfferPressed).
 *
 * Step 3's, recorded only for a followed reader (Journey\Reader: a person not asking not to be tracked, whose cookie
 * names a visitor or whose account a visitor is tied to): an offer viewed, favourited or put in the basket, a free offer
 * taken, a list subscribed to, a registration, a sign-in and coming back. Each holds the visitor and account, the
 * reader's current campaign, publication and click, the offer, a small detail and the country, and is announced
 * afterwards as a Journey\JourneyRecorded. A registration or a sign-in is also what ties a visitor to an account.
 *
 * A browser asking not to be tracked, with that setting on, is recorded with its country and no visitor and no account.
 * Every method returns nothing and never throws: a failure is logged and the page goes on. Each takes the request (the
 * current one when none is given) and the account (the signed-in reader's when none is given), so a service with no
 * request of its own can call it.
 */
final class Journey
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Events $events,
        private readonly Visitors $visitors,
        private readonly MarketingSettings $settings,
        private readonly NetworkLookup $network,
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ClockInterface $clock = null,
        private readonly ?Readers $readers = null,
        private readonly ?RequestStack $requests = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /** A landing page drawn for a reader: its visit recorded (a person's only), then announced. A preview records nothing. */
    public function opened(LandingView $view, Request $request, ?TrackedOpening $link = null): void
    {
        if ($view->isPreview()) {
            return;
        }
        $account = $this->account();
        $recorded = false;
        try {
            $reader = $this->landingReader($request, $link);
            if ($reader['person']) {
                $offer = $view->page->isOfferPage() ? [$view->page->offerType, $view->page->offerReference] : [null, null];
                $this->events->record(new EventInput(Events::LANDING_OPENED, $this->clock->now(), $reader['visitor'], $reader['do_not_track'] ? null : $account, $view->context->campaign?->id,
                    $link?->publicationId, $link?->touchId, $view->page->id, $offer[0], $offer[1], [], $reader['country'], $reader['do_not_track']));
                $recorded = true;
            }
        } catch (\Throwable $failure) {
            $this->logger->error('A landing page\'s visit could not be recorded; the page was drawn all the same.', ['page' => $view->page->reference, 'exception' => $failure]);
        }
        $this->announce(new LandingPageOpened($view, $request, $account, $recorded));
    }

    /**
     * An offer's button pressed on a published landing page and accepted (LandingPresses checks the page): recorded with
     * the action and the section's reference, then announced.
     */
    public function pressed(LandingPage $page, ?Offer $offer, string $action, Request $request, ?string $section = null, ?int $campaignId = null): void
    {
        $account = $this->account();
        try {
            $reader = $this->landingReader($request, null);
            if ($reader['person']) {
                $this->events->record(new EventInput(Events::LANDING_PRESSED, $this->clock->now(), $reader['visitor'], $reader['do_not_track'] ? null : $account, $campaignId ?? $page->campaignId,
                    null, null, $page->id, $offer?->type, $offer?->reference, ['action' => $action, 'section' => $section], $reader['country'], $reader['do_not_track']));
            }
        } catch (\Throwable $failure) {
            $this->logger->error('A landing page\'s button press could not be recorded; the press went on all the same.', ['page' => $page->reference, 'exception' => $failure]);
        }
        $this->announce(new LandingOfferPressed($page, $offer, $action, $request, $account, $section));
    }

    /**
     * A followed reader opened an offer's own page. A second view of the same offer by the same visitor or account within
     * MARKETING_SAME_VISIT is not recorded again. The project never calls it for a preview or its own editing.
     */
    public function viewed(Offer $offer, ?Request $request = null, ?string $account = null): void
    {
        $this->safely('An offer\'s view', function () use ($offer, $request, $account): void {
            $reader = $this->followed($request, $account);
            if ($reader === null || $this->events->latest([Events::OFFER_VIEWED], $reader->visitorId(), $reader->eventAccount(), $offer->type, $offer->reference, $this->sameVisitStart()) !== null) {
                return;
            }
            $this->write(Events::OFFER_VIEWED, $reader, $offer);
        });
    }

    /** A followed reader favourited an offer ($on) or took it out of their favourites; a repeat of the same within the visit is not recorded. */
    public function favourited(Offer $offer, bool $on, ?Request $request = null, ?string $account = null): void
    {
        $this->safely('A favourite', function () use ($offer, $on, $request, $account): void {
            $reader = $this->followed($request, $account);
            if ($reader === null) {
                return;
            }
            $kind = $on ? Events::FAVOURITED : Events::UNFAVOURITED;
            $last = $this->events->latest([Events::FAVOURITED, Events::UNFAVOURITED], $reader->visitorId(), $reader->eventAccount(), $offer->type, $offer->reference, $this->sameVisitStart());
            if ($last?->kind === $kind) {
                return;
            }
            $this->write($kind, $reader, $offer);
        });
    }

    /**
     * A followed reader put an offer in their basket ($added) or took it out, with how many. Each change a person made is
     * recorded; never the project's own merging of a guest's basket at sign-in (the project does not call it then).
     */
    public function basketChanged(Offer $offer, bool $added, int $quantity = 1, ?Request $request = null, ?string $account = null): void
    {
        $this->safely('A change to the basket', function () use ($offer, $added, $quantity, $request, $account): void {
            $reader = $this->followed($request, $account);
            if ($reader !== null) {
                $this->write($added ? Events::BASKET_ADDED : Events::BASKET_REMOVED, $reader, $offer, ['quantity' => max(1, $quantity)]);
            }
        });
    }

    /** A followed reader took a free offer without an order: recorded once for each person and offer. */
    public function taken(Offer $offer, ?Request $request = null, ?string $account = null): void
    {
        $this->safely('A free offer taken', function () use ($offer, $request, $account): void {
            $reader = $this->followed($request, $account);
            if ($reader !== null && $reader->who() !== null) {
                $this->write(Events::TAKEN, $reader, $offer, [], 'taken:' . $reader->who() . ':' . $offer->key());
            }
        });
    }

    /**
     * A followed reader agreed to a list: its label (at most 80 characters, naming no person, such as "News and
     * updates"), recorded once for each person and list.
     */
    public function subscribed(string $listLabel, ?Request $request = null, ?string $account = null): void
    {
        $this->safely('A subscription', function () use ($listLabel, $request, $account): void {
            $label = trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $listLabel)), 0, 80));
            $reader = $this->followed($request, $account);
            if ($label !== '' && $reader !== null && $reader->who() !== null) {
                $this->write(Events::SUBSCRIBED, $reader, null, ['list' => $label], 'subscribed:' . $reader->who() . ':' . $label);
            }
        });
    }

    /**
     * The followed reader's visitor reference, for the project to keep with a pending registration or an emailed link
     * and give back to registered(); null when the reader is not followed or their browser has no visitor.
     */
    public function carry(?Request $request = null): ?string
    {
        try {
            $request ??= $this->request();
            if ($request === null || $this->readers === null) {
                return null;
            }
            $reader = $this->readers->read($request);

            return $reader->followed ? $reader->visitor?->reference : null;
        } catch (\Throwable $failure) {
            $this->logger->error('The Campaign Marketing Manager could not read the visitor to carry; the page went on all the same.', ['exception' => $failure]);

            return null;
        }
    }

    /**
     * A new account: the visitor $carried names (the browser where the form was filled in, from carry()) and the visitor
     * of this browser's cookie (the browser that opened the link) are each tied to it if they have no account yet. Then
     * the registration is recorded once (key registered:<account>), only when one of those visitors is now the
     * account's: a registration no campaign touched is not a marketing event. A browser asking not to be tracked is never
     * tied and records nothing.
     */
    public function registered(string $account, ?string $carried = null, ?Request $request = null): void
    {
        $this->safely('A registration', function () use ($account, $carried, $request): void {
            [$reader, $request] = $this->readerAndRequest($request, $account);
            if ($reader === null || $reader->doNotTrack) {
                return;
            }
            $tied = [];
            $named = $carried === null || $carried === '' ? null : $this->visitors->byReference($carried);
            foreach ([$reader->visitor, $named] as $visitor) {
                if ($visitor !== null && !$visitor->isAnonymous()) {
                    $this->visitors->identify($visitor->id, $account);
                    if ($this->visitors->byId($visitor->id)?->account === $account) {
                        $tied[] = $visitor;
                    }
                }
            }
            if ($tied === []) {
                return;
            }
            // The campaign is the account's visitors' own, whatever this browser is (the link may be opened anywhere).
            $this->readers?->forget($request);
            $visitorIds = array_map(static fn(Visitor $visitor): int => $visitor->id, $this->visitors->ofAccount($account));
            $this->write(Events::REGISTERED, $reader, null, [], 'registered:' . $account, $tied[0]->id, $account, $this->readers?->campaignOf($visitorIds));
        });
    }

    /**
     * A sign-in: this browser's visitor is tied to the account if it has none (a browser two people use keeps its first
     * person). Recorded for a followed reader, not again within the same visit.
     */
    public function signedIn(string $account, ?Request $request = null): void
    {
        $this->safely('A sign-in', function () use ($account, $request): void {
            [$reader, $request] = $this->readerAndRequest($request, $account);
            if ($reader === null) {
                return;
            }
            if ($reader->visitor !== null && $reader->visitor->account === null && $this->visitors->identify($reader->visitor->id, $account)) {
                $this->readers?->forget($request);
                $reader = $this->readers?->read($request, $account) ?? $reader;
            }
            // The same person's sign-in within the visit; another person signing in on a browser they share is theirs.
            if (!$reader->followed || $this->events->latest([Events::SIGNED_IN], null, $account, null, null, $this->sameVisitStart()) !== null) {
                return;
            }
            $this->write(Events::SIGNED_IN, $reader);
        });
    }

    /**
     * A followed reader with the visitor cookie opened a page (Journey\VisitorReturns calls it, never the project): when
     * nothing of theirs (no page seen, no event, no click) happened within MARKETING_SAME_VISIT, they came back, recorded
     * with the referring host, "typed" when there is none, or "here" when it is this site (a page left open and used
     * again). Their visitor's last_seen_at moves on either way.
     */
    public function cameBack(Request $request): void
    {
        $this->safely('A visitor coming back', function () use ($request): void {
            if ($this->readers === null) {
                throw new \LogicException('Marketing\Journey is not given its Journey\Readers.');
            }
            $reader = $this->readers->read($request);
            $visitor = $reader->visitor;
            if (!$reader->followed || $visitor === null) {
                return;
            }
            $now = $this->clock->now();
            $start = $this->sameVisitStart();
            $this->visitors->seen($visitor->id, $now);
            if ($visitor->lastSeenAt >= $start || $this->events->latest(Events::KINDS, $visitor->id, $reader->eventAccount(), null, null, $start) !== null) {
                return;
            }
            $referrer = parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);
            $from = is_string($referrer) && $referrer !== '' ? strtolower($referrer) : 'typed';
            // From one of this site's own pages: a page left open and used again.
            $this->write(Events::CAME_BACK, $reader, null, ['from' => $from === strtolower($request->getHost()) ? 'here' : $from]);
        });
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
     * Writes one of step 3's events for a reader, with their current campaign, publication and click (or the click
     * given), and announces it.
     *
     * @param array<string,scalar|null> $detail
     */
    private function write(string $kind, Reader $reader, ?Offer $offer = null, array $detail = [], ?string $key = null, ?int $visitorId = null, ?string $account = null,
        ?Touch $campaign = null): void
    {
        $touch = $campaign ?? $reader->campaign();
        $id = $this->events->record(new EventInput($kind, $this->clock->now(), $visitorId ?? $reader->visitorId(), $account ?? $reader->eventAccount(), $touch?->campaignId,
            $touch?->publicationId, $touch?->id, null, $offer?->type, $offer?->reference, $detail, $reader->country, $reader->doNotTrack, $key));
        if ($id === null) {
            return;
        }
        $event = $this->events->byId($id);
        if ($event !== null) {
            $this->announce(new JourneyRecorded($event, $offer));
        }
    }

    /** The reader of a request, when they are followed; null otherwise, or when there is no request. */
    private function followed(?Request $request, ?string $account): ?Reader
    {
        [$reader] = $this->readerAndRequest($request, $account);

        return $reader !== null && $reader->followed ? $reader : null;
    }

    /** @return array{0:?Reader,1:Request} */
    private function readerAndRequest(?Request $request, ?string $account): array
    {
        if ($this->readers === null) {
            throw new \LogicException('Marketing\Journey is not given its Journey\Readers.');
        }
        // Without a request (a command, a queued job) the reader is nobody's browser: no visitor, no cookie.
        $request ??= $this->request() ?? new Request();

        return [$this->readers->read($request, $account), $request];
    }

    private function request(): ?Request
    {
        return $this->requests?->getCurrentRequest();
    }

    private function sameVisitStart(): \DateTimeImmutable
    {
        return $this->settings->sameVisit()->before($this->clock->now());
    }

    /** @param \Closure():void $record */
    private function safely(string $what, \Closure $record): void
    {
        try {
            $record();
        } catch (\Throwable $failure) {
            $this->logger->error($what . ' could not be recorded in the visitor\'s journey; the page went on all the same.', ['exception' => $failure]);
        }
    }

    /**
     * Who is reading a landing page: whether a person, their visitor (none when not tracked), their country and whether
     * they asked not to be tracked. A tracking link opened in the same request has already worked these out.
     *
     * @return array{person:bool,visitor:?int,country:string,do_not_track:bool}
     */
    private function landingReader(Request $request, ?TrackedOpening $link): array
    {
        if ($link !== null) {
            return ['person' => $link->agent === TouchAgent::PERSON, 'visitor' => $link->visitorId, 'country' => $link->country, 'do_not_track' => $link->doNotTrack];
        }
        $agent = TouchAgent::read((string) $request->headers->get('User-Agent', ''));
        $doNotTrack = $agent->isPerson() && $this->settings->honoursDoNotTrack() && ($request->headers->get('DNT') === '1' || $request->headers->get('Sec-GPC') === '1');
        $ip = $request->getClientIp();
        $country = $ip !== null && $ip !== '' ? $this->network->facts($ip)->countryCode : '';
        $visitor = null;
        if ($agent->isPerson() && !$doNotTrack) {
            $token = $request->cookies->get(Visitors::COOKIE);
            $visitor = is_string($token) ? $this->visitors->byToken($token)?->id : null;
        }

        return ['person' => $agent->isPerson(), 'visitor' => $visitor, 'country' => $country, 'do_not_track' => $doNotTrack];
    }

    private function announce(object $event): void
    {
        try {
            $this->dispatcher?->dispatch($event);
        } catch (\Throwable $failure) {
            $this->logger->error('A listener to the Campaign Marketing Manager\'s ' . (new \ReflectionClass($event))->getShortName() . ' failed; the page went on all the same.', ['exception' => $failure]);
        }
    }
}
