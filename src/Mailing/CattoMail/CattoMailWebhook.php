<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Webhooks\WebhookSignature;
use Symfony\Component\Clock\ClockInterface;

/**
 * The answer to a request on the project's catto-mail webhook address: at most 1 MiB, signed (Smarthost-Signature) with
 * the webhook secret or the one before it, an event with an id and a type. A good event is kept once and the waiting
 * events are worked through at once (20 of them); the rest wait for the next run. The project's controller passes the
 * signature header and the body, and sends back the status and the JSON this gives.
 */
final class CattoMailWebhook
{
    public const SIGNATURE_HEADER = 'Smarthost-Signature';
    private const LARGEST = 1048576;

    public function __construct(private readonly MailingSettings $settings, private readonly CattoMailEvents $events, private readonly ClockInterface $clock)
    {
    }

    /** @return array{0:int,1:array<string,mixed>} the status and the JSON body to answer with */
    public function answer(string $signature, string $body): array
    {
        if (strlen($body) > self::LARGEST) return [413, ['error' => 'too large']];
        $secrets = array_values(array_filter([$this->settings->value('cattomail_secret'), $this->settings->value('cattomail_secret_previous')]));
        if ($secrets === []) return [503, ['error' => 'not configured']];
        if (!WebhookSignature::valid($signature, $body, $secrets, $this->clock->now()->getTimestamp())) return [401, ['error' => 'bad signature']];
        $event = json_decode($body, true);
        if (!is_array($event) || !is_string($event['id'] ?? null) || $event['id'] === '' || !is_string($event['type'] ?? null)) return [400, ['error' => 'not an event']];
        $this->events->receive($event['id'], $event['type'], $body);
        $this->events->process(20);

        return [200, ['ok' => true]];
    }
}
