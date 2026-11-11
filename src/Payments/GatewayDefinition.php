<?php

declare(strict_types=1);

namespace CattoPHP\Payments;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingType;
use InvalidArgumentException;

/**
 * One payment gateway a project uses, as its configuration names it: its name in the project (`stitch`), the Omnipay
 * gateway (a short name such as `Stitch`, or a class), the words the Settings page shows, and each of the gateway's
 * parameters with the setting that holds it (named as in .env, a secret stored encrypted, an optional one left out
 * when empty).
 */
final class GatewayDefinition
{
    /** @var array<string,Setting> Each Omnipay parameter, with its setting. */
    public readonly array $parameters;

    /**
     * @param array<string,array{name:string,label:string,secret?:bool,optional?:bool,help?:string}> $parameters
     */
    public function __construct(
        public readonly string $name,
        public readonly string $omnipay,
        public readonly string $label,
        public readonly string $description,
        array $parameters,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('A payment gateway is named in small letters: "%s" is not.', $name));
        }
        if ($parameters === []) {
            throw new InvalidArgumentException(sprintf('The payment gateway %s names none of its parameters.', $name));
        }
        $settings = [];
        foreach ($parameters as $parameter => $setting) {
            $settings[$parameter] = new Setting(
                $setting['name'],
                $setting['label'],
                SettingType::Text,
                help: $setting['help'] ?? '',
                optional: $setting['optional'] ?? false,
                secret: $setting['secret'] ?? false,
                longest: 2000,
            );
        }
        $this->parameters = $settings;
    }

    /** The key of its group of settings. */
    public function settingsGroup(): string
    {
        return 'payment_' . $this->name;
    }
}
