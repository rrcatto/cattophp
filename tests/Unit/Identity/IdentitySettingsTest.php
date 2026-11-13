<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Identity;

use CattoPHP\Identity\IdentitySettingGroup;
use CattoPHP\Identity\IdentitySettings;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Identity verification's keys through Settings: what is saved there over .env's, .env's alone without the identity group,
 * and a saved key that cannot be read an error, never a quiet fallback to .env's (review finding 12).
 */
final class IdentitySettingsTest extends TestCase
{
    private const ENV = ['DIDIT_API_KEY' => 'sk_test_from_env', 'DIDIT_WORKFLOW_ID' => 'workflow-from-env', 'DIDIT_WEBHOOK_SECRET' => 'secret-from-env'];

    public function testASavedKeyComesBeforeEnvsAndEnvsAloneWithoutTheIdentityGroup(): void
    {
        $site = new Foundations(self::ENV, settingsProviders: [new IdentitySettingGroup()]);
        $site->settings->set('DIDIT_WORKFLOW_ID', 'workflow-saved');

        $identity = new IdentitySettings(new EnvironmentValues(self::ENV), settings: $site->settings);
        self::assertSame(['workflow-saved', 'sk_test_from_env'], [$identity->workflowId(), $identity->apiKey()]);
        $plain = new IdentitySettings(new EnvironmentValues(self::ENV), settings: (new Foundations(self::ENV))->settings);
        self::assertSame('workflow-from-env', $plain->workflowId(), 'Settings without the identity group: .env\'s.');
    }

    public function testASavedKeyThatCannotBeReadIsAnErrorNeverEnvsKeyQuietly(): void
    {
        $store = new MemorySettingsStore();
        $site = new Foundations(self::ENV, $store, [new IdentitySettingGroup()]);
        // Saved with another APP_KEY, or damaged: this site cannot decrypt it.
        $store->save('DIDIT_WEBHOOK_SECRET', 'v1:' . base64_encode(random_bytes(40)), true, $site->clock->now(), null);
        $identity = new IdentitySettings(new EnvironmentValues(self::ENV), settings: $site->settings);

        try {
            $secret = $identity->webhookSecret();
        } catch (RuntimeException $refused) {
            $secret = null;
            self::assertStringNotContainsString('secret-from-env', $refused->getMessage());
        }
        self::assertNull($secret, '.env\'s secret, which the saved one replaced, came back.');
    }
}
