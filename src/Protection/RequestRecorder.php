<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Http\LiveRefresh;
use CattoPHP\Http\VisitorAgent;
use CattoPHP\Network\NetworkFacts;
use CattoPHP\Network\NetworkLookup;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Throwable;

/**
 * Writes each request into the Site Log as it ends, once the visitor has their answer: who (signed in, a person, a
 * bot), the browser, the country and network, the page with its secrets masked, the answer, where the visitor came
 * from, and what the form guard found. A live page's own refresh is not a visit, and a test run writes nothing.
 */
final class RequestRecorder
{
    public function __construct(
        private readonly SiteLog $log,
        private readonly VisitorAccounts $visitors,
        private readonly NetworkLookup $network,
        private readonly PathMask $paths,
        private readonly string $environment,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $address = (string) $request->getClientIp();
        // The tests' kernels ask for pages with no visitor behind them, and every run must leave the records as it found them.
        if ($address === '' || $this->environment === 'test' || !$event->isMainRequest()) return;
        try {
            if ($request->attributes->get(LiveRefresh::ATTRIBUTE) === true) return;
            $response = $event->getResponse();
            $account = $this->visitors->current();
            $agent = (string) $request->headers->get('User-Agent', '');
            $reading = VisitorAgent::read($agent);
            $facts = $this->network->facts($address);
            [$cameFrom, $source] = Arrival::sharedVia($request->query->all()) ?? Arrival::cameFrom((string) $request->headers->get('Referer', ''), $request->getHost());
            $form = $request->attributes->get(FormGuard::ATTRIBUTE);
            $form = is_array($form) ? $form : [];
            // A page changed in place is a page like any other; htmx names its target, the page's main area.
            $inPlace = str_ends_with((string) $request->headers->get('HX-Target', ''), 'cl-main');
            $page = $request->isMethod('GET') && $response->getStatusCode() < 400 && (!$request->headers->has('HX-Request') || $inPlace)
                && str_starts_with((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
            $this->log->record([
                'address' => $address, 'subject' => Addresses::subject($address), 'account_id' => $account['id'] ?? null, 'by_administrator' => $account !== null && $account['administrator'],
                'visitor' => $account !== null ? 'signed_in' : ($reading->isAutomated() ? 'automated' : 'person'),
                'agent_kind' => $reading->kind, 'agent_label' => mb_substr($reading->label, 0, 80), 'device' => $reading->device, 'user_agent' => mb_substr($agent, 0, 500),
                'country_code' => $facts->countryCode !== '' ? $facts->countryCode : null, 'network_kind' => Addresses::isPrivate($address) ? 'private' : (in_array($facts->kind, NetworkFacts::KINDS, true) ? $facts->kind : ''),
                'network_asn' => $facts->asn, 'network_name' => mb_substr($facts->networkName, 0, 200),
                'method' => mb_substr($request->getMethod(), 0, 10), 'path' => $this->paths->masked($request->getRequestUri()), 'status' => $response->getStatusCode(), 'is_page' => $page,
                'came_from' => $cameFrom, 'source' => $source,
                'form' => $form['form'] ?? null, 'form_signal' => $form['signal'] ?? null, 'form_refused' => (bool) ($form['refused'] ?? false),
                'form_email' => ($form['refused'] ?? false) ? ($form['email'] ?? null) : null, 'form_email_hash' => $form['email_hash'] ?? null,
            ]);
        } catch (Throwable $fault) {
            error_log('A request could not be written to the Site Log: ' . $fault->getMessage());
        }
    }
}
