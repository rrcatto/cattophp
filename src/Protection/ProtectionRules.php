<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Foundation\Duration;
use DateTimeImmutable;

/**
 * The rules, run every minute (SiteProtectionTask): each address over a rule's limit is flagged, and timed out where the
 * rule says so; a time-out after another doubles, up to the longest, and enough of them within the window lock the
 * address out. Administrators' addresses, signed-in people's (flagged, never timed out), proved search engines, private
 * addresses, the Never lock out list and an address marked not a problem are spared. Lockouts that ended are taken off
 * the server's firewall, and a few addresses calling themselves search engines are proved each run.
 */
class ProtectionRules
{
    /** How long administrators' and signed-in people's addresses are spared after they were used. */
    public const SPARED_DAYS = 30;

    /** How many addresses are asked about in one run: a name lookup can take seconds. */
    public const PROOFS_A_RUN = 10;

    public function __construct(
        private readonly SiteLog $log,
        private readonly ProtectionSettings $settings,
        private readonly Flags $flags,
        private readonly Lockouts $lockouts,
        private readonly ServerLink $server,
        private readonly SearchEngineProof $proof = new SearchEngineProof(),
    ) {
    }

    public function run(): string
    {
        $proved = $this->proveSearchEngines();
        $settings = $this->settings->values();
        $done = ['flagged' => 0, 'timed' => 0, 'locked' => 0];
        foreach (ProtectionSettings::RULES as $key => $rule) {
            $set = $settings['rules'][$key] ?? null;
            if (!is_array($set) || empty($set['on'])) continue;
            foreach ($this->log->offenders($key, Duration::parse((string) $set['within'])->lengthInSeconds(), (int) $set['count']) as $offender) {
                $this->act($key, $rule, $set, $settings, $offender, $done);
            }
        }
        $released = 0;
        foreach ($this->lockouts->firewallDue() as $due) {
            $this->server->release($due['network_text']);
            $this->lockouts->firewallReleased($due['id']);
            $released++;
        }

        return sprintf('%d flagged, %d timed out, %d locked out, %d released at the firewall, %d search engines checked.', $done['flagged'], $done['timed'], $done['locked'], $released, $proved);
    }

    /** Why a rule fired, in words. */
    public static function words(string $key, int $count, string $within): string
    {
        $in = Duration::parse($within)->words();

        return match ($key) {
            'missing' => 'Asked for ' . $count . ' pages that do not exist in ' . $in . '.',
            'flood' => $count . ' requests in ' . $in . '.',
            'links' => 'Asked for sign-in links for ' . $count . ' different email addresses in ' . $in . '.',
            'register' => 'Tried to register ' . $count . ' times in ' . $in . '.',
            'tokens' => 'Sent ' . $count . ' forms with a false or expired form token in ' . $in . '.',
            'trap' => 'Filled in a hidden field that people never see.',
            'script' => 'Sent a form from a script, not a browser.',
            default => 'Sent a form from a browser years out of date, or from no browser at all.',
        };
    }

    /**
     * @param array{name:string,counted:bool,level:string,env:string,start:string} $rule
     * @param array<string,mixed> $set
     * @param array<string,mixed> $settings
     * @param array{subject:string,n:int} $offender
     * @param array{flagged:int,timed:int,locked:int} $done
     */
    private function act(string $key, array $rule, array $set, array $settings, array $offender, array &$done): void
    {
        $subject = $offender['subject'];
        if ($this->settings->isNeverLockedOut($subject) || Addresses::isPrivate($subject)) return;
        if (!empty($settings['spare_administrators']) && $this->log->usedByAnAdministrator($subject, self::SPARED_DAYS)) return;
        if (!empty($settings['spare_search_engines']) && $this->log->isProvedSearchEngine($subject)) return;
        if ($this->flags->isFine($subject, ProtectionSettings::FINE_DAYS)) return;
        $fine = $this->flags->findBySubject($subject);
        if ($fine !== null && ($fine['fine'] === true || $fine['fine'] === 't')) $this->flags->reopen($subject);

        $within = Duration::parse((string) $set['within'])->lengthInSeconds();
        $words = self::words($key, $offender['n'], (string) $set['within']);
        $timeOut = $set['then'] === 'timeout';
        if ($timeOut && !empty($settings['spare_people']) && $this->log->usedBySomeoneSignedIn($subject, self::SPARED_DAYS)) {
            $timeOut = false;
            $words .= ' Signed-in people have used this address, so it was only flagged, not timed out.';
        }
        $flagged = $this->flags->raise($subject, $rule['level'], $key, $words, max($within, 60));
        if (!$timeOut) {
            if ($flagged) $done['flagged']++;

            return;
        }
        $this->timeOut($key, $set, $settings, $subject, $words, $done);
    }

    /**
     * @param array<string,mixed> $set
     * @param array<string,mixed> $settings
     * @param array{flagged:int,timed:int,locked:int} $done
     */
    private function timeOut(string $key, array $set, array $settings, string $subject, string $words, array &$done): void
    {
        $now = new DateTimeImmutable();
        $earlier = $this->lockouts->automaticTimeoutsSince($subject, Duration::parse((string) $settings['lockout_within'])->before($now));
        $base = ['kind' => LockoutTarget::ADDRESS, 'network' => $subject, 'asn' => null, 'label' => $subject, 'rule_key' => $key, 'automatic' => true, 'created_by' => null, 'firewall' => 'no'];
        if (count($earlier) + 1 >= (int) $settings['lockout_count']) {
            $this->lockouts->add($base + ['which' => 'lockout', 'why' => 'Timed out ' . (count($earlier) + 1) . ' times within ' . Duration::parse((string) $settings['lockout_within'])->words() . '.', 'until' => Duration::parse((string) $settings['lockout_length'])->after($now)]);
            $done['locked']++;

            return;
        }
        $seconds = Duration::parse((string) $set['length'])->lengthInSeconds();
        $again = $earlier !== [];
        if ($again && !empty($settings['doubles'])) {
            $last = $earlier[0];
            $seconds = max($seconds, 2 * ((int) strtotime((string) $last['until_at']) - (int) strtotime($last['started_at'])));
            $seconds = min($seconds, max(Duration::parse((string) $settings['longest'])->lengthInSeconds(), Duration::parse((string) $set['length'])->lengthInSeconds()));
        }
        $this->lockouts->add($base + ['which' => 'timeout', 'why' => $words . ($again ? ' It came back after a time-out.' : ''), 'until' => $now->modify('+' . $seconds . ' seconds')]);
        $done['timed']++;
    }

    /** Proves a few addresses calling themselves search engines; an earlier answer for an address is used again for a week. */
    private function proveSearchEngines(): int
    {
        $asked = 0;
        foreach ($this->log->searchEnginesToProve(self::PROOFS_A_RUN) as $row) {
            $this->log->proved($row['address'], $this->log->earlierProof($row['address']) ?? $this->proof->proves($row['address'], $row['engine']));
            $asked++;
        }

        return $asked;
    }
}
