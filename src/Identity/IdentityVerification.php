<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

/**
 * Identity verification through Didit's hosted page: the person photographs their ID or passport and takes a live selfie
 * on Didit's page, and Didit matches them. No identity document is ever uploaded to the site.
 *
 * - Starting a check creates a Didit session (the workflow, the account's reference as vendor_data and the return
 *   address) and sends the person to Didit's page.
 * - The result arrives by Didit's webhook, its signature checked and each event handled once, and is also read when the
 *   person returns, so it works where the webhook cannot reach the site.
 * - Approved: the project's IdentityApproved is told, unless the document shows the person younger than the minimum age.
 *   In review: the person is told it is being reviewed. Declined: the person is told why in plain words and may try
 *   again, at most 3 times, after which an administrator must allow another try.
 * - An administrator revokes a verification with a reason and allows another try. Every event goes into the activity
 *   log, without the document number or date of birth.
 * - Without the keys, verification is not available. Verification is never skipped.
 * - A live site never takes a result from Didit's sandbox: sandbox checks are mocked and can be forced to pass, so such
 *   a result is ignored, by the webhook and on the person's return alike, and recorded in the activity log.
 */
final class IdentityVerification
{
    /** Declined checks a person may make before an administrator must allow another try. */
    public const TRIES = 3;

    /** Didit's console, where an administrator opens a check by its session id. */
    public const CONSOLE = 'https://business.didit.me';

    /**
     * @param string $accountDetail the name the account goes by in the activity log's details
     * @param int|null $minimumAge the age from which an approval counts for IdentityApproved; null for any age
     */
    public function __construct(
        private readonly IdentityChecks $checks,
        private readonly DiditClient $didit,
        private readonly IdentitySettings $settings,
        private readonly ClockInterface $clock,
        private readonly ?ActivityLog $activity = null,
        private readonly ?IdentityAccounts $accounts = null,
        private readonly ?IdentityApproved $approved = null,
        private readonly string $accountDetail = 'account',
        private readonly ?int $minimumAge = null,
    ) {
    }

    public function isVerified(string $account): bool
    {
        return $this->checks->approved($account) !== null;
    }

    /**
     * Where an account stands, for its verification page and an administrator.
     *
     * @return array{status:string,verified:bool,verification:?array<string,mixed>,latest:?array<string,mixed>,tries_used:int,tries_left:int,blocked:bool,in_review:bool,available:bool,minor:bool,status_words:string}
     */
    public function state(string $account): array
    {
        $approved = $this->checks->approved($account);
        $latest = $this->checks->latest($account);
        $declined = $this->checks->declinedTries($account);
        $status = $approved !== null ? 'verified' : (string) ($latest['status'] ?? 'none');

        return [
            'status' => $status,
            'verified' => $approved !== null,
            'verification' => $approved,
            'latest' => $latest,
            'tries_used' => $declined,
            'tries_left' => max(0, self::TRIES - $declined),
            'blocked' => $approved === null && $declined >= self::TRIES,
            'in_review' => $approved === null && ($latest['status'] ?? '') === IdentityDecision::IN_REVIEW,
            'available' => $this->settings->available(),
            'minor' => $approved !== null && $this->minimumAge !== null && self::younger($approved['date_of_birth'] ?? null, $this->minimumAge, $this->now()),
            'status_words' => self::words($status),
        ];
    }

    /**
     * Starts a check on Didit's page and returns its address. Refused when the account is verified, a check is being
     * reviewed, three checks were declined or verification is not available.
     */
    public function start(string $account): string
    {
        $state = $this->state($account);
        if ($state['verified']) throw new RuntimeException('Your identity is already verified.');
        if (!$state['available']) throw new RuntimeException('Identity verification is not available at the moment. Please try again later.');
        if ($state['in_review']) throw new RuntimeException('Your identity check is being reviewed. We will let you know the result.');
        if ($state['blocked']) throw new RuntimeException('Your identity check was declined ' . self::TRIES . ' times. Please contact us so we can allow another try.');
        $session = $this->didit->createSession($this->accounts?->reference($account) ?? $account, $this->settings->returnUrl());
        $this->checks->transaction(function () use ($account, $session): void {
            $row = $this->checks->create($account, $session['session_id']);
            $this->record('identity.verification_started', $account, ['verification_id' => (int) $row['id'], 'session_id' => $session['session_id']], $account);
        });

        return $session['url'];
    }

