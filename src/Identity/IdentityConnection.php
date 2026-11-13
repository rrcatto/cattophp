<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\ServiceConnections;
use CattoPHP\Settings\Settings;
use Throwable;

/**
 * The connection to Didit, as the Identity verification section shows and controls it. A connection exists only once
 * Check the connection established it: Didit accepted the
 * API key and the workflow is one of the application's. Until then the section is a form with Check the connection;
 * connected, the details are shown as text with Drop connection, which forgets all three keys, even where .env holds
 * some, until new ones are saved or reset to .env. Register the webhook asks Didit for the site's webhook and keeps the
 * signing secret Didit gives. Where it stands is kept among the connections (ServiceConnections, as `didit`), for the API
 * key and workflow it was checked with: changed since, in Settings or in .env, it is not checked yet.
 */
final class IdentityConnection
{
    /** Its name among the connections. */
    public const NAME = 'didit';

    public function __construct(
        private readonly ServiceConnections $connections,
        private readonly Settings $settings,
        private readonly IdentitySettings $identity,
        private readonly DiditClient $didit,
    ) {
    }

    /**
     * Check the connection: asks Didit for the application's workflows (a pure read) and records the outcome. Answers the
     * words to show; throws with what went wrong, recorded too.
     */
    public function check(): string
    {
        try {
            if ($this->identity->apiKey() === '') throw new \RuntimeException('Enter Didit\'s API key first.');
            if ($this->identity->workflowId() === '') throw new \RuntimeException('Enter the workflow ID first.');
            $workflow = null;
            foreach ($this->didit->workflows() as $found) {
                if ($found['workflow_id'] === $this->identity->workflowId()) $workflow = $found;
            }
            if ($workflow === null) throw new \RuntimeException('Didit accepted the API key, but the workflow ID is not one of this application\'s workflows.');
        } catch (Throwable $failed) {
            $this->connections->record(self::NAME, $this->checked(), false, $failed->getMessage());
            throw $failed instanceof \RuntimeException ? $failed : new \RuntimeException($failed->getMessage(), 0, $failed);
        }
        $label = $workflow['label'] !== '' ? $workflow['label'] : $workflow['workflow_id'];
        $this->connections->record(self::NAME, $this->checked(), true, learnt: ['workflow_label' => $label]);

        return 'Connected to Didit' . ($this->identity->keyKind() === 'sandbox' ? '\'s sandbox' : '') . ': the workflow is "' . $label . '".';
    }

    /**
     * After the keys are saved: when they differ from $before, what was found with the old ones no longer says anything,
     * so the connection goes back to not checked.
     *
     * @param array{api_key:string,workflow_id:string,webhook_secret:string} $before
     */
    public function keysSaved(array $before): void
    {
        if ($before !== $this->keys()) $this->forgetState();
    }

    /** @return array{api_key:string,workflow_id:string,webhook_secret:string} the keys as they are now */
    public function keys(): array
    {
        return ['api_key' => $this->identity->apiKey(), 'workflow_id' => $this->identity->workflowId(), 'webhook_secret' => $this->identity->webhookSecret()];
    }

    /** Registers the site's webhook with Didit and keeps the signing secret it gives (shown by Didit only now). */
    public function registerWebhook(?Actor $by): void
    {
        if ($this->status()['state'] !== 'connected') {
            throw new \RuntimeException('Connect to Didit first: Check the connection, then register the webhook.');
        }
        $this->settings->set('DIDIT_WEBHOOK_SECRET', $this->didit->registerWebhook($this->identity->webhookUrl()), $by);
    }

    /** Drop connection: all three keys forgotten, .env's too, until new ones are saved or reset to .env. */
    public function drop(?Actor $by): void
    {
        foreach (IdentitySettings::KEYS as $key) $this->settings->clear($key, $by);
        $this->forgetState();
    }

    /** Reset to .env: the keys .env gives apply again, not yet checked. */
    public function resetToEnv(?Actor $by): void
    {
        $this->settings->resetGroup(IdentitySettingGroup::GROUP, $by);
        $this->forgetState();
    }

    /**
     * Where the connection stands: 'none' without an API key and workflow, 'unchecked' before a check with these keys,
     * 'connected' when Didit accepted them, 'failed' when it did not; with when it was tried, the workflow's name and what
     * went wrong.
     *
     * @return array{state:string,checked_at:string,error:string,workflow_label:string}
     */
    public function status(): array
    {
        $status = $this->connections->status(self::NAME, $this->checked());

        return ['state' => $status['state'], 'checked_at' => $status['checked_at'], 'error' => $status['error'], 'workflow_label' => $status['learnt']['workflow_label'] ?? ''];
    }

    /** @return list<string> what a check reads: the API key and the workflow (the webhook's secret is not checked) */
    private function checked(): array
    {
        return [$this->identity->apiKey(), $this->identity->workflowId()];
    }

    private function forgetState(): void
    {
        $this->connections->forget(self::NAME);
    }
}
