<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

use CattoPHP\Mailing\Copies;
use CattoPHP\Mailing\CopyOutcomes;

/**
 * What catto-mail's events mean for the copies of campaigns: message.hard_bounced and message.complained reach the copy by
 * its reference (its token) and are recorded at once (CopyOutcomes: the address is never emailed news again, and after a
 * complaint every list its person is on is stopped); send.completed and send.failed bring the job's copies forward for
 * the next reading of what became of them.
 */
final class CopyEvents implements CattoMailEventHandler
{
    public function __construct(private readonly Copies $copies, private readonly CopyOutcomes $outcomes)
    {
    }

    public function types(): array
    {
        return ['message.hard_bounced', 'message.complained', 'send.completed', 'send.failed'];
    }

    public function handle(string $type, array $event): string
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        if (in_array($type, ['message.hard_bounced', 'message.complained'], true)) {
            $message = is_array($data['message'] ?? null) ? $data['message'] : [];
            $copy = $this->copies->byToken(is_string($message['external_recipient_reference'] ?? null) ? $message['external_recipient_reference'] : '');
            if ($copy === null) return 'No copy here has that reference.';
            $happened = is_array($data['event'] ?? null) ? $data['event'] : [];
            $detail = '';
            foreach (['detail', 'reason', 'diagnostic'] as $key) {
                if (is_scalar($happened[$key] ?? null) && trim((string) $happened[$key]) !== '') { $detail = trim((string) $happened[$key]); break; }
            }
            $status = $type === 'message.hard_bounced' ? 'bounced' : 'complained';
            $this->outcomes->record($copy, $status, mb_substr($detail, 0, 400), is_scalar($message['id'] ?? null) ? (string) $message['id'] : '');

            return 'Recorded: ' . ($status === 'bounced' ? 'a hard bounce' : 'a spam complaint') . '.';
        }
        $job = is_scalar($data['id'] ?? null) ? (string) $data['id'] : '';
        if ($job !== '') $this->copies->jobNeedsReading($job);

        return 'Its copies are read back on the next run.';
    }
}
