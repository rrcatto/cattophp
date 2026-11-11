<?php

declare(strict_types=1);

namespace CattoPHP\Payments;

use InvalidArgumentException;

/** The payment gateways a project's configuration names (`payments.gateways`), by name. */
final class GatewayDefinitions
{
    /** @var array<string,GatewayDefinition> */
    private array $definitions = [];

    /**
     * @param array<string,array{omnipay:string,label:string,description?:string,parameters:array<string,array{name:string,label:string,secret?:bool,optional?:bool,help?:string}>}> $config
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $name => $gateway) {
            $this->add(new GatewayDefinition($name, $gateway['omnipay'], $gateway['label'], $gateway['description'] ?? '', $gateway['parameters']));
        }
    }

    public function add(GatewayDefinition $definition): void
    {
        $this->definitions[$definition->name] = $definition;
    }

    /** @return array<string,GatewayDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): GatewayDefinition
    {
        return $this->definitions[$name] ?? throw new InvalidArgumentException(sprintf('No payment gateway is named %s.', $name));
    }
}
