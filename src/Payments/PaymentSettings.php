<?php

declare(strict_types=1);

namespace CattoPHP\Payments;

use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;

/** Each payment gateway's settings, one group a gateway (`payment_<name>`), so the Settings page can change them. */
final class PaymentSettings implements SettingsProvider
{
    public function __construct(private readonly GatewayDefinitions $definitions)
    {
    }

    public function settingGroups(): iterable
    {
        foreach ($this->definitions->all() as $definition) {
            yield new SettingGroup($definition->settingsGroup(), $definition->label, $definition->description, array_values($definition->parameters));
        }
    }
}
