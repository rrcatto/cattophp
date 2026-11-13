<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Payments\GatewayConnections;
use CattoPHP\Payments\GatewayDefinitions;
use CattoPHP\Payments\PaymentGateways;
use CattoPHP\Payments\PaymentSettings;
use CattoPHP\Settings\DbalConnectionStore;
use CattoPHP\Settings\ServiceConnections;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\ExampleGateway;
use CattoPHP\Tests\Support\Foundations;
use RuntimeException;

/**
 * A payment gateway's connection as a settings page controls it, the way a card payments section uses it: the
 * keys in Settings over .env; connected only once Check the connection asked the provider and it
 * accepted them, the outcome kept for the keys it was checked with; Drop connection forgetting every key, even where .env
 * holds some; Reset to .env. The provider here is a stand-in question, as the project asks its own provider.
 */
final class GatewayConnectionsTest extends DatabaseTestCase
{
    private const CONFIG = [
        'example' => [
            'omnipay' => '\\' . ExampleGateway::class,
            'label' => 'Card payments (Example)',
            'parameters' => [
                'apiKey' => ['name' => 'EXAMPLE_API_KEY', 'label' => 'Client ID'],
                'apiSecret' => ['name' => 'EXAMPLE_API_SECRET', 'label' => 'Client secret', 'secret' => true],
                'webhookSecret' => ['name' => 'EXAMPLE_WEBHOOK_SECRET', 'label' => 'Webhook signing secret', 'secret' => true, 'optional' => true],
            ],
        ],
    ];

    private Foundations $site;
    private PaymentGateways $gateways;
    private GatewayConnections $connections;
    /** @var list<array<string,string>> the keys the provider was asked with */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations(['EXAMPLE_API_KEY' => 'test-from-env', 'EXAMPLE_API_SECRET' => 'secret-from-env', 'EXAMPLE_WEBHOOK_SECRET' => 'whsec_from_env'], settingsProviders: [new PaymentSettings(new GatewayDefinitions(self::CONFIG))]);
        $this->gateways = new PaymentGateways(new GatewayDefinitions(self::CONFIG), $this->site->settings);
        $this->connections = new GatewayConnections($this->gateways, $this->site->settings, new ServiceConnections(new DbalConnectionStore($this->connection), $this->site->keyedHash()));
    }

    /** The provider's answer: null when it accepts the keys it is asked with, else its words. */
    private function provider(?string $answer): callable
    {
        return function () use ($answer): ?string {
            $this->asked[] = $this->gateways->parameters('example');

            return $answer;
        };
    }

    public function testAGatewayIsConnectedCheckedAndDroppedFromTheSettings(): void
    {
        $owner = new Actor('7', 'Owner');
        self::assertSame('unchecked', $this->connections->status('example')['state'], 'Keys from .env, never checked: not connected.');
        self::assertFalse($this->connections->connected('example'));

        $this->site->settings->save('payment_example', ['EXAMPLE_API_KEY' => 'test-saved-here', 'EXAMPLE_API_SECRET' => 'secret-saved-here'], $owner);
        try {
            $this->connections->check('example', $this->provider('The provider did not accept the client ID and secret (401).'));
            self::fail('A refused key is an error.');
        } catch (RuntimeException $refused) {
            self::assertSame('The provider did not accept the client ID and secret (401).', $refused->getMessage());
        }
        $failed = $this->connections->status('example');
        self::assertSame(['failed', 'The provider did not accept the client ID and secret (401).'], [$failed['state'], $failed['error']], 'Could not connect, in the provider\'s words.');
        self::assertSame(['apiKey' => 'test-saved-here', 'apiSecret' => 'secret-saved-here', 'webhookSecret' => 'whsec_from_env'], $this->asked[0], 'Asked with the keys Settings holds.');

        try {
            $this->connections->check('example', static fn(): never => throw new RuntimeException('Could not reach the provider: timed out.'));
            self::fail('A provider that cannot be reached is an error.');
        } catch (RuntimeException $unreachable) {
            self::assertSame('Could not reach the provider: timed out.', $unreachable->getMessage());
        }
        self::assertSame('failed', $this->connections->status('example')['state']);

        $this->connections->check('example', $this->provider(null));
        self::assertSame(['connected', ''], [$this->connections->status('example')['state'], $this->connections->status('example')['error']]);
        self::assertTrue($this->connections->connected('example'));

        $this->site->settings->set('EXAMPLE_WEBHOOK_SECRET', 'whsec_registered', $owner);
        self::assertTrue($this->connections->connected('example'), 'The webhook\'s secret is not checked: saving it keeps the connection.');
        $this->site->settings->set('EXAMPLE_API_SECRET', 'another-secret', $owner);
        self::assertSame('unchecked', $this->connections->status('example')['state'], 'A key changed since the check: not checked yet, until Check the connection.');
        $this->connections->check('example', $this->provider(null));
        self::assertTrue($this->connections->connected('example'));

        $this->connections->drop('example', $owner);
        self::assertSame(['', '', ''], [$this->site->settings->get('EXAMPLE_API_KEY'), $this->site->settings->get('EXAMPLE_API_SECRET'), $this->site->settings->get('EXAMPLE_WEBHOOK_SECRET')], 'Dropped: forgotten, though .env holds keys.');
        self::assertSame('none', $this->connections->status('example')['state']);
        self::assertSame(['EXAMPLE_API_KEY', 'EXAMPLE_API_SECRET'], $this->gateways->missing('example'));
        try {
            $this->connections->check('example', $this->provider(null));
            self::fail('No keys, nothing to check.');
        } catch (RuntimeException $missing) {
            self::assertSame('Enter the client ID and the client secret first.', $missing->getMessage());
        }
        self::assertCount(3, $this->asked, 'The provider is never asked without the keys.');

        $this->connections->resetToEnv('example', $owner);
        self::assertSame(['test-from-env', 'secret-from-env', 'whsec_from_env'], array_values($this->gateways->parameters('example')), 'Reset to .env: .env applies again.');
        self::assertSame('unchecked', $this->connections->status('example')['state'], 'And is checked again before it is a connection.');
    }
}
