<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** A mail transport that keeps what it is given, for tests to read. */
final class RecordingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function last(): Email
    {
        $last = end($this->sent);
        if ($last === false) {
            throw new \RuntimeException('No email was sent.');
        }

        return $last;
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
