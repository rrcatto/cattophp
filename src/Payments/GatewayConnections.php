<?php

declare(strict_types=1);

namespace CattoPHP\Payments;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\ServiceConnections;
use CattoPHP\Settings\Settings;
use RuntimeException;
use Throwable;

/**
 * Each payment gateway's connection, as a settings page shows and controls it. A
 * connection exists only once Check the connection established it: every key the gateway needs has a value, and the
 * provider accepted them. The provider is asked by the project, which knows how (Omnipay has no request every gateway
 * answers): check() takes that question as a callable answering null when the provider accepted the keys, else its words.
 * Until then the page is a form with Check the connection; connected, the keys are shown as text with Drop connection,
 * which forgets every key, even those .env gives, until new ones are saved or reset to .env. Where it stands is kept
 * among the connections (ServiceConnections) by the gateway's name, for the keys it was checked with: changed since, in
 * Settings or in .env, it is not checked yet. Nothing here retries a connection by itself.
 */
final class GatewayConnections
{
    public function __construct(
        private readonly PaymentGateways $gateways,
        private readonly Settings $settings,
        private readonly ServiceConnections $connections,
    ) {
    }

    /**
     * Where the gateway's connection stands: `none` when none of the keys it needs has a value, `unchecked`, `failed`
     * (with what the provider said) or `connected`, and when it was last checked.
     *
     * @return array{state:string,checked_at:string,error:string}
     */
    public function status(string $gateway): array
    {
        $status = $this->connections->status($gateway, $this->needed($gateway));

        return ['state' => $status['state'], 'checked_at' => $status['checked_at'], 'error' => $status['error']];
    }

    public function connected(string $gateway): bool
    {
        return $this->status($gateway)['state'] === ServiceConnections::CONNECTED;
    }

    /**
     * Check the connection: refused while a key the gateway needs has no value; otherwise $ask asks the provider, by a
     * request that changes nothing, and answers null when it accepted the keys, else its words (it may throw). The outcome
     * is recorded; a refusal is thrown with its words.
     *
     * @param callable():?string $ask
     * @throws RuntimeException
     */
    public function check(string $gateway, callable $ask): void
    {
        $missing = $this->gateways->missing($gateway);
        if ($missing !== []) {
            $labels = [];
            foreach ($this->gateways->definition($gateway)->parameters as $setting) {
                if (in_array($setting->name, $missing, true)) {
                    $labels[] = self::lower($setting->label);
                }
            }
            $words = 'Enter the ' . implode(' and the ', $labels) . ' first.';
            $this->connections->record($gateway, $this->needed($gateway), false, $words);
            throw new RuntimeException($words);
        }
        try {
            $problem = $ask();
        } catch (Throwable $failed) {
            $problem = $failed->getMessage() !== '' ? $failed->getMessage() : 'The provider did not answer.';
        }
        $this->connections->record($gateway, $this->needed($gateway), $problem === null, (string) $problem);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }

    /** Drop connection: every key of the gateway forgotten, .env's too, until new ones are saved or reset to .env. */
    public function drop(string $gateway, ?Actor $by = null): void
    {
        foreach ($this->gateways->definition($gateway)->parameters as $setting) {
            $this->settings->clear($setting->name, $by);
        }
        $this->connections->forget($gateway);
    }

    /** Reset to .env: the keys .env gives apply again, not yet checked. */
    public function resetToEnv(string $gateway, ?Actor $by = null): void
    {
        $this->settings->resetGroup($this->gateways->definition($gateway)->settingsGroup(), $by);
        $this->connections->forget($gateway);
    }

    /** @return list<string> the values of the keys the gateway needs, which a check reads (an optional key is not checked) */
    private function needed(string $gateway): array
    {
        $values = [];
        foreach ($this->gateways->definition($gateway)->parameters as $setting) {
            if (!$setting->optional) {
                $values[] = $this->settings->get($setting->name);
            }
        }

        return $values;
    }

    /** A label in the middle of a sentence: "Client ID" becomes "client ID", an abbreviation kept. */
    private static function lower(string $label): string
    {
        return preg_match('/^[A-Z][a-z]/', $label) === 1 ? lcfirst($label) : $label;
    }
}
