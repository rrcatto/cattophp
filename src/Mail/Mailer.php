<?php

declare(strict_types=1);

namespace CattoPHP\Mail;

use CattoPHP\Installation\Installation;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Settings\Settings;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;

/**
 * Sends email through the transport the installation is configured with (MAILER_DSN, which the Settings page
 * overrides): on a server its own Postfix, another SMTP server or a smarthost; on a development machine Mailpit, which
 * catches everything. A test email goes the same way as every other, so it proves what it says. The transport is made
 * by Symfony's own factory, so its events, the profiler and the mail assertions in a project's tests see every email.
 *
 * Every email comes from the From address and name in Settings, with the reply-to address when there is one. On a
 * demo site its subject starts with [DEMO] and its first line says the site is a demo.
 *
 * An email made from a template has the blocks `subject`, `text` and, optionally, `html`. A template with only words is
 * named `.txt.twig`, so nothing in it is escaped as HTML; one with an `html` block is `.html.twig`, and its `text` block
 * then writes `|raw` where a value may hold an ampersand.
 */
final class Mailer
{
    private ?TransportInterface $built = null;

    /** The mail server the built transport is for: one changed in Settings is used from the next email, in a long-running worker too. */
    private string $builtFor = '';

    public function __construct(
        private readonly Settings $settings,
        private readonly Installation $installation,
        private readonly SiteMode $siteMode,
        private readonly Environment $twig,
        private readonly ClockInterface $clock,
        private readonly ?TransportInterface $transport = null,
        private readonly ?Transport $transports = null,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @throws MailNotSent
     */
    public function sendTemplate(string $to, string $template, array $context = []): void
    {
        $context += ['installation' => $this->installation];
        try {
            $loaded = $this->twig->load($template);
            $email = (new Email())
                ->to($to)
                ->subject(trim($loaded->renderBlock('subject', $context)))
                ->text(trim($loaded->renderBlock('text', $context)) . "\n");
            if ($loaded->hasBlock('html', $context)) {
                $email->html($loaded->renderBlock('html', $context));
            }
        } catch (Throwable $drawing) {
            throw new MailNotSent(sprintf('The email could not be written: %s', $drawing->getMessage()), 0, $drawing);
        }
        $this->send($email);
    }

    /**
     * Sends an email after giving it its sender (unless it names its own), its reply-to address and, on a demo site, the
     * demo marks.
     *
     * @throws MailNotSent
     */
    public function send(Email $email): void
    {
        // An email that names its own sender keeps it (a reply sent from a project's own mailbox); every other comes from
        // the settings' sender.
        if ($email->getFrom() === []) {
            $from = $this->settings->get('MAIL_FROM_ADDRESS');
            if ($from === '') {
                throw new MailNotSent('No From address is set: set it in Settings, under Mail, or MAIL_FROM_ADDRESS in .env.');
            }
            $name = $this->settings->get('MAIL_FROM_NAME');
            $email->from(new Address($from, $name !== '' ? $name : $this->installation->name()));
        }
        $replyTo = $this->settings->get('MAIL_REPLY_TO');
        if ($replyTo !== '' && $email->getReplyTo() === []) {
            $email->replyTo($replyTo);
        }
        if ($this->siteMode->isDemo()) {
            $this->markDemo($email);
        }
        try {
            $this->transport()->send($email, Envelope::create($email));
        } catch (TransportExceptionInterface $failure) {
            throw new MailNotSent(sprintf('The mail server did not take the email: %s', $failure->getMessage()), 0, $failure);
        }
    }

    /**
     * A test email to $to, through the configured transport like every other email.
     *
     * @throws MailNotSent
     */
    public function sendTest(string $to): void
    {
        $name = $this->installation->name();
        $at = $this->installation->localTime($this->clock->now())->format('j F Y, H:i T');
        $this->send((new Email())
            ->to($to)
            ->subject(sprintf('%s: a test email', $name))
            ->text(sprintf("This is a test email from %s, sent on %s.\n\nIt came through the mail server %s is set up with, so its emails reach this address. There is nothing to do.\n", $name, $at, $name)));
    }

    private function markDemo(Email $email): void
    {
        $subject = (string) $email->getSubject();
        if (!str_starts_with($subject, SiteMode::EMAIL_PREFIX)) {
            $email->subject(SiteMode::EMAIL_PREFIX . $subject);
        }
        $notice = $this->siteMode->emailNotice();
        $text = $email->getTextBody();
        // A project may have put the notice in its own email design already: it is said once.
        if (is_string($text) && !str_contains($text, $notice)) {
            $email->text($notice . "\n\n" . $text);
        }
        $html = $email->getHtmlBody();
        if (is_string($html) && !str_contains($html, htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'))) {
            $paragraph = '<p><strong>' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</strong></p>';
            $email->html(preg_match('/<body[^>]*>/i', $html) === 1 ? (string) preg_replace('/(<body[^>]*>)/i', '$1' . $paragraph, $html, 1) : $paragraph . $html);
        }
    }

    private function transport(): TransportInterface
    {
        if ($this->transport !== null) {
            return $this->transport;
        }
        $dsn = $this->settings->get('MAILER_DSN');
        if ($dsn === '') {
            throw new MailNotSent('No mail server is set: set it in Settings, under Mail, or MAILER_DSN in .env.');
        }
        try {
            if ($this->built === null || $this->builtFor !== $dsn) {
                $this->built = $this->transports !== null ? $this->transports->fromString($dsn) : Transport::fromDsn($dsn);
                $this->builtFor = $dsn;
            }

            return $this->built;
        } catch (Throwable $unusable) {
            throw new MailNotSent('The mail server\'s address cannot be used: check it in Settings, under Mail.', 0, $unusable);
        }
    }
}
