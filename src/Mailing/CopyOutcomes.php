<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;

/**
 * What becomes of a copy once it has left, from every route by which a sending hears back (catto-mail's webhook, reading
 * a job back, a mail server's answer): a copy only moves forward (handed, deferred, delivered, then a soft bounce or a
 * failure, then a hard bounce, then a complaint). A hard bounce puts the address on the addresses never emailed; a spam
 * complaint does too and stops every list its person is on. Its unsubscribe link, or the email app's one-click button,
 * stops the lists that brought the copy, or every list.
 */
final class CopyOutcomes
{
    /** How far along each status is: a copy never moves back. */
    private const RANK = ['queued' => 0, 'handed' => 1, 'deferred' => 2, 'delivered' => 3, 'soft_bounced' => 4, 'failed' => 4, 'suppressed' => 4, 'skipped' => 4, 'bounced' => 5, 'complained' => 6];

    public function __construct(
        private readonly Copies $copies,
        private readonly Suppressions $suppressions,
        private readonly Consents $consents,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /**
     * Records what became of a copy. False when it says nothing new.
     *
     * @param array<string,mixed> $copy
     */
    public function record(array $copy, string $status, string $detail = '', ?string $messageId = null): bool
    {
        if (!isset(self::RANK[$status])) return false;
        $current = self::text($copy['status'] ?? 'queued');
        $fields = [];
        if ($messageId !== null && $messageId !== '' && self::text($copy['transport_message_id'] ?? '') === '') $fields['transport_message_id'] = $messageId;
        $forward = self::RANK[$status] > (self::RANK[$current] ?? 0);
        if ($forward) {
            $fields['status'] = $status;
            if ($detail !== '') $fields['status_detail'] = $detail;
            if (in_array($status, Copies::RESOLVED, true)) $fields['resolved_at'] = gmdate('Y-m-d H:i:sP');
        }
        $id = (int) $copy['id'];
        if ($fields !== []) $this->copies->update($id, $fields);
        if (!$forward) return false;

        $email = self::text($copy['email'] ?? '');
        $account = self::text($copy['account'] ?? '');
        $details = ['delivery_id' => $id, 'campaign_id' => (int) ($copy['campaign_id'] ?? 0)];
        if ($status === 'bounced') {
            $this->suppressions->suppress($email, 'hard_bounce', $detail !== '' ? $detail : 'The receiving server said the address does not exist.', $id);
            $this->log('mailing.hard_bounce', $details, $account);
        }
        if ($status === 'complained') {
            $this->suppressions->suppress($email, 'complaint', $detail !== '' ? $detail : 'Marked an email as spam.', $id);
            if ($account !== '') $this->consents->unsubscribeAll($account, 'complaint');
            $this->copies->update($id, ['unsubscribed_at' => gmdate('Y-m-d H:i:sP')]);
            $this->log('mailing.complaint', $details, $account);
        }

        return true;
    }

    /**
     * A copy's unsubscribe link, or the email app's one-click button: stops the lists that brought the copy (a digest or an
     * update brought by several lists stops every one of them), or every list. Returns how many lists were stopped.
     *
     * @param array<string,mixed> $copy
     */
    public function unsubscribe(array $copy, bool $everything, string $source): int
    {
        $account = self::text($copy['account'] ?? '');
        if ($account === '') return 0;
        $content = is_array($copy['content'] ?? null) ? $copy['content'] : [];
        $lists = array_values(array_filter(array_map('intval', (array) ($content['lists'] ?? []))));
        if ($lists === [] && ($copy['list_id'] ?? null) !== null) $lists = [(int) $copy['list_id']];
        $stopped = 0;
        if ($everything || $lists === []) $stopped = $this->consents->unsubscribeAll($account, $source);
        else foreach ($lists as $listId) $stopped += $this->consents->unsubscribeList($account, $listId, $source) ? 1 : 0;
        if (($copy['unsubscribed_at'] ?? null) === null) $this->copies->update((int) $copy['id'], ['unsubscribed_at' => gmdate('Y-m-d H:i:sP')]);

        return $stopped;
    }

    /** @param array<string,mixed> $details */
    private function log(string $action, array $details, string $account): void
    {
        $this->activity?->record($action, $details, actor: $account !== '' && $account !== '0' ? new Actor($account, $account) : Actor::system());
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
