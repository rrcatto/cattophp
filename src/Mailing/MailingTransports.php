<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use CattoPHP\Mailing\CattoMail\CattoMailTransport;

/** The transport copies leave by, as Settings > Mailing chooses (MAILING_TRANSPORT): a mail server or catto-mail. */
final class MailingTransports
{
    public function __construct(
        private readonly MailingSettings $settings,
        private readonly MailingTransport $smtp,
        private readonly CattoMailTransport $cattomail,
    ) {
    }

    public function current(): MailingTransport
    {
        return $this->settings->transport() === 'cattomail' ? $this->cattomail : $this->smtp;
    }

    public function cattomail(): CattoMailTransport
    {
        return $this->cattomail;
    }
}
