<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Network\NetworkLookup;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What a person decides about an address: lock it out (for a while or for good, at the server's firewall too when the
 * link is on), lift a lockout, or mark a flagged address not a problem. Nobody can lock out the address they are using,
 * their own network owner, or what the Never lock out list holds. Each decision goes into the activity log.
 */
class LockoutService
{
    /** The lengths a lockout by hand is offered: the value, and how a page says it. */
    public const LENGTHS = ['1h' => '1 hour', '1d' => '1 day', '1w' => '1 week', '30d' => '1 month', 'good' => 'For good'];

    public function __construct(
        private readonly Lockouts $lockouts,
        private readonly Flags $flags,
        private readonly ProtectionSettings $settings,
        private readonly ServerLink $server,
        private readonly NetworkLookup $network,
        private readonly ?ActivityLog $activity = null,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    /** Locks out, ending whatever was in force on exactly the same thing; answers the lockout's id. */
    public function lockOut(LockoutTarget $target, string $length, string $why, bool $firewall, ?Actor $actor = null): int
    {
        $why = trim($why);
        if ($why === '') throw new InvalidArgumentException('Say why it is being locked out.');
        if (!isset(self::LENGTHS[$length])) throw new InvalidArgumentException('Choose how long it is locked out for.');
        $this->assertAllowed($target);
        $by = $actor?->id;
        $until = $length === 'good' ? null : Duration::parse($length)->after(new DateTimeImmutable());
        $firewall = $firewall && $target->kind !== LockoutTarget::OWNER && $this->settings->values()['firewall_link'] === true;
        $this->lockouts->liftSameTarget($target->network, $target->asn, $by);
        if ($firewall) $this->server->ban((string) $target->network);
        $id = $this->lockouts->add($target->toArray() + ['which' => 'lockout', 'why' => $why, 'rule_key' => null, 'automatic' => false, 'created_by' => $by, 'until' => $until, 'firewall' => $firewall ? 'banned' : 'no']);
        if ($target->kind === LockoutTarget::ADDRESS) $this->flags->raise((string) $target->network, 'high', 'by_hand', 'Locked out by hand: ' . $why, 60);
        $this->activity?->record('site.locked_out', ['what' => $target->label, 'kind' => $target->kind, 'length' => $length, 'firewall' => $firewall], actor: $actor);

        return $id;
    }

    public function lift(int $id, ?Actor $actor = null): void
    {
        $lockout = $this->lockouts->find($id);
        if ($lockout === null) throw new InvalidArgumentException('That lockout could not be found.');
        $atFirewall = $lockout['firewall'] === 'banned';
        if (!$this->lockouts->lift($id, $actor?->id, $atFirewall ? 'released' : null)) throw new InvalidArgumentException('That lockout has already ended.');
        if ($atFirewall) $this->server->release((string) $lockout['network_text']);
        $this->activity?->record('site.lockout_lifted', ['what' => (string) $lockout['label']], actor: $actor);
    }

    /** Not a problem ends what the site did by itself; a lockout by hand stays until it is lifted by hand. */
    public function markNotAProblem(string $subject, ?Actor $actor = null): void
    {
        $this->flags->markFine($subject, $actor?->id);
        $this->lockouts->liftAutomatic($subject, $actor?->id);
        $this->activity?->record('site.flag_not_a_problem', ['address' => $subject], actor: $actor);
    }

    private function assertAllowed(LockoutTarget $target): void
    {
        $own = (string) $this->requests?->getCurrentRequest()?->getClientIp();
        if ($target->kind === LockoutTarget::OWNER) {
            if ($own !== '' && $this->network->facts($own)->asn === $target->asn) throw new InvalidArgumentException('You are using an address of this network owner now, so you cannot lock it out.');

            return;
        }
        $network = (string) $target->network;
        if ($own !== '' && Addresses::contains($network, $own)) throw new InvalidArgumentException('You are using this address now, so you cannot lock it out.');
        foreach ((array) $this->settings->values()['never_lock_out'] as $entry) {
            if (Addresses::contains($network, (string) strtok((string) $entry, '/')) || Addresses::contains((string) $entry, (string) strtok($network, '/'))) {
                throw new InvalidArgumentException($target->label . ' is on your Never lock out list, so it cannot be locked out.');
            }
        }
    }
}
