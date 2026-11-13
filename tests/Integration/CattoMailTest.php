<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Mailing\CattoMail\CattoMailClient;
use CattoPHP\Mailing\CattoMail\CattoMailConnection;
use CattoPHP\Mailing\CattoMail\CattoMailEventHandler;
use CattoPHP\Mailing\CattoMail\CattoMailEvents;
use CattoPHP\Mailing\CattoMail\CattoMailTransport;
use CattoPHP\Mailing\CattoMail\CattoMailWebhook;
use CattoPHP\Mailing\MailingSettingGroup;
use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Mailing\OutgoingCopy;
use CattoPHP\Mailing\SenderIdentity;
use CattoPHP\Mailing\TransportRefused;
use CattoPHP\Mailing\TransportUnavailable;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Webhooks\WebhookSignature;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** catto-mail's connection, its send jobs and its webhook, against a stand-in of its API (ported from cattolms's checks). */
final class CattoMailTest extends DatabaseTestCase
{
    private Foundations $site;
    private MemorySettingsStore $store;
    private MailingSettings $settings;
    /** @var list<array{method:string,url:string,body:mixed,auth:string,headers:array<string,mixed>}> */
    private array $requests = [];
    /** @var list<MockResponse> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new MemorySettingsStore();
        $this->site = new Foundations(['MAIL_FROM_ADDRESS' => 'site@example.com'], $this->store, [new MailingSettingGroup()]);
        $this->settings = new MailingSettings($this->site->settings);
    }

    public function testConnectingKeepsTheKeyAndSecretAndNewsWaitsUntilTheDomainCanSendAndTheQuotaResets(): void
    {
        $this->settings->save(['from_address' => 'news@news.example.test']);
        $this->site->clock->modify('2026-11-02 10:00:00');
        [$connection, $transport] = $this->connection();

        $connection->saveAddress('https://mail.example.test/v1', '1');
        $start = $connection->begin();
        parse_str((string) parse_url($start['url'], PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://mail.example.test/dashboard/connect?', $start['url']);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $start['verifier'], true)), '+/', '-_'), '='), $query['code_challenge'], 'S256 of the verifier.');
        self::assertSame(['S256', $start['state'], 'news.example.test', 'Example Site'], [$query['code_challenge_method'], $query['state'], $query['sending_domain'], $query['application_name']]);
        self::assertSame('https://example.com/mailing/cattomail/callback', $query['redirect_uri']);
        self::assertSame('https://example.com/webhooks/cattomail', $query['webhook_url']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._~-]{43,128}$/', $start['verifier']);

        $this->answers[] = new MockResponse((string) json_encode(['connection_id' => 'conn-1', 'application_name' => 'Example', 'client_name' => 'Example Learning',
            'api_base_url' => 'https://mail.example.test/v1', 'api_key' => 'shk_secretkey', 'webhook' => ['endpoint_id' => 'ep-1', 'url' => 'https://example.com/webhooks/cattomail', 'signing_secret' => 'whsec_secret'],
            'sending_domains' => [['id' => 'd-1', 'domain' => 'news.example.test', 'status' => 'verified', 'dkim_status' => 'pending_dns', 'can_send' => false]]]), ['http_code' => 200]);
        $connection->finish('the-code', $start['verifier'], $start['redirect_uri'], '1', 'Thandi');
        self::assertSame(['code' => 'the-code', 'code_verifier' => $start['verifier'], 'redirect_uri' => $start['redirect_uri']], $this->requests[0]['body']);
        self::assertSame('', $this->requests[0]['auth'], 'The exchange carries no key.');
        self::assertSame('shk_secretkey', $this->settings->value('cattomail_key'));
        self::assertNotSame('shk_secretkey', $this->store->all()['CATTOMAIL_API_KEY']->value, 'Kept encrypted.');
        self::assertSame('whsec_secret', $this->settings->value('cattomail_secret'));
        self::assertSame('cattomail', $this->settings->transport(), 'News goes through catto-mail once connected.');
        self::assertSame(['Example Learning', 'Thandi'], [$connection->state()['client_name'], $connection->state()['connected_by']]);
        self::assertFalse($connection->ready());
        self::assertSame(2, $connection->steps()['done']);
        self::assertSame(['mailing.cattomail_address_saved', 'mailing.cattomail_connected'], $this->actions());

        $copy = $this->copy(1, str_repeat('a', 32), '1');
        $sender = new SenderIdentity('news@news.example.test', 'News');
        try {
            $transport->send('1', 'message', $sender, [$copy]);
            self::fail('Sent before the domain could send.');
        } catch (TransportUnavailable $waits) {
            self::assertStringContainsString('cannot send from news.example.test yet', $waits->getMessage());
        }
        self::assertCount(1, $this->requests, 'Nothing was asked of catto-mail.');

        $this->answers[] = new MockResponse((string) json_encode(['data' => [['id' => 'd-1', 'domain' => 'news.example.test', 'status' => 'verified', 'dkim_status' => 'active', 'can_send' => true]]]), ['http_code' => 200]);
        self::assertFalse($connection->recheckIfWaiting(), 'Asked on connecting: not again within five minutes.');
        $this->site->clock->modify('+6 minutes');
        self::assertTrue($connection->recheckIfWaiting(), 'Asked again: it had not been asked in five minutes.');
        self::assertTrue($connection->ready());
        self::assertFalse($connection->recheckIfWaiting(), 'Ready: nothing to ask.');
        self::assertSame('Bearer shk_secretkey', $this->requests[1]['auth']);

        $this->answers[] = new MockResponse((string) json_encode(['type' => 'https://mail.example.test/problems/quota-exceeded', 'title' => 'Quota exceeded', 'status' => 429, 'detail' => 'The daily quota is used up.',
            'quota' => ['metric' => 'send_recipients', 'period' => 'day', 'resets_at' => '2026-11-03T00:00:00.000000+00:00']]), ['http_code' => 429]);
        try {
            $transport->send('1', 'message', $sender, [$copy]);
            self::fail('A quota refusal was not a wait.');
        } catch (TransportUnavailable $waits) {
            $local = (new \DateTimeImmutable('2026-11-03T00:00:00+00:00'))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('j F \\a\\t H:i');
            self::assertStringContainsString('quota is used up until ' . $local . '.', $waits->getMessage(), 'In the site\'s time.');
        }
        $asked = count($this->requests);
        try {
            $transport->send('1', 'message', $sender, [$copy]);
            self::fail('Sent before the quota reset.');
        } catch (TransportUnavailable) {
            self::assertCount($asked, $this->requests, 'Nothing is asked before the quota resets.');
        }
        $this->site->clock->modify('+14 hours');
        self::assertNull($connection->quotaUntil(), 'Once it resets, news goes on.');

        $this->answers[] = new MockResponse('', ['http_code' => 404]);
        $this->answers[] = new MockResponse((string) json_encode(['data' => [['id' => 'd-1', 'domain' => 'news.example.test', 'status' => 'verified', 'dkim_status' => 'active', 'can_send' => true]]]), ['http_code' => 200]);
        self::assertTrue($connection->check(), 'A job that cannot exist answers 404 to a key catto-mail accepts.');
        $this->answers[] = new MockResponse('', ['http_code' => 401]);
        self::assertFalse($connection->check());
        self::assertStringContainsString('does not accept the key', (string) $connection->state()['last_problem']);

        $this->answers[] = new MockResponse('', ['http_code' => 204]);
        $connection->dropConnection('1');
        self::assertSame('DELETE', $this->requests[count($this->requests) - 1]['method']);
        self::assertStringEndsWith('/v1/connection', $this->requests[count($this->requests) - 1]['url']);
        self::assertFalse($connection->connected(), 'Forgotten here.');
        self::assertSame('', $connection->state()['client_name']);
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_cattomail_connection'));
    }

    public function testAnApprovalThatCannotBeUsedSaysSoAndChangesNothing(): void
    {
        [$connection] = $this->connection();
        $connection->saveAddress('https://mail.example.test', '1');
        $this->answers[] = new MockResponse((string) json_encode(['type' => 'https://mail.example.test/problems/invalid-connect-code', 'title' => 'Invalid connect code', 'status' => 400]), ['http_code' => 400]);
        try {
            $connection->finish('used-code', str_repeat('v', 43), 'https://example.com/mailing/cattomail/callback', '1', 'Thandi');
            self::fail('Connected with a used code.');
        } catch (TransportRefused $refused) {
            self::assertStringStartsWith('That approval cannot be used', $refused->getMessage());
            self::assertSame([400, 'invalid-connect-code'], [$refused->status, $refused->problem]);
        }
        self::assertFalse($connection->connected());
        self::assertSame('smtp', $this->settings->transport());
    }

    public function testCattoMailIsGivenOneJobPerListWithEachCopysReferenceAndItsOwnTrackingOff(): void
    {
        $this->settings->save(['cattomail_url' => 'https://mail.example.test', 'cattomail_key' => 'shk_test']);
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'body' => json_decode((string) ($options['body'] ?? 'null'), true), 'headers' => $options['normalized_headers'] ?? []];
            if (str_ends_with($url, '/send-jobs')) return new MockResponse((string) json_encode(['id' => 'job-' . count($requests)]), ['http_code' => 201]);
            if (str_contains($url, '/messages')) return new MockResponse((string) json_encode(['data' => [['id' => 'm1', 'external_recipient_reference' => str_repeat('a', 32), 'current_status' => 'remote_accepted'], ['id' => 'm2', 'external_recipient_reference' => str_repeat('b', 32), 'current_status' => 'queued']], 'pagination' => ['next_cursor' => null]]), ['http_code' => 200]);

            return new MockResponse((string) json_encode(['ok' => true]), ['http_code' => str_ends_with($url, '/submit') ? 202 : 201]);
        });
        $transport = new CattoMailTransport(new CattoMailClient($http, $this->settings), 'example');
        $result = $transport->send('5', 'message', new SenderIdentity('news@news.example.test', 'Site', 'help@example.test'), [$this->copy(1, str_repeat('a', 32), '7'), $this->copy(2, str_repeat('b', 32), '7'), $this->copy(3, str_repeat('c', 32), '8')]);
        self::assertSame([1 => 'job-1', 2 => 'job-1', 3 => 'job-4'], array_map(static fn(array $handed): ?string => $handed['job_id'], $result->handed), 'One job for each list.');
        self::assertSame(['https://mail.example.test/v1/send-jobs', 'https://mail.example.test/v1/send-jobs/job-1/recipients', 'https://mail.example.test/v1/send-jobs/job-1/submit', 'https://mail.example.test/v1/send-jobs'], array_slice(array_column($requests, 'url'), 0, 4));
        $job = $requests[0]['body'];
        self::assertSame('subscription', $job['message_class']);
        self::assertSame(['opens' => false, 'clicks' => false], $job['tracking'], 'The copies carry the site\'s own tracking.');
        self::assertSame('News and Updates <list-7.news.example.test>', $job['list_id']);
        self::assertSame(['email' => 'help@example.test'], $job['reply_to']);
        self::assertStringStartsWith('example-c5-l7-', $job['external_reference']);
        self::assertSame([str_repeat('a', 32), str_repeat('b', 32)], array_column($requests[1]['body']['recipients'], 'external_recipient_reference'));
        self::assertSame('https://news.example.test/m/u/' . str_repeat('a', 32), $requests[1]['body']['recipients'][0]['unsubscribe_url']);
        self::assertSame(['Idempotency-Key: ' . $job['external_reference'] . '-job'], $requests[0]['headers']['idempotency-key'] ?? null, 'The same copies again make the same request, which catto-mail does once.');
        self::assertSame(['Authorization: Bearer shk_test'], $requests[0]['headers']['authorization'] ?? null);
        self::assertSame([['reference' => str_repeat('a', 32), 'message_id' => 'm1', 'status' => 'delivered'], ['reference' => str_repeat('b', 32), 'message_id' => 'm2', 'status' => null]], $transport->outcomes('job-1'));

        $refusing = new CattoMailTransport(new CattoMailClient(new MockHttpClient(new MockResponse((string) json_encode(['type' => 'https://errors.example/submit-rejected', 'title' => 'Submit rejected', 'detail' => 'The sending domain has no active DKIM key.',
            'errors' => [['pointer' => '/sender_identity', 'message' => 'is not verified']]]), ['http_code' => 422])), $this->settings), 'example');
        try {
            $refusing->send('5', 'message', new SenderIdentity('news@news.example.test', 'Site'), [$this->copy(1, str_repeat('a', 32), '7')]);
            self::fail('A refusal went unsaid.');
        } catch (TransportRefused $refused) {
            self::assertSame('catto-mail answered 422: The sending domain has no active DKIM key. /sender_identity is not verified', $refused->getMessage());
            self::assertSame([422, 'submit-rejected'], [$refused->status, $refused->problem]);
        }
    }

    public function testTheWebhookKeepsEachSignedEventOnceAndItsHandlersSayWhatTheyDid(): void
    {
        $handled = [];
        $handler = new class ($handled) implements CattoMailEventHandler {
            /** @param list<string> $handled */
            public function __construct(public array $handled)
            {
            }

