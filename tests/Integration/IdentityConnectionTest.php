<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Identity\DiditClient;
use CattoPHP\Identity\IdentityConnection;
use CattoPHP\Identity\IdentitySettingGroup;
use CattoPHP\Identity\IdentitySettings;
use CattoPHP\Settings\DbalConnectionStore;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\ServiceConnections;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The connection to Didit as the Identity verification section controls it:
 * Didit's keys in Settings over .env, Check the connection by a pure read of the workflows, the webhook registered from
 * the page, Drop connection forgetting the keys even where .env holds some, and Reset to .env.
 */
final class IdentityConnectionTest extends DatabaseTestCase
{
    private Foundations $site;
    private IdentitySettings $identity;
    private IdentityConnection $didit;
    /** Didit's answer to the next request: 'accept', 'refuse' or 'no-workflow'. */
    private string $answer = 'accept';
    /** @var list<array{method:string,url:string,body:string,key:string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations(['DIDIT_API_KEY' => 'sk_test_from_env', 'DIDIT_WORKFLOW_ID' => 'wf-from-env', 'DIDIT_WEBHOOK_SECRET' => 'whsec_from_env'], settingsProviders: [new IdentitySettingGroup()]);
        $this->identity = new IdentitySettings(new EnvironmentValues([]), $this->site->siteMode, $this->site->installation, '/identity/return', '/webhooks/didit', $this->site->settings);
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) $headers[] = (string) $line;
            $key = '';
            foreach ($headers as $line) if (stripos($line, 'x-api-key:') === 0) $key = trim(substr($line, 10));
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? ''), 'key' => $key];
            if ($this->answer === 'refuse') return new MockResponse('{"detail":"Authentication credentials were not provided or are invalid."}', ['http_code' => 401]);
            if (str_ends_with($url, '/v3/webhook/destinations/')) return new MockResponse((string) json_encode(['uuid' => 'd-1', 'secret_shared_key' => 'whsec_made_by_didit']), ['http_code' => 201]);
            $workflows = [['uuid' => 'v-1', 'workflow_id' => 'wf-other', 'workflow_label' => 'Other', 'status' => 'published', 'is_default' => false]];
            if ($this->answer === 'accept') $workflows[] = ['uuid' => 'v-2', 'workflow_id' => $this->identity->workflowId(), 'workflow_label' => 'ID and selfie', 'status' => 'published', 'is_default' => true];

            return new MockResponse((string) json_encode($workflows), ['http_code' => 200]);
        });
        $this->didit = new IdentityConnection(new ServiceConnections(new DbalConnectionStore($this->connection), $this->site->keyedHash()), $this->site->settings, $this->identity, new DiditClient($http, $this->identity));
    }

    public function testDiditIsConnectedCheckedAndDroppedFromTheSettings(): void
    {
        $owner = new Actor('7', 'Owner');
        self::assertSame(['sk_test_from_env', 'wf-from-env'], [$this->identity->apiKey(), $this->identity->workflowId()], '.env gives the starting values.');
        $this->site->settings->set('DIDIT_API_KEY', 'sk_test_saved_here', $owner);
        $this->site->settings->set('DIDIT_WORKFLOW_ID', 'wf-saved-here', $owner);
        self::assertSame(['sk_test_saved_here', 'wf-saved-here'], [$this->identity->apiKey(), $this->identity->workflowId()], 'Settings override .env.');
        self::assertSame('unchecked', $this->didit->status()['state'], 'Keys never checked: not connected.');

        $this->answer = 'refuse';
        try {
            $this->didit->check();
            self::fail('A refused key is an error.');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString('Didit did not accept the API key (401)', $refused->getMessage());
        }
        self::assertSame('failed', $this->didit->status()['state']);
        self::assertSame(['GET', 'https://verification.didit.me/v3/workflows/', 'sk_test_saved_here'], [$this->requests[0]['method'], $this->requests[0]['url'], $this->requests[0]['key']], 'A pure read, with the saved key.');
        try {
            $this->didit->registerWebhook($owner);
            self::fail('No webhook before a connection.');
        } catch (RuntimeException $first) {
            self::assertStringContainsString('Connect to Didit first', $first->getMessage());
        }

        $this->answer = 'no-workflow';
        try {
            $this->didit->check();
            self::fail('An unknown workflow is an error.');
        } catch (RuntimeException $unknown) {
            self::assertStringContainsString('the workflow ID is not one of this application\'s workflows', $unknown->getMessage());
        }

        $this->answer = 'accept';
        self::assertSame('Connected to Didit\'s sandbox: the workflow is "ID and selfie".', $this->didit->check());
        $status = $this->didit->status();
        self::assertSame(['connected', '', 'ID and selfie'], [$status['state'], $status['error'], $status['workflow_label']]);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_connections WHERE name = 'didit'"), 'Kept among the connections, as didit.');
        $before = $this->didit->keys();
        $this->didit->keysSaved($before);
        self::assertSame('connected', $this->didit->status()['state'], 'Saved unchanged: still connected.');

        $this->didit->registerWebhook($owner);
        $webhook = $this->requests[count($this->requests) - 1];
        self::assertSame('https://verification.didit.me/v3/webhook/destinations/', $webhook['url']);
        self::assertSame(['label' => 'This site', 'url' => 'https://example.com/webhooks/didit', 'enabled' => true, 'webhook_version' => 'v3'], json_decode($webhook['body'], true));
        self::assertSame('whsec_made_by_didit', $this->identity->webhookSecret(), 'The secret Didit gives is kept.');
        self::assertTrue($this->site->settings->isOverridden('DIDIT_WEBHOOK_SECRET'));

        $this->site->settings->set('DIDIT_WORKFLOW_ID', 'wf-another', $owner);
        self::assertSame('unchecked', $this->didit->status()['state'], 'A key changed after the check, without keysSaved: not checked yet.');
        $this->site->settings->set('DIDIT_WORKFLOW_ID', 'wf-saved-here', $owner);
        self::assertSame('connected', $this->didit->status()['state'], 'The keys it was checked with again: connected.');
        $before = $this->didit->keys();
        $this->site->settings->set('DIDIT_WORKFLOW_ID', 'wf-another', $owner);
        $this->didit->keysSaved($before);
        self::assertSame('unchecked', $this->didit->status()['state'], 'New keys: not checked yet.');

        $this->didit->drop($owner);
        self::assertSame(['', '', ''], [$this->identity->apiKey(), $this->identity->workflowId(), $this->identity->webhookSecret()], 'Dropped: forgotten, though .env holds keys.');
        self::assertSame(['none', false], [$this->didit->status()['state'], $this->identity->available()]);
        self::assertSame(IdentitySettings::KEYS, $this->identity->missingKeys());

        $this->didit->resetToEnv($owner);
        self::assertSame(['sk_test_from_env', 'wf-from-env', 'whsec_from_env'], array_values($this->didit->keys()), 'Reset to .env: .env applies again.');
        self::assertSame('unchecked', $this->didit->status()['state']);
    }
}
