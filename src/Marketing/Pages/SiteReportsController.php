<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Sites\ReportReceiver;
use CattoPHP\Marketing\Sites\SiteReports;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a connected site reaches this one (config/routes/marketing_sites.php): POST /webhooks/marketing takes its
 * reports (Sites\ReportReceiver), answering 200 with how many were recorded; GET /webhooks/marketing/check answers 200
 * with this site's name when its signature checks. A sender that is not connected, a signature that does not match and a
 * time too far from this site's clock are answered 401 with an empty body, telling the sender nothing. Every answer
 * carries this site's time in its Date header, from which the other site works out how far apart the two clocks are, and
 * none is kept by a cache.
 */
final class SiteReportsController
{
    public function __construct(private readonly ReportReceiver $receiver, private readonly ClockInterface $clock, private readonly LoggerInterface $logger)
    {
    }

    public function receive(Request $request): Response
    {
        try {
            $recorded = $this->receiver->receive((string) $request->headers->get(SiteReports::FROM_HEADER, ''), (string) $request->headers->get(SiteReports::SIGNATURE_HEADER, ''),
                $request->getContent());
        } catch (\Throwable $failure) {
            $this->logger->error('Reports from a connected site could not all be kept; it will send them again.', ['exception' => $failure]);

            return $this->answer(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->answer($recorded === null ? new Response('', Response::HTTP_UNAUTHORIZED) : new JsonResponse(['recorded' => $recorded]));
    }

    public function check(Request $request): Response
    {
        $name = $this->receiver->answerCheck((string) $request->headers->get(SiteReports::FROM_HEADER, ''), (string) $request->headers->get(SiteReports::SIGNATURE_HEADER, ''));

        return $this->answer($name === null ? new Response('', Response::HTTP_UNAUTHORIZED) : new JsonResponse(['name' => $name]));
    }

    private function answer(Response $response): Response
    {
        $response->setDate(\DateTime::createFromImmutable($this->clock->now()));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