            public function types(): array
            {
                return ['message.hard_bounced'];
            }

            public function handle(string $type, array $event): string
            {
                $data = is_array($event['data'] ?? null) ? $event['data'] : [];
                $message = is_array($data['message'] ?? null) ? $data['message'] : [];
                $this->handled[] = $type . ' ' . (is_string($message['external_recipient_reference'] ?? null) ? $message['external_recipient_reference'] : '');

                return 'Recorded: a hard bounce.';
            }
        };
        $events = new CattoMailEvents($this->connection, [$handler]);
        $webhook = new CattoMailWebhook($this->settings, $events, $this->site->clock);
        $body = (string) json_encode(['id' => 'evt-1', 'type' => 'message.hard_bounced', 'data' => ['message' => ['id' => 'msg-1', 'external_recipient_reference' => 'tok-1']]]);
        $now = $this->site->clock->now()->getTimestamp();

        self::assertSame([503, ['error' => 'not configured']], $webhook->answer(WebhookSignature::sign($body, ['whsec_new'], $now), $body), 'Nothing is taken before there is a secret.');
        $this->settings->save(['cattomail_secret' => 'whsec_new', 'cattomail_secret_previous' => 'whsec_old']);
        self::assertSame(401, $webhook->answer(WebhookSignature::sign($body, ['whsec_other'], $now), $body)[0]);
        self::assertSame(401, $webhook->answer(WebhookSignature::sign($body, ['whsec_new'], $now - 3600), $body)[0], 'An old signature is refused.');
        self::assertSame(413, $webhook->answer('', str_repeat('x', 1048577))[0]);
        $notEvent = (string) json_encode(['type' => 'message.hard_bounced']);
        self::assertSame(400, $webhook->answer(WebhookSignature::sign($notEvent, ['whsec_new'], $now), $notEvent)[0]);
        self::assertSame([200, ['ok' => true]], $webhook->answer(WebhookSignature::sign($body, ['whsec_old'], $now), $body), 'The previous secret still signs while catto-mail changes over.');
        self::assertSame(200, $webhook->answer(WebhookSignature::sign($body, ['whsec_new'], $now), $body)[0], 'A repeat is answered.');
        self::assertSame(['message.hard_bounced tok-1'], $handler->handled, 'And done once.');
        $other = (string) json_encode(['id' => 'evt-2', 'type' => 'send.completed', 'data' => ['id' => 'job-9']]);
        self::assertSame(200, $webhook->answer(WebhookSignature::sign($other, ['whsec_new'], $now), $other)[0]);

        $recent = $events->recent();
        self::assertSame(['evt-2', 'evt-1'], array_column($recent, 'event_id'));
        self::assertSame(['Kept; nothing to do.', 'Recorded: a hard bounce.'], array_column($recent, 'outcome'));
        self::assertSame([null, 'tok-1'], array_column($recent, 'reference'));
        self::assertSame(['job-9', null], array_column($recent, 'job_id'));
        self::assertCount(1, $events->recent('send.completed'));
        self::assertSame(0, $events->waiting());
        self::assertTrue($events->retry((int) $recent[1]['id']));
        self::assertFalse($events->retry(999999));
        self::assertSame(1, $events->waiting());
        self::assertSame(1, $events->process());
        self::assertSame(['message.hard_bounced tok-1', 'message.hard_bounced tok-1'], $handler->handled, 'Taken again when asked.');
    }

    private function copy(int $id, string $token, string $list): OutgoingCopy
    {
        return new OutgoingCopy($id, $token, $token . '@example.test', 'Hi', '<p>Hi</p>', 'Hi', 'https://news.example.test/m/u/' . $token, $list, 'News and Updates');
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_values(array_filter(array_map(static fn($entry): string => $entry->action, array_reverse($this->site->activity->recent())), static fn(string $action): bool => str_starts_with($action, 'mailing.')));
    }

    /** @return array{0:CattoMailConnection,1:CattoMailTransport} */
    private function connection(): array
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $auth = '';
            foreach ((array) ($options['normalized_headers']['authorization'] ?? []) as $header) $auth = trim(substr((string) $header, strlen('Authorization:')));
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => json_decode((string) ($options['body'] ?? 'null'), true), 'auth' => $auth, 'headers' => []];

            return array_shift($this->answers) ?? new MockResponse('', ['http_code' => 500]);
        });
        $client = new CattoMailClient($http, $this->settings);
        $connection = new CattoMailConnection($this->settings, $client, $this->connection, $this->site->settings, $this->site->installation, $this->site->clock, 'example',
            '/mailing/cattomail/callback', '/webhooks/cattomail', $this->site->activity);

        return [$connection, new CattoMailTransport($client, 'example', $connection)];
    }
}
