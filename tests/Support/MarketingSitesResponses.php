<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The answers a test kernel's HTTP client gives (framework.http_client.mock_response_factory), for the Campaign Marketing
 * Manager's connected sites: whatever the test sets as $answer, given the method, the address and the options of each
 * request, which are kept in $requests. With none set, every request fails as an unreachable site does, so a test
 * never reaches the network.
 */
final class MarketingSitesResponses
{
    /** @var (\Closure(string, string, array<string,mixed>): ResponseInterface)|null */
    public ?\Closure $answer = null;

    /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
    public array $requests = [];

    /** @param array<string,mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        if ($this->answer === null) {
            throw new TransportException(sprintf('No answer is set for %s %s in this test.', $method, $url));
        }

        return ($this->answer)($method, $url, $options);
    }
}
