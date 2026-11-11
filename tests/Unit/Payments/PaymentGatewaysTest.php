<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Payments;

use CattoPHP\Payments\GatewayDefinition;
use CattoPHP\Payments\GatewayDefinitions;
use CattoPHP\Payments\PaymentGateways;
use CattoPHP\Payments\PaymentSettings;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Tests\Support\ExampleGateway;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Payment gateways kept in Settings: .env's starting keys, the Settings page's over them, secrets encrypted, an Omnipay gateway built from them. */
final class PaymentGatewaysTest extends TestCase
{
    private const CONFIG = [
        'example' => [
            'omnipay' => '\\' . ExampleGateway::class,
            'label' => 'Card payments (Example)',
            'description' => 'The keys from Example\'s dashboard.',
            'parameters' => [
                'apiKey' => ['name' => 'EXAMPLE_API_KEY', 'label' => 'API key'],
                'apiSecret' => ['name' => 'EXAMPLE_API_SECRET', 'label' => 'API secret', 'secret' => true],
                'webhookSecret' => ['name' => 'EXAMPLE_WEBHOOK_SECRET', 'label' => 'Webhook secret', 'secret' => true, 'optional' => true],
            ],
        ],
    ];

    private MemorySettingsStore $store;

    protected function setUp(): void
    {
        $this->store = new MemorySettingsStore();
    }

    /** @param array<string,string> $env */
    private function site(array $env = []): Foundations
    {
        return new Foundations($env, $this->store, [new PaymentSettings(new GatewayDefinitions(self::CONFIG))]);
    }

    private function gateways(Foundations $site, ?RequestStack $requests = null): PaymentGateways
    {
        return new PaymentGateways(new GatewayDefinitions(self::CONFIG), $site->settings, $requests);
    }

    public function testEachGatewayIsAGroupOfSettings(): void
    {
        $group = $this->site()->catalogue->group('payment_example');
        self::assertSame('Card payments (Example)', $group->label);
        self::assertSame(['EXAMPLE_API_KEY', 'EXAMPLE_API_SECRET', 'EXAMPLE_WEBHOOK_SECRET'], array_keys($group->settings));
        self::assertTrue($group->settings['EXAMPLE_API_SECRET']->secret);
        self::assertTrue($group->settings['EXAMPLE_WEBHOOK_SECRET']->optional);
    }

    public function testAGatewayIsConfiguredWhenEveryKeyItNeedsHasAValue(): void
    {
        $gateways = $this->gateways($this->site());
        self::assertFalse($gateways->configured('example'));
        self::assertSame(['EXAMPLE_API_KEY', 'EXAMPLE_API_SECRET'], $gateways->missing('example'), 'The optional webhook secret is not missing.');

        $gateways = $this->gateways($this->site(['EXAMPLE_API_KEY' => 'key-from-env', 'EXAMPLE_API_SECRET' => 'secret-from-env']));
        self::assertTrue($gateways->configured('example'));
        self::assertSame(['apiKey' => 'key-from-env', 'apiSecret' => 'secret-from-env'], $gateways->parameters('example'));
    }

    public function testTheSettingsPageOverridesEnvAndKeepsSecretsEncrypted(): void
    {
        $site = $this->site(['EXAMPLE_API_KEY' => 'key-from-env', 'EXAMPLE_API_SECRET' => 'secret-from-env']);
        $site->settings->save('payment_example', ['EXAMPLE_API_KEY' => 'key-from-page', 'EXAMPLE_API_SECRET' => 'secret-from-page', 'EXAMPLE_WEBHOOK_SECRET' => 'whsec_page']);
        $gateway = $this->gateways($site)->gateway('example');
        self::assertInstanceOf(ExampleGateway::class, $gateway);
        self::assertSame('key-from-page', $gateway->getApiKey());
        self::assertSame('secret-from-page', $gateway->getApiSecret());
        self::assertSame('whsec_page', $gateway->getWebhookSecret());
        $stored = $this->store->all();
        self::assertNotSame('secret-from-page', $stored['EXAMPLE_API_SECRET']->value, 'The secret is stored encrypted.');
        self::assertSame('key-from-page', $stored['EXAMPLE_API_KEY']->value);

        $site->settings->reset('EXAMPLE_API_KEY');
        $again = $this->gateways($site)->gateway('example');
        self::assertInstanceOf(ExampleGateway::class, $again);
        self::assertSame('key-from-env', $again->getApiKey(), 'Reset goes back to .env.');
    }

    public function testTheGatewayReadsTheCurrentRequest(): void
    {
        $requests = new RequestStack();
        $requests->push($webhook = Request::create('https://example.com/webhooks/example', 'POST', content: '{"paid":true}'));
        $gateway = $this->gateways($this->site(), $requests)->gateway('example');
        self::assertInstanceOf(ExampleGateway::class, $gateway);
        self::assertSame($webhook, $gateway->request());
    }

    public function testAnUnknownGatewayIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No payment gateway is named nothing.');
        $this->gateways($this->site())->gateway('nothing');
    }

    public function testADefinitionNamesItsGatewayInSmallLettersAndItsParameters(): void
    {
        try {
            new GatewayDefinition('Stitch', 'Stitch', 'Stitch', '', ['clientId' => ['name' => 'STITCH_CLIENT_ID', 'label' => 'Client ID']]);
            self::fail('A name in capitals was accepted.');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString('small letters', $refused->getMessage());
        }
        $this->expectExceptionMessage('names none of its parameters');
        new GatewayDefinition('stitch', 'Stitch', 'Stitch', '', []);
    }
}
