<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Mailing\TransportRefused;
use CattoPHP\Mailing\TransportUnavailable;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * catto-mail's API, as its contract describes it: JSON over HTTPS at <address>/v1 with the API key as a Bearer token, each
 * write with an Idempotency-Key so a request asked twice is done once. A busy answer (408, 425, 429, 5xx, or an
 * idempotent request still in progress) is asked again twice, waiting as catto-mail says up to 10 seconds, then
 * TransportUnavailable; a quota used up is QuotaExceeded, with when it resets; any other refusal is TransportRefused with
 * catto-mail's words and the fields it named. The address, key and secrets are Settings > Mailing's.
 */
final class CattoMailClient
{
    /** The most recipients sent in one request. */
    public const BATCH = 500;

    public function __construct(private readonly HttpClientInterface $http, private readonly MailingSettings $settings)
    {
    }

    public function configured(): bool
    {
        return $this->base() !== '' && $this->settings->value('cattomail_key') !== '';
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed> the SendJob
     */
    public function createJob(array $job, string $idempotencyKey): array
    {
        return $this->call('POST', '/send-jobs', $job, $idempotencyKey);
    }

    /**
     * @param list<array<string,mixed>> $recipients
     * @return array<string,mixed>
     */
    public function addRecipients(string $jobId, array $recipients, string $idempotencyKey): array
    {
        return $this->call('POST', '/send-jobs/' . rawurlencode($jobId) . '/recipients', ['recipients' => $recipients], $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function submit(string $jobId): array
    {
        return $this->call('POST', '/send-jobs/' . rawurlencode($jobId) . '/submit');
    }

    /** @return array<string,mixed> */
    public function job(string $jobId): array
    {
        return $this->call('GET', '/send-jobs/' . rawurlencode($jobId));
    }

    /** @return array{data:list<array<string,mixed>>,next_cursor:?string} one page of a job's messages */
    public function messages(string $jobId, ?string $cursor): array
    {
        $page = $this->call('GET', '/send-jobs/' . rawurlencode($jobId) . '/messages?limit=1000' . ($cursor !== null ? '&cursor=' . rawurlencode($cursor) : ''));
        $next = is_array($page['pagination'] ?? null) ? ($page['pagination']['next_cursor'] ?? null) : null;
        /** @var list<array<string,mixed>> $data */
        $data = array_values(array_filter((array) ($page['data'] ?? []), 'is_array'));

        return ['data' => $data, 'next_cursor' => is_string($next) && $next !== '' ? $next : null];
    }

    /** @return list<array<string,mixed>> the sending domains and whether each can send */
    public function sendingDomains(): array
    {
        /** @var list<array<string,mixed>> */
        return array_values(array_filter((array) ($this->call('GET', '/sending-domains')['data'] ?? []), 'is_array'));
    }

    /**
     * Exchanges the one-time code catto-mail's connect page gave for the connection's key, webhook secret and sending
     * domains. The one request without a key.
     *
     * @return array<string,mixed>
     */
    public function exchange(string $host, string $code, string $verifier, string $redirectUri): array
    {
        try {
            $response = $this->http->request('POST', rtrim($host, '/') . '/v1/connect/exchange', ['json' => ['code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri],
                'headers' => ['Accept' => 'application/json, application/problem+json'], 'timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface $network) {
            throw new TransportUnavailable('catto-mail could not be reached: ' . $network->getMessage());
        }
        if ($status < 300 && is_array($data) && is_string($data['api_key'] ?? null)) {
            /** @var array<string,mixed> $data */
            return $data;
        }
        $type = is_array($data) ? self::text($data['type'] ?? '') : '';
        $detail = is_array($data) ? trim(self::text($data['detail'] ?? '')) : '';
        throw new TransportRefused(match (true) {
            str_ends_with($type, '/invalid-connect-code') => 'That approval cannot be used: it was used already, it expired, or it was for another request. Press Connect to approve again.',
            str_ends_with($type, '/connect-refused') => 'catto-mail did not connect it: ' . ($detail !== '' ? $detail : 'the account is not active, or has no room for another key or webhook.') . ' Put it right at catto-mail, then connect again.',
            $status === 429 => 'catto-mail asks to wait a moment before trying again.',
            default => 'catto-mail answered ' . $status . ($detail !== '' ? ': ' . $detail : '.'),
        }, $status, self::slug($type));
    }

    /** Ends this site's connection at catto-mail: its key is revoked and its webhook removed. False when it was ended already. */
    public function disconnect(): bool
    {
        return $this->status('DELETE', '/connection') === 204;
    }

    /**
     * Whether catto-mail can be reached and takes the key: a job that cannot exist answers 404 to a key it accepts, and
     * 401 to one it does not. 0 when it cannot be reached.
     */
    public function probe(): int
    {
        return $this->status('GET', '/send-jobs/00000000-0000-0000-0000-000000000000');
    }

    /** catto-mail's own address, without /v1: where its connect and DNS pages are. */
    public function host(): string
    {
        $base = $this->base();

        return $base === '' ? '' : substr($base, 0, -3);
    }

    /** A request's status code only (0 when catto-mail cannot be reached). */
    private function status(string $method, string $path): int
    {
        if (!$this->configured()) return 0;
        try {
            return $this->http->request($method, $this->base() . $path, ['headers' => ['Authorization' => 'Bearer ' . $this->settings->value('cattomail_key'), 'Accept' => 'application/json, application/problem+json'],
                'timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0])->getStatusCode();
        } catch (TransportExceptionInterface) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        if (!$this->configured()) throw new TransportRefused('catto-mail is not connected: connect it on Mailing, catto-mail.');
        $headers = ['Authorization' => 'Bearer ' . $this->settings->value('cattomail_key'), 'Accept' => 'application/json, application/problem+json'];
        if ($idempotencyKey !== null) $headers['Idempotency-Key'] = $idempotencyKey;
        $options = ['headers' => $headers, 'timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0];
        if ($body !== null) $options['json'] = $body;
        $last = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->http->request($method, $this->base() . $path, $options);
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
                $decoded = $content === '' ? [] : json_decode($content, true);
                /** @var array<string,mixed> $data */
                $data = is_array($decoded) ? $decoded : [];
                if ($status < 300) return $data;
                $slug = self::slug(self::text($data['type'] ?? ''));
                $words = trim(self::text($data['detail'] ?? $data['title'] ?? ''));
                $last = 'catto-mail answered ' . $status . ($words !== '' ? ': ' . $words : '');
                // A quota used up does not come back by asking again: it waits until catto-mail says it resets.
                if ($status === 429 && $slug === 'quota-exceeded') {
                    $resets = is_array($data['quota'] ?? null) ? self::text($data['quota']['resets_at'] ?? '') : '';
                    throw new QuotaExceeded($last, $resets !== '' && strtotime($resets) !== false ? new DateTimeImmutable($resets) : null);
                }
                $busy = in_array($status, [408, 425, 429], true) || $status >= 500 || ($status === 409 && $slug === 'idempotency-in-progress');
                if (!$busy) {
                    foreach ((array) ($data['errors'] ?? []) as $error) if (is_array($error)) $last .= ' ' . trim(self::text($error['pointer'] ?? '') . ' ' . self::text($error['message'] ?? ''));
                    throw new TransportRefused($last, $status, $slug);
                }
                $wait = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
                if ($attempt === 3) throw new TransportUnavailable($last, $wait);
                sleep(max(1, min(10, $wait > 0 ? $wait : $attempt)));
            } catch (TransportExceptionInterface $network) {
                $last = 'catto-mail could not be reached: ' . $network->getMessage();
                if ($attempt === 3) throw new TransportUnavailable($last);
                sleep($attempt);
            }
        }

        throw new TransportUnavailable($last);
    }

    private function base(): string
    {
        $base = rtrim($this->settings->value('cattomail_url'), '/');
        if ($base === '' || !str_starts_with($base, 'https://')) return '';

        return str_ends_with($base, '/v1') ? $base : $base . '/v1';
    }

    /** The last part of a problem type, such as quota-exceeded. */
    private static function slug(string $type): string
    {
        return $type === '' ? '' : substr($type, (int) strrpos($type, '/') + (str_contains($type, '/') ? 1 : 0));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
