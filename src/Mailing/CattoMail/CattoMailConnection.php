<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Installation\Installation;
use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Mailing\TransportRefused;
use CattoPHP\Mailing\TransportUnavailable;
use CattoPHP\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;

/**
 * This site's connection to catto-mail, in four steps: catto-mail's address kept; Connect, an approval on catto-mail's
 * connect page (OAuth 2 authorisation code with PKCE, S256), whose code is exchanged for the key and the webhook secret,
 * saved encrypted in Settings > Mailing with the transport switched to catto-mail; the sending domain made ready there
 * (asked again every five minutes while it is not); and a real test email. Then Check the connection (a probe: 404 is a
 * key accepted), Disconnect (the key revoked at catto-mail) and Forget it here. What is known of it besides the secrets
 * (catto-mail's names for it, the sending domains, the last check and problem, a quota used up until when, the test) is
 * kept in cattophp_cattomail_connection. News waits while the domain cannot send or the quota is used up.
 */
final class CattoMailConnection
{
    public const TABLE = 'cattophp_cattomail_connection';
    private const VERIFIER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    /** How often a waiting site asks again whether the sending domain is ready, in seconds. */
    private const RECHECK = 300;

    public function __construct(
        private readonly MailingSettings $settings,
        private readonly CattoMailClient $client,
        private readonly Connection $connection,
        private readonly Settings $siteSettings,
        private readonly Installation $installation,
        private readonly ClockInterface $clock,
        private readonly string $project,
        private readonly string $callbackPath,
        private readonly string $webhookPath,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /** @return array<string,mixed> what is known of the connection */
    public function state(): array
    {
        $saved = $this->connection->fetchOne('SELECT state FROM ' . self::TABLE . ' WHERE id = 1');
        $state = is_string($saved) ? json_decode($saved, true) : null;

        return (is_array($state) ? $state : []) + ['connection_id' => '', 'client_name' => '', 'application_name' => '', 'endpoint_id' => '', 'webhook_url' => '',
            'connected_at' => null, 'connected_by' => '', 'domains' => [], 'domains_checked_at' => null, 'last_check_at' => null, 'last_check_ok' => null,
            'last_problem' => '', 'last_problem_at' => null, 'quota_until' => null, 'test_sent_at' => null, 'test_sent_to' => ''];
    }

    public function connected(): bool
    {
        return $this->client->configured();
    }

    /** The domain news comes from: the part after @ of the sender address. */
    public function sendingDomain(): string
    {
        return mb_strtolower((string) substr((string) strrchr($this->fromAddress(), '@'), 1));
    }

    /** @return array<string,mixed>|null catto-mail's last word on the sending domain */
    public function domain(): ?array
    {
        foreach ((array) $this->state()['domains'] as $domain) {
            if (is_array($domain) && mb_strtolower(self::text($domain['domain'] ?? '')) === $this->sendingDomain()) {
                /** @var array<string,mixed> $domain */
                return $domain;
            }
        }

        return null;
    }

    public function ready(): bool
    {
        return $this->connected() && (bool) ($this->domain()['can_send'] ?? false);
    }

    /** @return array{address:bool,connected:bool,domain:bool,test:bool,done:int} the four steps, and how many are done */
    public function steps(): array
    {
        $steps = ['address' => $this->client->host() !== '', 'connected' => $this->connected(), 'domain' => $this->ready(), 'test' => $this->ready() && $this->state()['test_sent_at'] !== null];

        return $steps + ['done' => count(array_filter($steps))];
    }

    public function saveAddress(string $address, string $actor = ''): void
    {
        $address = rtrim(trim($address), '/');
        if (preg_match('#^https://[a-z0-9.-]+(:[0-9]+)?(/v1)?$#i', $address) !== 1) throw new InvalidArgumentException('Write catto-mail\'s address with https://, such as https://mail.example.test.');
        $this->settings->save(['cattomail_url' => preg_replace('#/v1$#', '', $address) ?? $address], $actor);
        $this->record('mailing.cattomail_address_saved', [], $actor);
    }

    /**
     * Step 2's start: the address of catto-mail's connect page, with the state and code verifier to keep in the
     * administrator's session until they come back.
     *
     * @return array{url:string,state:string,verifier:string,redirect_uri:string}
     */
    public function begin(): array
    {
        $host = $this->client->host();
        if ($host === '') throw new InvalidArgumentException('Keep catto-mail\'s address first.');
        $verifier = '';
        for ($i = 0; $i < 64; $i++) $verifier .= self::VERIFIER[random_int(0, strlen(self::VERIFIER) - 1)];
        $state = bin2hex(random_bytes(24));
        $redirect = $this->installation->addressOf($this->callbackPath);
        $query = ['application_name' => mb_substr($this->installation->name(), 0, 100), 'redirect_uri' => $redirect, 'webhook_url' => $this->installation->addressOf($this->webhookPath), 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        if ($this->sendingDomain() !== '') $query['sending_domain'] = $this->sendingDomain();

        return ['url' => $host . '/dashboard/connect?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'state' => $state, 'verifier' => $verifier, 'redirect_uri' => $redirect];
    }

    /** Step 2's end: the code exchanged for the key, the webhook secret and the domains; news goes through catto-mail. */
    public function finish(string $code, string $verifier, string $redirectUri, string $actor, string $actorName): void
    {
        if (trim($code) === '') throw new InvalidArgumentException('catto-mail sent no approval back. Press Connect to try again.');
        $answer = $this->client->exchange($this->client->host(), $code, $verifier, $redirectUri);
        $webhook = is_array($answer['webhook'] ?? null) ? $answer['webhook'] : [];
        $base = self::text($answer['api_base_url'] ?? '');
        $fields = ['cattomail_key' => self::text($answer['api_key'] ?? ''), 'cattomail_secret' => self::text($webhook['signing_secret'] ?? ''), 'transport' => 'cattomail'];
        if (preg_match('#^https://#', $base) === 1) $fields['cattomail_url'] = preg_replace('#/v1/?$#', '', $base) ?? $base;
        $this->settings->save($fields, $actor);
        $now = $this->now();
        $this->save(['connection_id' => self::text($answer['connection_id'] ?? ''), 'client_name' => self::text($answer['client_name'] ?? ''), 'application_name' => self::text($answer['application_name'] ?? ''),
            'endpoint_id' => self::text($webhook['endpoint_id'] ?? ''), 'webhook_url' => self::text($webhook['url'] ?? ''), 'connected_at' => $now, 'connected_by' => $actorName,
            'domains' => array_values(array_filter((array) ($answer['sending_domains'] ?? []), 'is_array')), 'domains_checked_at' => $now, 'last_check_at' => $now, 'last_check_ok' => true,
            'last_problem' => '', 'quota_until' => null, 'test_sent_at' => null, 'test_sent_to' => '']);
        $this->record('mailing.cattomail_connected', ['connection_id' => self::text($answer['connection_id'] ?? '')], $actor);
    }

    /** Asks catto-mail about the sending domains now. */
    public function checkDomains(): void
    {
        try {
            $this->save(['domains' => $this->client->sendingDomains(), 'domains_checked_at' => $this->now()]);
        } catch (TransportRefused | TransportUnavailable | QuotaExceeded $problem) {
            $this->problem($problem->getMessage());
        }
    }

    /** While connected but the domain is not ready, asks again every five minutes. True when it asked. */
    public function recheckIfWaiting(): bool
    {
        if (!$this->connected() || $this->ready()) return false;
        $last = $this->state()['domains_checked_at'];
        if (is_string($last) && strtotime($last) > $this->clock->now()->getTimestamp() - self::RECHECK) return false;
        $this->checkDomains();

        return true;
    }

    /** Checks the connection: reachable, and the key accepted. True when both. */
    public function check(): bool
    {
        $status = $this->client->probe();
        $ok = $status === 404;
        $problem = match (true) {
            $ok => '',
            $status === 0 => 'catto-mail could not be reached.',
            $status === 401 => 'catto-mail does not accept the key: the connection was ended at catto-mail. Connect again.',
            default => 'catto-mail answered ' . $status . ' to the check.',
        };
        $this->save(['last_check_at' => $this->now(), 'last_check_ok' => $ok] + ($ok ? [] : ['last_problem' => $problem, 'last_problem_at' => $this->now()]));
        if ($ok) $this->checkDomains();

        return $ok;
    }

    /** Ends the connection at catto-mail and forgets it here. */
    public function disconnect(string $actor = ''): void
    {
        $this->client->disconnect();
        $this->forget($actor);
        $this->record('mailing.cattomail_disconnected', [], $actor);
    }

    /** Forgets the key, the webhook secrets and what is known of the connection, here only. */
    public function forget(string $actor = ''): void
    {
        $this->settings->forget(['cattomail_key', 'cattomail_secret', 'cattomail_secret_previous']);
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE id = 1');
        $this->record('mailing.cattomail_forgotten', [], $actor);
    }

    /** Step 4: a real email through catto-mail to an address of the administrator's. */
    public function test(string $email, string $actor = ''): void
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Write the address to send the test to.');
        if (!$this->ready()) throw new InvalidArgumentException('catto-mail cannot send from ' . $this->sendingDomain() . ' yet. Make the sending domain ready first.');
        $from = $this->fromAddress();
        $name = $this->installation->name();
        $reference = $this->project . '-test-' . bin2hex(random_bytes(6));
        $siteName = trim($this->siteSettings->get('MAIL_FROM_NAME'));
        $job = $this->client->createJob(['external_reference' => $reference, 'message_class' => 'transactional',
            'sender_identity' => ['email' => $from, 'name' => $this->settings->fromName($siteName !== '' ? $siteName : $name)], 'tracking' => ['opens' => false, 'clicks' => false]], $reference . '-job');
        $jobId = self::text($job['id'] ?? '');
        if ($jobId === '') throw new TransportRefused('catto-mail made no send job.');
        $words = 'This email came through catto-mail from ' . $from . '. ' . $name . ' is connected, and its news can go out.';
        $this->client->addRecipients($jobId, [['external_recipient_reference' => $reference, 'email_address' => $email, 'subject' => 'catto-mail is connected to ' . $name,
            'html_body' => '<p>' . htmlspecialchars($words, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>', 'text_body' => $words]], $reference . '-r0');
        $this->client->submit($jobId);
        $this->save(['test_sent_at' => $this->now(), 'test_sent_to' => $email]);
        $this->record('mailing.cattomail_test_sent', [], $actor);
    }

    /** catto-mail's quota is used up until a moment (an hour from now when it did not say): nothing is asked before it. */
    public function quotaUsedUntil(?DateTimeImmutable $resetsAt, string $words): void
    {
        $until = $resetsAt ?? DateTimeImmutable::createFromInterface($this->clock->now())->modify('+1 hour');
        $this->save(['quota_until' => $until->format(DATE_ATOM)]);
        $this->problem($words);
    }

    /** Until when catto-mail's quota is used up, in the site's time; null when it is not. */
    public function quotaUntil(): ?DateTimeImmutable
    {
        $until = $this->state()['quota_until'];
        if (!is_string($until) || strtotime($until) === false) return null;
        $at = new DateTimeImmutable($until);

        return $at > DateTimeImmutable::createFromInterface($this->clock->now()) ? $at->setTimezone(new DateTimeZone(date_default_timezone_get())) : null;
    }

    public function problem(string $words): void
    {
        $this->save(['last_problem' => mb_substr($words, 0, 500), 'last_problem_at' => $this->now()]);
    }

    private function fromAddress(): string
    {
        return $this->settings->fromAddress(trim($this->siteSettings->get('MAIL_FROM_ADDRESS')));
    }

    /** @param array<string,mixed> $fields */
    private function save(array $fields): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (id, state, updated_at) VALUES (1, CAST(:state AS JSONB), NOW()) ON CONFLICT (id) DO UPDATE SET state = EXCLUDED.state, updated_at = NOW()',
            ['state' => json_encode(array_replace($this->state(), $fields), JSON_THROW_ON_ERROR)]
        );
    }

    /** @param array<string,mixed> $details */
    private function record(string $action, array $details, string $actor): void
    {
        $this->activity?->record($action, $details, actor: $actor !== '' && $actor !== '0' ? new Actor($actor, $actor) : Actor::system());
    }

    private function now(): string
    {
        return DateTimeImmutable::createFromInterface($this->clock->now())->format(DATE_ATOM);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
