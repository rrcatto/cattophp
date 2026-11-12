<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

/**
 * Sends campaigns, a little each run (every minute from the project's scheduled job). Each run: the campaigns due start
 * (their audience taken from the project at that moment, up to the most they send to); then, within the sending hours and
 * the allowance (the most an hour, and a gentle first day that doubles each day after for 20 days), each sending campaign
 * hands its next copies to the transport, a copy skipped when its address may not be emailed, its person's account is
 * gone, or they stopped every list that brought it; a campaign pauses itself when its bounces or complaints pass the
 * rules (unless an administrator resumed it past them); and what became of copies handed to catto-mail is read back.
 * A transport that refuses pauses the campaign with why; one that is busy leaves the copies queued for the next run.
 */
final class CampaignSender
{
    /** The most copies one run hands on, so a run stays within its minute. */
    public const PER_RUN = 200;

    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly Copies $copies,
        private readonly MailingTransports $transports,
        private readonly CopyOutcomes $outcomes,
        private readonly Subscriptions $subscriptions,
        private readonly Suppressions $suppressions,
        private readonly CampaignProject $project,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array{started:int,handed:int,paused:int,resolved:int,notes:list<string>} */
    public function run(): array
    {
        $done = ['started' => 0, 'handed' => 0, 'paused' => 0, 'resolved' => 0, 'notes' => []];
        foreach ($this->campaigns->dueToStart(self::stamp($this->now())) as $id) $done['started'] += $this->start($id) ? 1 : 0;
        $budget = $this->allowance();
        foreach ($this->campaigns->sending() as $id) {
            if ($budget < 1) break;
            [$handed, $note] = $this->sendSome($id, $budget);
            $budget -= $handed;
            $done['handed'] += $handed;
            if ($note !== '') $done['notes'][] = $note;
        }
        foreach ($this->campaigns->sending() as $id) $done['paused'] += $this->checkRates($id) ? 1 : 0;
        $done['resolved'] = $this->reconcile();

        return $done;
    }

    /** How many copies may be handed on now: none outside the sending hours, the hourly limit, and the gentle first days. */
    public function allowance(): int
    {
        $rules = $this->project->rules();
        $now = $this->now();
        if (!$rules->withinSendingHours($now)) return 0;
        $allowed = $rules->hourlyLimit - $this->copies->handedSince(self::stamp($now->modify('-1 hour')));
        $first = $rules->firstDayLimit;
        if ($first > 0) {
            $zone = new DateTimeZone(date_default_timezone_get());
            $today = $now->setTimezone($zone)->setTime(0, 0);
            $firstAt = $this->copies->firstHandedAt();
            $day = $firstAt === null ? 0 : max(0, (int) (new DateTimeImmutable($firstAt))->setTimezone($zone)->setTime(0, 0)->diff($today)->days);
            $cap = $day >= 20 ? PHP_INT_MAX : $first * (2 ** $day);
            if ($cap < $rules->hourlyLimit * 24) $allowed = min($allowed, $cap - $this->copies->handedSince(self::stamp($today)));
        }

        return max(0, min($allowed, self::PER_RUN));
    }

    private function start(int $campaignId): bool
    {
        return $this->connection->transactional(function () use ($campaignId): bool {
            $campaign = $this->campaigns->find($campaignId, true);
            if ($campaign === null || $campaign['status'] !== 'scheduled') return false;
            $people = $this->project->audience($campaign);
            if ($people !== null) {
                if ((int) ($campaign['max_recipients'] ?? 0) > 0) $people = array_slice($people, 0, (int) $campaign['max_recipients']);
                $this->copies->add($campaignId, $people);
            }
            $now = self::stamp($this->now());
            if ($this->copies->queuedCount($campaignId) === 0) {
                $this->campaigns->update($campaignId, ['status' => 'sent', 'started_at' => $now, 'finished_at' => $now, 'status_note' => 'Nobody followed its lists when it was due, so nothing was sent.']);

                return true;
            }
            $this->campaigns->update($campaignId, ['status' => 'sending', 'started_at' => $now, 'status_note' => null]);

            return true;
        });
    }

