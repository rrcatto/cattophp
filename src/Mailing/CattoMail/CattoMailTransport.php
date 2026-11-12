<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

use CattoPHP\Mailing\MailingTransport;
use CattoPHP\Mailing\SenderIdentity;
use CattoPHP\Mailing\SendResult;
use CattoPHP\Mailing\TransportRefused;
use CattoPHP\Mailing\TransportUnavailable;
use DateTimeZone;

/**
 * Copies through catto-mail. Each batch becomes one send job per list (catto-mail puts one List-Id on a job and adds the
 * List-Unsubscribe headers from each copy's unsubscribe link), its copies added 500 at a time with each copy's reference,
 * then submitted; catto-mail's own tracking is off, since the project tracks opens and clicks itself. Every request has an
 * idempotency key made from the mailing, the list and the copies, so a run interrupted and repeated sends nothing twice.
 * Nothing is sent while the sending domain cannot send or the quota is used up.
 */
final class CattoMailTransport implements MailingTransport
{
    /** catto-mail's message statuses, as a copy's status; those not listed are still on their way. */
    public const STATUSES = [
        'remote_accepted' => 'delivered',
        'deferred' => 'deferred',
        'soft_bounced' => 'soft_bounced',
        'hard_bounced' => 'bounced',
        'complained' => 'complained',
        'failed' => 'failed',
        'suppressed' => 'suppressed',
    ];

    public function __construct(private readonly CattoMailClient $client, private readonly string $project, private readonly ?CattoMailConnection $connection = null)
    {
    }

    public function name(): string
    {
        return 'cattomail';
    }

    public function send(string $mailing, string $kind, SenderIdentity $sender, array $copies): SendResult
    {
        if ($this->connection !== null) {
            $until = $this->connection->quotaUntil();
            if ($until !== null) throw new TransportUnavailable('catto-mail\'s quota is used up until ' . $until->format('j F \a\t H:i') . '.');
            if ($this->connection->connected() && !$this->connection->ready()) {
                throw new TransportUnavailable('catto-mail cannot send from ' . $this->connection->sendingDomain() . ' yet: finish its DNS steps on Mailing, catto-mail.');
            }
        }
        try {
            return $this->sendNow($mailing, $sender, $copies);
        } catch (QuotaExceeded $quota) {
            $this->connection?->quotaUsedUntil($quota->resetsAt, $quota->getMessage());

            throw new TransportUnavailable('catto-mail\'s quota is used up' . ($quota->resetsAt !== null ? ' until ' . $quota->resetsAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j F \a\t H:i') : '') . '.');
        }
    }

    /**
     * What became of each copy in a job: its reference, catto-mail's message id and its status (null while it is still on
     * its way).
     *
     * @return list<array{reference:string,message_id:string,status:?string}>
     */
    public function outcomes(string $jobId): array
    {
        $out = [];
        $cursor = null;
        do {
            $page = $this->client->messages($jobId, $cursor);
            foreach ($page['data'] as $message) {
                $status = is_scalar($message['current_status'] ?? null) ? (string) $message['current_status'] : '';
                $out[] = ['reference' => is_scalar($message['external_recipient_reference'] ?? null) ? (string) $message['external_recipient_reference'] : '',
                    'message_id' => is_scalar($message['id'] ?? null) ? (string) $message['id'] : '', 'status' => self::STATUSES[$status] ?? null];
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        return $out;
    }

    /** @param list<\CattoPHP\Mailing\OutgoingCopy> $copies */
    private function sendNow(string $mailing, SenderIdentity $sender, array $copies): SendResult
    {
        $handed = [];
        $byList = [];
        foreach ($copies as $copy) $byList[$copy->listId][] = $copy;
        foreach ($byList as $listId => $group) {
            $host = (string) (parse_url($group[0]->unsubscribeUrl, PHP_URL_HOST) ?: 'localhost');
            $reference = $this->project . '-c' . $mailing . '-l' . $listId . '-' . substr(hash('sha256', implode(',', array_map(static fn($copy): string => (string) $copy->id, $group))), 0, 24);
            $job = $this->client->createJob([
                'external_reference' => $reference,
                'message_class' => 'subscription',
                'sender_identity' => ['email' => $sender->email, 'name' => $sender->name],
                'tracking' => ['opens' => false, 'clicks' => false],
                'list_id' => mb_substr(str_replace(['<', '>', '"'], '', $group[0]->listName), 0, 150) . ' <list-' . $listId . '.' . $host . '>',
            ] + ($sender->replyTo !== '' ? ['reply_to' => ['email' => $sender->replyTo]] : []), $reference . '-job');
            $jobId = is_scalar($job['id'] ?? null) ? (string) $job['id'] : '';
            if ($jobId === '') throw new TransportRefused('catto-mail made no send job.');
            foreach (array_chunk($group, CattoMailClient::BATCH) as $index => $chunk) {
                $this->client->addRecipients($jobId, array_map(static fn($copy): array => [
                    'external_recipient_reference' => $copy->reference,
                    'email_address' => $copy->to,
                    'subject' => $copy->subject,
                    'html_body' => $copy->html,
                    'text_body' => $copy->text,
                    'unsubscribe_url' => $copy->unsubscribeUrl,
                ], $chunk), $reference . '-r' . $index);
            }
            $this->client->submit($jobId);
            foreach ($group as $copy) $handed[$copy->id] = ['job_id' => $jobId, 'message_id' => null];
        }

        return new SendResult($handed);
    }
}
