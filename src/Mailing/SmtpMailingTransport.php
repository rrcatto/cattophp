<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use CattoPHP\Settings\Settings;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Copies through a mail server: Settings > Mailing's mail server (MAILING_DSN), else the site's own (MAILER_DSN; Mailpit on
 * a development machine). A mail server adds no bulk-mail headers itself, so each copy carries them: List-Unsubscribe with
 * its unsubscribe link and List-Unsubscribe-Post for the email app's one-click button (RFC 8058), List-Id naming its list,
 * and Feedback-ID naming the mailing, its kind and the project.
 */
final class SmtpMailingTransport implements MailingTransport
{
    public function __construct(
        private readonly MailingSettings $mailing,
        private readonly Settings $settings,
        private readonly string $project,
        private readonly ?RecipientAddresses $addresses = null,
        private readonly ?TransportInterface $transport = null,
    ) {
    }

    /** The mail server Settings name: Mailing's, else the site's. */
    private function built(): TransportInterface
    {
        $dsn = $this->mailing->value('dsn') !== '' ? $this->mailing->value('dsn') : trim($this->settings->get('MAILER_DSN'));
        if ($dsn === '') throw new TransportRefused('No mail server is set: set it in Settings, under Mailing or Mail.');
        try {
            return Transport::fromDsn($dsn);
        } catch (Throwable $broken) {
            throw new TransportRefused('The mail server setting cannot be used: ' . $broken->getMessage());
        }
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(string $mailing, string $kind, SenderIdentity $sender, array $copies): SendResult
    {
        $transport = $this->transport ?? $this->built();
        $host = (string) (parse_url($copies[0]->unsubscribeUrl ?? '', PHP_URL_HOST) ?: 'localhost');
        $handed = [];
        $failed = [];
        foreach ($copies as $copy) {
            $email = (new Email())
                ->from(new Address($sender->email, $sender->name))
                ->replyTo(new Address($sender->replyTo !== '' ? $sender->replyTo : $sender->email))
                ->to($this->addresses?->deliveryAddress($copy->to) ?? $copy->to)
                ->subject($copy->subject)
                ->html($copy->html)
                ->text($copy->text);
            $headers = $email->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<' . $copy->unsubscribeUrl . '>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $headers->addTextHeader('List-Id', '"' . str_replace('"', '', $copy->listName) . '" <list-' . $copy->listId . '.' . $host . '>');
            $headers->addTextHeader('Feedback-ID', $mailing . ':' . $kind . ':' . $this->project);
            try {
                $sent = $transport->send($email);
                $handed[$copy->id] = ['job_id' => null, 'message_id' => $sent?->getMessageId()];
            } catch (TransportExceptionInterface $problem) {
                // Nothing was handed yet: the server itself is not there, so everything waits for the next run.
                if ($handed === [] && $failed === []) throw new TransportUnavailable('The mail server did not answer: ' . $problem->getMessage());
                $failed[$copy->id] = 'The mail server refused it: ' . $problem->getMessage();
            }
        }

        return new SendResult($handed, $failed);
    }
}
