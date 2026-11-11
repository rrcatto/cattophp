<?php

declare(strict_types=1);

namespace CattoPHP\Payments;

use CattoPHP\Settings\Settings;
use Omnipay\Common\GatewayFactory;
use Omnipay\Common\GatewayInterface;
use Omnipay\Common\Http\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The project's payment gateways through Omnipay (https://omnipay.thephpleague.com), each kept in Settings: its keys
 * start from .env and the Settings page overrides them with a Reset, a secret stored encrypted and never shown again.
 * `gateway('stitch')` gives the Omnipay gateway with its parameters from Settings and the current request (which a
 * webhook's notification reads); `configured()` says whether every parameter it needs has a value, and `missing()`
 * which have none. The project's own code decides what a payment pays for: CattoPHP names no order or product.
 *
 * Omnipay finds its HTTP client by discovery (any php-http client implementation, such as Symfony's HTTP client).
 */
final class PaymentGateways
{
    public function __construct(
        private readonly GatewayDefinitions $definitions,
        private readonly Settings $settings,
        private readonly ?RequestStack $requests = null,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    /** @return array<string,GatewayDefinition> */
    public function definitions(): array
    {
        return $this->definitions->all();
    }

    public function has(string $name): bool
    {
        return $this->definitions->has($name);
    }

    public function definition(string $name): GatewayDefinition
    {
        return $this->definitions->get($name);
    }

    /** Whether every parameter the gateway needs has a value. */
    public function configured(string $name): bool
    {
        return $this->missing($name) === [];
    }

    /**
     * The settings (named as in .env) of the parameters the gateway needs and lacks.
     *
     * @return list<string>
     */
    public function missing(string $name): array
    {
        $missing = [];
        foreach ($this->definition($name)->parameters as $setting) {
            if (!$setting->optional && trim($this->settings->get($setting->name)) === '') {
                $missing[] = $setting->name;
            }
        }

        return $missing;
    }

    /**
     * Each parameter's value as Settings holds it, by the Omnipay parameter's name; an optional one without a value is
     * left out.
     *
     * @return array<string,string>
     */
    public function parameters(string $name): array
    {
        $values = [];
        foreach ($this->definition($name)->parameters as $parameter => $setting) {
            $value = trim($this->settings->get($setting->name));
            if ($value !== '' || !$setting->optional) {
                $values[$parameter] = $value;
            }
        }

        return $values;
    }

    /** The Omnipay gateway, its parameters from Settings, reading the current request. */
    public function gateway(string $name): GatewayInterface
    {
        $definition = $this->definition($name);
        $gateway = (new GatewayFactory())->create($definition->omnipay, $this->httpClient, $this->requests?->getCurrentRequest() ?? Request::createFromGlobals());
        $gateway->initialize($this->parameters($name));

        return $gateway;
    }
}
