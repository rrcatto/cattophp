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
     * The application's workflows, by a call Didit documents as a pure read (GET /v3/workflows/): what a key may use, so
     * Check the connection proves the key and finds the workflow without creating anything.
     *
     * @return list<array{workflow_id:string,label:string,status:string,is_default:bool}>
     */
    public function workflows(): array
    {
        $body = $this->call('GET', '/v3/workflows/', [], false);
        // A list of workflows, or (Didit's documentation is of two minds) a page of them under results.
        $rows = is_array($body['results'] ?? null) ? $body['results'] : array_values($body);
        $found = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['workflow_id'] ?? null)) continue;
            $found[] = ['workflow_id' => $row['workflow_id'], 'label' => is_string($row['workflow_label'] ?? null) ? $row['workflow_label'] : '',
                'status' => is_string($row['status'] ?? null) ? $row['status'] : '', 'is_default' => ($row['is_default'] ?? false) === true];
        }

        return $found;
    }

    /**
     * Registers the site's webhook address with Didit (POST /v3/webhook/destinations/, version 3, the format this site
     * reads) and answers the signing secret Didit makes for it, which Didit shows only now.
     */
    public function registerWebhook(string $url): string
    {
        $body = $this->call('POST', '/v3/webhook/destinations/', ['json' => ['label' => 'This site', 'url' => $url, 'enabled' => true, 'webhook_version' => 'v3']], false);
        $secret = is_string($body['secret_shared_key'] ?? null) ? $body['secret_shared_key'] : '';
        if ($secret === '') {
            throw new RuntimeException('Didit registered the webhook but gave no signing secret: copy it from Didit\'s Business Console, under API & Webhooks.');
        }

        return $secret;
    }

    /**
     * @param array<string,mixed> $options
     * @param bool $needsAll whether all three keys are needed (a verification), or the API key alone (a check, the webhook)
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, array $options, bool $needsAll = true): array
    {
        if ($needsAll ? !$this->settings->available() : $this->settings->apiKey() === '') {
            throw new RuntimeException($needsAll ? 'Identity verification is not available at the moment.' : 'Enter Didit\'s API key first.');
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
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Didit did not accept the API key (' . $status . '). Check it in Didit\'s Business Console, under API & Webhooks.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('The identity verification service refused the request (' . $status . '). Please try again in a few minutes.');
        }
        return $body;
    }
}
