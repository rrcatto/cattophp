<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Settings\Fact;
use CattoPHP\Settings\FactGroup;
use CattoPHP\Settings\FactsProvider;

/** A project's facts on the Settings page, as an application guarding a machine would show the networks it protects. */
final class ExampleSettingsFacts implements FactsProvider
{
    public function settingsFacts(): iterable
    {
        yield new FactGroup('protected_networks', 'Protected networks', 'These networks are never refused. They are set in the installation\'s configuration and cannot be changed here.', [
            new Fact('Office', '192.0.2.0/24'),
            new Fact('Tunnel', '198.51.100.0/24'),
        ]);
    }
}
