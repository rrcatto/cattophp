<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/**
 * How copies of a mailing leave the site: through a mail server (SmtpMailingTransport) or through catto-mail
 * (CattoMail\CattoMailTransport). A transport hands on what it can; it throws TransportUnavailable when nothing could be
 * handed and the next run should try again, and TransportRefused when what sends them should pause until it is put right.
 */
interface MailingTransport
{
    /** smtp or cattomail, as a copy records it. */
    public function name(): string;

    /**
     * @param string $mailing the mailing's own id, as the project knows it (in references and the Feedback-ID)
     * @param string $kind what sort of mailing it is (in the Feedback-ID), such as message or digest
     * @param list<OutgoingCopy> $copies
     */
    public function send(string $mailing, string $kind, SenderIdentity $sender, array $copies): SendResult;
}
