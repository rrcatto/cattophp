<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Didit's API, the two calls verification needs: POST /v3/session/ starts a verification session with the workflow id,
 * the account's reference as vendor_data and the address the person returns to, and answers with the session id and
 * the hosted page's url; GET /v3/session/{id}/decision/ reads a session's result, so a check finishes when the person
 * returns even where Didit's webhook cannot reach the site (a development machine). Both authenticate with the
 * x-api-key header. Nothing about the person is sent except vendor_data. Needs symfony/http-client.
 */
final class DiditClient
{
    public const API = 'https://verification.didit.me';

    public function __construct(private readonly HttpClientInterface $http, private readonly IdentitySettings $settings) {}

    /**
     * @return array{session_id:string,url:string}
     */
    public function createSession(string $vendorData, string $callback): array
    {
        $body = $this->call('POST', '/v3/session/', ['json' => ['workflow_id' => $this->settings->workflowId(), 'vendor_data' => $vendorData, 'callback' => $callback]]);
        $sessionId = (string) ($body['session_id'] ?? '');
        $url = (string) ($body['url'] ?? '');
        if ($sessionId === '' || preg_match('#^https://#', $url) !== 1) {
            throw new RuntimeException('The identity check could not be started. Please try again in a few minutes.');
        }
        return ['session_id' => $sessionId, 'url' => $url];
    }

    /** @return array<string,mixed> the session's decision report */
    public function decision(string $sessionId): array
    {
        if (preg_match('/^[A-Za-z0-9-]{8,80}$/', $sessionId) !== 1) {
            throw new RuntimeException('That identity check could not be found.');
        }
        return $this->call('GET', '/v3/session/' . $sessionId . '/decision/', []);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, array $options): array
    {
        if (!$this->settings->available()) {
            throw new RuntimeException('Identity verification is not available at the moment.');
        }
        try {
            $response = $this->http->request($method, self::API . $path, $options + [
                'headers' => ['x-api-key' => $this->settings->apiKey(), 'Accept' => 'application/json'],
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (Throwable $failed) {
            throw new RuntimeException('The identity verification service could not be reached. Please try again in a few minutes.', 0, $failed);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('The identity verification service refused the request (' . $status . '). Please try again in a few minutes.');
        }
        return $body;
    }
}
