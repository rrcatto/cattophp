<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use Symfony\Component\Mime\Email;

/** How a reply is sent: CattoPHP's mailer by default (MailerReplySender), or a project's own (`inbox.sender`). */
interface ReplySender
{
    public function send(Email $email): void;
}
