<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;

/**
 * Identity verification's settings group, as the settings catalogue reads it: Didit's API key, the workflow and the
 * webhook's signing secret. `.env` gives their starting values and Settings overrides them, with a Reset, the secrets
 * stored encrypted. IdentitySettings reads them; IdentityConnection
 * checks them with Didit.
 */
final class IdentitySettingGroup implements SettingsProvider
{
    public const GROUP = 'identity';

    public function settingGroups(): iterable
    {
        yield new SettingGroup(self::GROUP, 'Identity verification', 'Didit\'s keys: the API key, the workflow and the webhook\'s signing secret.', [
            new Setting('DIDIT_API_KEY', 'API key', help: 'From Didit\'s Business Console, under API & Webhooks: sk_test_ for the sandbox, sk_live_ for the live application.', optional: true, secret: true, longest: 500),
            new Setting('DIDIT_WORKFLOW_ID', 'Workflow ID', help: 'The workflow learners go through, from Didit\'s Business Console, under Workflows.', optional: true, longest: 100),
            new Setting('DIDIT_WEBHOOK_SECRET', 'Webhook signing secret', help: 'Filled in when the webhook is registered with Didit; or copied from Didit\'s Business Console.', optional: true, secret: true, longest: 500),
        ]);
    }
}