    /** @return array{0:int,1:string} copies handed on, and a note when the transport could not take them */
    private function sendSome(int $campaignId, int $budget): array
    {
        try {
            return $this->connection->transactional(function () use ($campaignId, $budget): array {
                $campaign = $this->campaigns->find($campaignId, true);
                if ($campaign === null || $campaign['status'] !== 'sending') return [0, ''];
                $sender = $this->project->sender($campaign);
                $copies = [];
                foreach ($this->copies->nextQueued($campaignId, $budget) as $copy) {
                    $why = $this->stillWanted($copy);
                    if ($why !== '') {
                        $this->copies->update((int) $copy['id'], ['status' => $why === 'suppressed' ? 'suppressed' : 'skipped', 'status_detail' => $why === 'suppressed' ? 'The address may not be emailed news.' : $why, 'resolved_at' => self::stamp($this->now())]);
                        continue;
                    }
                    $drawn = $this->project->draw($campaign, $copy);
                    $copies[] = new OutgoingCopy((int) $copy['id'], (string) $copy['token'], (string) $copy['email'], $drawn->subject, $drawn->html, $drawn->text, $drawn->unsubscribeUrl, (string) (int) ($copy['list_id'] ?? 0), $drawn->listName);
                }
                $handed = 0;
                if ($copies !== []) {
                    $transport = $this->transports->current();
                    try {
                        $result = $transport->send((string) $campaignId, (string) $campaign['kind'], $sender, $copies);
                    } catch (TransportRefused $refused) {
                        $this->campaigns->update($campaignId, ['status' => 'paused', 'status_note' => 'Paused: ' . $refused->getMessage() . ' Nothing more was sent. Fix it, then resume.']);

                        return [0, 'Message ' . $campaignId . ' paused: ' . $refused->getMessage()];
                    }
                    $now = self::stamp($this->now());
                    foreach ($result->handed as $copyId => $handedTo) {
                        $this->copies->update((int) $copyId, ['status' => 'handed', 'handed_at' => $now, 'transport' => $transport->name(), 'transport_job_id' => $handedTo['job_id'], 'transport_message_id' => $handedTo['message_id'], 'attempts' => 1]);
                        $handed++;
                    }
                    foreach ($result->failed as $copyId => $detail) $this->copies->update((int) $copyId, ['status' => 'failed', 'status_detail' => $detail, 'attempts' => 1, 'resolved_at' => $now]);
                }
                // A campaign that waits for more (a welcome email) stays sending for the next person added.
                if (!$this->project->waitsForMore($campaign) && $this->copies->queuedCount($campaignId) === 0) $this->campaigns->update($campaignId, ['status' => 'sent', 'finished_at' => self::stamp($this->now())]);

                return [$handed, ''];
            });
        } catch (TransportUnavailable $busy) {
            // The copies stay queued: the next run tries again.
            return [0, 'Message ' . $campaignId . ' waits: ' . $busy->getMessage()];
        }
    }

    /**
     * Why a queued copy should not go after all, or '' when it should.
     *
     * @param array<string,mixed> $copy
     */
    private function stillWanted(array $copy): string
    {
        if ($this->suppressions->isSuppressed((string) $copy['email'])) return 'suppressed';
        if (($copy['account'] ?? null) === null) return 'The account was deleted before it was sent.';
        $content = is_array($copy['content'] ?? null) ? $copy['content'] : [];
        $lists = array_values(array_filter(array_map('intval', (array) ($content['lists'] ?? []))));
        if ($lists === [] && ($copy['list_id'] ?? null) !== null) $lists = [(int) $copy['list_id']];
        if ($lists === []) return '';
        $on = $this->subscriptions->subscribedListIds((string) $copy['account']);
        foreach ($lists as $listId) if (in_array($listId, $on, true)) return '';
        if (count($lists) > 1) return 'They unsubscribed from everything that brought it before it was sent.';

        return 'They unsubscribed from it before it was sent.';
    }

    /** Pauses a sending campaign whose bounces or complaints passed the rules. True when it paused. */
    private function checkRates(int $campaignId): bool
    {
        $campaign = $this->campaigns->find($campaignId);
        if ($campaign === null || $campaign['status'] !== 'sending' || (bool) $campaign['checks_overridden']) return false;
        $rules = $this->project->rules();
        $figures = $this->campaigns->figures($campaignId);
        $sent = (int) ($figures['sent'] ?? 0);
        if ($sent < $rules->judgeAfter || $sent < 1) return false;
        $bounces = 100 * (int) ($figures['hard_bounced'] ?? 0) / $sent;
        $complaints = 100 * (int) ($figures['complained'] ?? 0) / $sent;
        $why = '';
        if ($bounces > $rules->pauseBouncePercent) $why = ($figures['hard_bounced'] ?? 0) . ' of the ' . $sent . ' emails sent bounced (' . self::percent($bounces) . '), above the limit of ' . self::percent($rules->pauseBouncePercent) . '.';
        elseif ($complaints > $rules->pauseComplaintPercent) $why = ($figures['complained'] ?? 0) . ' of the ' . $sent . ' emails sent were marked as spam (' . self::percent($complaints) . '), above the limit of ' . self::percent($rules->pauseComplaintPercent) . '.';
        if ($why === '') return false;
        $this->campaigns->update($campaignId, ['status' => 'paused', 'status_note' => 'Paused by itself: ' . $why . ' Look at who bounced or complained before you resume or cancel it.']);

        return true;
    }

    /** Reads back from catto-mail what became of the copies it was given, a few jobs each run. */
    private function reconcile(): int
    {
        $resolved = 0;
        foreach ($this->copies->openJobs('cattomail', self::stamp($this->now()->modify('-15 minutes')), 10) as $job) {
            $jobId = $job['transport_job_id'];
            try {
                $outcomes = $this->transports->cattomail()->outcomes($jobId);
            } catch (Throwable) {
                continue;
            }
            foreach ($outcomes as $outcome) {
                if ($outcome['reference'] === '') continue;
                $copy = $this->copies->byToken($outcome['reference']);
                if ($copy === null) continue;
                if ($outcome['status'] === null) {
                    if ($outcome['message_id'] !== '' && (string) ($copy['transport_message_id'] ?? '') === '') $this->copies->update((int) $copy['id'], ['transport_message_id' => $outcome['message_id']]);
                    continue;
                }
                $resolved += $this->outcomes->record($copy, $outcome['status'], '', $outcome['message_id']) ? 1 : 0;
            }
            $this->copies->jobChecked($jobId);
        }

        return $resolved;
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private static function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }
}