    /**
     * The person came back from Didit's page: the decision is read from Didit, so the check finishes even where the
     * webhook cannot reach the site.
     *
     * @return array<string,mixed> the account's state afterwards
     */
    public function returned(string $account, string $sessionId): array
    {
        $row = $this->checks->bySession($sessionId);
        if ($row === null || (string) $row['account'] !== $account) throw new InvalidArgumentException('That identity check could not be found.');
        try {
            $report = $this->didit->decision($sessionId);
            if (!$this->sandboxOnLiveSite($report, $sessionId)) $this->apply($sessionId, self::text($report['status'] ?? ''), $report);
        } catch (RuntimeException) {
            // The webhook still brings the result; the person sees the check as it stands.
        }

        return $this->state($account);
    }

    /**
     * Didit's webhook. Refused unless signed with the webhook secret within the last 5 minutes. Each event is handled
     * once; anything but a verification session's status change is ignored.
     *
     * @param array<string,string> $headers lower-case header name => value
     */
    public function webhook(string $rawBody, array $headers): void
    {
        if (!DiditSignature::verify($rawBody, $headers['x-signature-v2'] ?? '', $headers['x-signature'] ?? '', $headers['x-timestamp'] ?? '', $this->settings->webhookSecret(), $this->now()->getTimestamp(),
            $headers['x-signature-v2-previous'] ?? '', $headers['x-signature-previous'] ?? '')) {
            throw new InvalidDiditWebhook('The webhook signature is not valid.');
        }
        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidDiditWebhook('The webhook is not JSON.');
        }
        if (!is_array($event) || ($event['webhook_type'] ?? '') !== 'status.updated' || ($event['session_kind'] ?? '') === 'business') return;
        $sessionId = self::text($event['session_id'] ?? '');
        if ($sessionId === '' || $this->checks->bySession($sessionId) === null) return;
        $eventId = self::text($event['event_id'] ?? '');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $eventId) === 1 && !$this->checks->rememberEvent($eventId)) return;
        /** @var array<string,mixed> $event */
        if ($this->sandboxOnLiveSite($event, $sessionId)) return;
        /** @var array<string,mixed> $decision */
        $decision = is_array($event['decision'] ?? null) ? $event['decision'] : [];
        $this->apply($sessionId, self::text($event['status'] ?? ''), $decision);
    }

    /**
     * Records Didit's status for a check. A decided check is changed only by a later decision (a reviewer's), a revoked
     * one never. An approval tells the project's IdentityApproved.
     *
     * @param array<string,mixed> $report the decision report
     */
    public function apply(string $sessionId, string $diditStatus, array $report): void
    {
        $decision = IdentityDecision::from($diditStatus, $report);
        $approvedNow = $this->checks->transaction(function () use ($sessionId, $decision): ?string {
            $row = $this->checks->bySession($sessionId, true);
            if ($row === null || $row['status'] === IdentityDecision::REVOKED || $row['status'] === $decision->status) return null;
            if ($decision->status === IdentityDecision::STARTED && $row['status'] !== IdentityDecision::STARTED) return null;
            $account = (string) $row['account'];
            if ($decision->status === IdentityDecision::APPROVED) {
                $other = $this->checks->approved($account);
                // One verification at a time; a check approved without a name waits for a reviewer.
                if (($other !== null && (int) $other['id'] !== (int) $row['id']) || $decision->name === null) return null;
            }
            $this->checks->decide((int) $row['id'], $decision);
            $this->record('identity.verification_' . $decision->status, $account, ['verification_id' => (int) $row['id'], 'session_id' => $sessionId]
                + ($decision->status === IdentityDecision::APPROVED ? ['document_type' => $decision->documentType, 'issuing_country' => $decision->issuingCountry] : [])
                + ($decision->status === IdentityDecision::DECLINED ? ['reason' => $decision->declineReason] : []));

            return $decision->status === IdentityDecision::APPROVED ? $account : null;
        });
        if ($approvedNow !== null && $this->approved !== null && !$this->state($approvedNow)['minor']) {
            try {
                $this->approved->approved($approvedNow);
            } catch (Throwable) {
                // The project records what it could not do; the check stays approved.
            }
        }
    }

    /** An administrator revokes an approved verification with a written reason. The account must verify again. */
    public function revoke(int $verificationId, string $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) throw new InvalidArgumentException('Write why the verification is revoked, in at most 500 characters.');
        $this->checks->transaction(function () use ($verificationId, $actor, $reason): void {
            $row = $this->checks->find($verificationId, true) ?? throw new InvalidArgumentException('That identity check does not exist.');
            if ($row['status'] !== IdentityDecision::APPROVED) throw new InvalidArgumentException('Only an approved verification can be revoked.');
            $this->checks->revoke($verificationId, $actor, $reason);
            $this->record('identity.verification_revoked', (string) $row['account'], ['verification_id' => $verificationId, 'reason' => $reason], $actor);
        });
    }

    /** An administrator allows another try after three declined checks. */
    public function allowAnotherTry(string $account, string $actor): void
    {
        $this->checks->transaction(function () use ($account, $actor): void {
            $this->checks->allowAnotherTry($account);
            $this->record('identity.retry_allowed', $account, [], $actor);
        });
    }

    /** @return list<array<string,mixed>> the checks Didit is reviewing, oldest first */
    public function inReview(): array
    {
        return $this->checks->inReview();
    }

    /** @return list<array<string,mixed>> an account's checks, newest first, each with its status in words */
    public function history(string $account): array
    {
        return array_map(static fn(array $row): array => $row + ['status_words' => self::words((string) $row['status'])], $this->checks->history($account));
    }

    /** A status in words. */
    public static function words(string $status): string
    {
        return match ($status) {
            'verified', IdentityDecision::APPROVED => 'Verified',
            IdentityDecision::STARTED => 'Started, not finished',
            IdentityDecision::IN_REVIEW => 'Being reviewed',
            IdentityDecision::DECLINED => 'Declined',
            IdentityDecision::ABANDONED => 'Left unfinished',
            IdentityDecision::EXPIRED => 'Expired',
            IdentityDecision::REVOKED => 'Revoked',
            default => 'Not verified',
        };
    }

    /** Whether someone born on this date (YYYY-MM-DD…) is younger than the age given today. No date is not younger. */
    public static function younger(mixed $dateOfBirth, int $years, DateTimeImmutable $today): bool
    {
        if (!is_string($dateOfBirth) || preg_match('/^\d{4}-\d{2}-\d{2}/', $dateOfBirth) !== 1) return false;

        return (new DateTimeImmutable(substr($dateOfBirth, 0, 10)))->modify('+' . $years . ' years') > $today->setTime(0, 0);
    }

    /**
     * True, and recorded, when a live site is handed a result from Didit's sandbox: its checks are mocked and can be
     * forced to pass, so it never verifies anyone here. Didit marks every webhook and decision with its environment.
     *
     * @param array<string,mixed> $data the webhook event or the decision report
     */
    private function sandboxOnLiveSite(array $data, string $sessionId): bool
    {
        if (!$this->settings->ignoresSandboxResults()) return false;
        if (strtolower(trim(self::text($data['environment'] ?? ''))) !== 'sandbox') return false;
        $row = $this->checks->bySession($sessionId);
        $this->record('identity.sandbox_ignored', $row === null ? null : (string) $row['account'], ['session_id' => $sessionId]);

        return true;
    }

    /**
     * An entry in the activity log: the account first, under the project's name for it (a number written as one), then
     * the details; by the actor given, or by the system.
     *
     * @param array<string,mixed> $details
     */
    private function record(string $action, ?string $account, array $details, ?string $actor = null): void
    {
        $this->activity?->record($action, [$this->accountDetail => $account === null ? null : (ctype_digit($account) ? (int) $account : $account)] + $details,
            actor: $actor !== null && $actor !== '' ? new Actor($actor, $actor) : Actor::system());
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
