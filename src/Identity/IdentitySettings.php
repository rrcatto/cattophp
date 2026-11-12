<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use CattoPHP\Installation\Installation;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Settings\EnvironmentValues;

/**
 * Identity verification's settings. Verification uses Didit's hosted page; its keys come from .env (DIDIT_API_KEY,
 * DIDIT_WORKFLOW_ID, DIDIT_WEBHOOK_SECRET) and are never written by the site. Without all three, verification is not
 * available, and whatever waits for it waits. Verification is never skipped.
 *
 * Didit tells its keys apart by their start: sk_test_ is a sandbox key, whose checks are mocked and can be forced to
 * pass, and sk_live_ a live one. A live site with a sandbox key has no verification, rather than trust a mocked check. A
 * demo site takes either.
 */
final class IdentitySettings
{
    /** The .env keys verification needs. */
    public const KEYS = ['DIDIT_API_KEY', 'DIDIT_WORKFLOW_ID', 'DIDIT_WEBHOOK_SECRET'];

    /** Didit's key prefixes: a sandbox key and a live one. */
    public const SANDBOX_PREFIX = 'sk_test_';
    public const LIVE_PREFIX = 'sk_live_';

    /**
     * @param string $returnPath where the person goes when the hosted page is done; Didit adds the session id and status
     * @param string $webhookPath where Didit sends its webhooks: entered in the Didit console
     */
    public function __construct(
        private readonly EnvironmentValues $environment = new EnvironmentValues(),
        private readonly ?SiteMode $site = null,
        private readonly ?Installation $installation = null,
        private readonly string $returnPath = '/identity/return',
        private readonly string $webhookPath = '/webhooks/didit',
    ) {
    }

    /** Whether the keys verification needs are all in .env, and a live site does not hold a sandbox key. */
    public function available(): bool
    {
        return $this->missingKeys() === [] && !$this->sandboxKeyOnLiveSite();
    }

    /** sandbox, live or unknown, from the key's start. */
    public function keyKind(): string
    {
        $key = $this->apiKey();

        return str_starts_with($key, self::SANDBOX_PREFIX) ? 'sandbox' : (str_starts_with($key, self::LIVE_PREFIX) ? 'live' : 'unknown');
    }

    /** A live site holding a sandbox key: its checks prove nothing, so verification is not available. */
    public function sandboxKeyOnLiveSite(): bool
    {
        return $this->site !== null && !$this->site->isDemo() && $this->keyKind() === 'sandbox';
    }

    /** Whether this is a demo site, for a settings page's words. */
    public function demoSite(): bool
    {
        return $this->site === null || $this->site->isDemo();
    }

    /** Whether a result from Didit's sandbox is ignored here: on a live site, since sandbox checks prove nothing. */
    public function ignoresSandboxResults(): bool
    {
        return $this->site !== null && !$this->site->isDemo();
    }

    /** @return list<string> the .env keys still missing */
    public function missingKeys(): array
    {
        // An example file's placeholders start with replace-with-: a copied example is not a configured site.
        return array_values(array_filter(self::KEYS, fn(string $key): bool => $this->environment->get($key) === '' || str_starts_with($this->environment->get($key), 'replace-with-')));
    }

    public function apiKey(): string
    {
        return $this->environment->get('DIDIT_API_KEY');
    }

    public function workflowId(): string
    {
        return $this->environment->get('DIDIT_WORKFLOW_ID');
    }

    public function webhookSecret(): string
    {
        return $this->environment->get('DIDIT_WEBHOOK_SECRET');
    }

    /** The site's own address, without a closing slash. */
    public function siteUrl(): string
    {
        return $this->installation?->address() ?? rtrim($this->environment->get('APP_URL'), '/');
    }

    public function returnPath(): string
    {
        return $this->returnPath;
    }

    public function webhookPath(): string
    {
        return $this->webhookPath;
    }

    /** The address to enter in the Didit console as the webhook destination. */
    public function webhookUrl(): string
    {
        return $this->siteUrl() . $this->webhookPath;
    }

    public function returnUrl(): string
    {
        return $this->siteUrl() . $this->returnPath;
    }
}
