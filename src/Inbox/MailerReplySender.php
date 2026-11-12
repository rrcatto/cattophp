<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use CattoPHP\Mail\Mailer;
use Symfony\Component\Mime\Email;

/** Replies sent through CattoPHP's mailer, which keeps the reply's own sender and adds the demo marks on a demo site. */
final class MailerReplySender implements ReplySender
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function send(Email $email): void
    {
        $this->mailer->send($email);
    }
}
