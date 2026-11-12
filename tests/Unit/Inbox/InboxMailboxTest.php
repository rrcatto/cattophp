<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Inbox;

use CattoPHP\Inbox\MimeMessage;
use CattoPHP\Tests\Support\ScriptedMailbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** An answer read from the mailbox and the IMAP conversation, ported from cattolms's InboxMailboxTest with every case it covers. */
final class InboxMailboxTest extends TestCase
{
    private const ANSWER = "Return-Path: <thandeka@example.org>\r\n"
        . "From: =?UTF-8?B?VGhhbmRla2EgTW9rb2VuYQ==?= <Thandeka@Example.org>\r\n"
        . "To: support@example.com\r\n"
        . "Subject: Re: Accounting issue [MSG-000047]\r\n"
        . "Message-ID: <answer-1@mail.example.org>\r\n"
        . "In-Reply-To: <inbox.6f1c2a00-1111-4222-8333-944445555666@example.com>\r\n"
        . "References: <first@example.com>\r\n <inbox.6f1c2a00-1111-4222-8333-944445555666@example.com>\r\n"
        . "Date: Wed, 11 Nov 2026 15:05:00 +0200\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/mixed; boundary=\"outer\"\r\n"
        . "\r\n"
        . "--outer\r\n"
        . "Content-Type: multipart/alternative; boundary=inner\r\n"
        . "\r\n"
        . "--inner\r\n"
        . "Content-Type: text/plain; charset=\"iso-8859-1\"\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n"
        . "\r\n"
        . "Thank you, that works. Caf=E9 receipts =\r\nare fine.\r\n"
        . "--inner\r\n"
        . "Content-Type: text/html; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "\r\n"
        . "PHA+VGhhbmsgeW91LCB0aGF0IHdvcmtzLjwvcD4=\r\n"
        . "--inner--\r\n"
        . "--outer\r\n"
        . "Content-Type: application/pdf; name=\"vat-certificate.pdf\"\r\n"
        . "Content-Disposition: attachment; filename=\"vat-certificate.pdf\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "\r\n"
        . "JVBERi0xLjQK\r\n"
        . "--outer--\r\n";

    public function testAnAnswerIsReadFromItsParts(): void
    {
        $email = MimeMessage::parse(self::ANSWER);
        self::assertSame(['Thandeka Mokoena', 'thandeka@example.org'], [$email->fromName, $email->fromEmail]);
        self::assertSame('Re: Accounting issue [MSG-000047]', $email->subject);
        self::assertSame('answer-1@mail.example.org', $email->messageId);
        self::assertSame('inbox.6f1c2a00-1111-4222-8333-944445555666@example.com', $email->answers[0], 'The message it answers first.');
        self::assertContains('first@example.com', $email->answers);
        self::assertSame("Thank you, that works. Café receipts are fine.", $email->text, 'The plain text part, decoded to UTF-8.');
        self::assertSame(['vat-certificate.pdf'], $email->attachments);
        self::assertSame('2026-11-11T13:05:00+00:00', (new \DateTimeImmutable((string) $email->date))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertFalse($email->automatic);

        $html = MimeMessage::parse("From: Ann <ann@example.org>\nSubject: Hi\nContent-Type: text/html; charset=utf-8\n\n<p>Hello <a href=\"https://example.org/x\">there</a></p><p>Line two</p>");
        self::assertSame("Hello there (https://example.org/x)\nLine two", $html->text, 'With only HTML, its words as text.');
        self::assertTrue(MimeMessage::parse("From: a@example.org\nAuto-Submitted: auto-replied\nSubject: Out of office\n\nAway.")->automatic, 'An automatic reply is known for one.');
    }

    public function testTheMailboxIsReadOverImapWithoutChangingIt(): void
    {
        $settings = ['host' => 'mail.example.org', 'port' => 993, 'security' => 'ssl', 'user' => 'support@example.org', 'password' => 'secret "quoted"'];
        $examine = "* 3 EXISTS\r\n* OK [UIDVALIDITY 777] UIDs valid\r\n* OK [UIDNEXT 41] Predicted next UID\r\nC2 OK [READ-ONLY] EXAMINE completed\r\n";

        $first = new ScriptedMailbox("* OK IMAP ready\r\nC1 OK LOGIN completed\r\n" . $examine);
        self::assertSame(['messages' => [], 'state' => ['validity' => 777, 'last' => 40]], $first->newMessages($settings, []), 'The first time, it only notes where the mailbox stands.');
        self::assertStringContainsString('C1 LOGIN "support@example.org" "secret \\"quoted\\""', $first->sent());
        self::assertStringContainsString('C2 EXAMINE INBOX', $first->sent(), 'INBOX is opened read-only.');

        $raw = "Subject: Re: Other [MSG-000050]\r\nFrom: b@example.org\r\n\r\nYes please.\r\n";
        $next = new ScriptedMailbox("* OK IMAP ready\r\nC1 OK LOGIN completed\r\n" . $examine
            . "* SEARCH 40 41\r\nC3 OK SEARCH completed\r\n"
            . "* 3 FETCH (UID 41 BODY[] {" . strlen($raw) . "}\r\n" . $raw . ")\r\nC4 OK FETCH completed\r\n");
        $found = $next->newMessages($settings, ['validity' => 777, 'last' => 40]);
        self::assertSame([['uid' => 41, 'raw' => $raw]], $found['messages'], 'Only what is newer than the last one read.');
        self::assertSame(['validity' => 777, 'last' => 41], $found['state']);
        self::assertStringContainsString('C3 UID SEARCH UID 41:*', $next->sent());
        self::assertStringContainsString('C4 UID FETCH 41 (BODY.PEEK[])', $next->sent(), 'Fetched without marking it read.');

        $renumbered = new ScriptedMailbox("* OK IMAP ready\r\nC1 OK LOGIN completed\r\n" . str_replace('777', '901', $examine));
        self::assertSame([], $renumbered->newMessages($settings, ['validity' => 777, 'last' => 40])['messages'], 'A mailbox whose numbers started again is noted afresh, not read from the start.');

        $refused = new ScriptedMailbox("* OK IMAP ready\r\nC1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
        try {
            $refused->check($settings);
            self::fail('A refused password is said.');
        } catch (RuntimeException $failed) {
            self::assertSame('The mail server did not accept the mailbox user and password.', $failed->getMessage());
        }
        self::assertSame('Connected: 3 messages in the mailbox.', (new ScriptedMailbox("* OK IMAP ready\r\nC1 OK LOGIN completed\r\n" . $examine))->check($settings));
    }
}
