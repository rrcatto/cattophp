<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Mail;

use CattoPHP\Mail\MailNotSent;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

final class MailerTest extends TestCase
{
    public function testEveryEmailComesFromTheSettingsSender(): void
    {
        $site = new Foundations(['APP_SITE_MODE' => 'live', 'MAIL_REPLY_TO' => 'help@example.com']);
        $site->mailer->send((new Email())->to('ada@example.org')->subject('Hello')->text('Words.'));

        $email = $site->transport->last();
        self::assertSame('"Example Site" <site@example.com>', $email->getFrom()[0]->toString());
        self::assertSame('help@example.com', $email->getReplyTo()[0]->getAddress());
        self::assertSame('Hello', $email->getSubject());
        self::assertSame('Words.', $email->getTextBody());
    }

    public function testADemoSitesEmailSaysSo(): void
    {
        $site = new Foundations();
        $site->mailer->send((new Email())->to('ada@example.org')->subject('Hello')->text('Words.')->html('<html><body><p>Words.</p></body></html>'));

        $email = $site->transport->last();
        self::assertSame('[DEMO] Hello', $email->getSubject());
        self::assertStringStartsWith($site->siteMode->emailNotice() . "\n\nWords.", (string) $email->getTextBody());
        self::assertStringContainsString('<body><p><strong>' . htmlspecialchars($site->siteMode->emailNotice()) . '</strong></p><p>Words.</p>', (string) $email->getHtmlBody());

        // An email whose own design already says it: said once, the subject's [DEMO] too.
        $notice = $site->siteMode->emailNotice();
        $site->mailer->send((new Email())->to('ada@example.org')->subject('[DEMO] Again')->text($notice . "\n\nWords.")
            ->html('<html><body><div class="banner">' . htmlspecialchars($notice) . '</div><p>Words.</p></body></html>'));
        $again = $site->transport->last();
        self::assertSame('[DEMO] Again', $again->getSubject());
        self::assertSame(1, substr_count((string) $again->getTextBody(), $notice));
        self::assertSame(1, substr_count((string) $again->getHtmlBody(), htmlspecialchars($notice)));
    }

    public function testATestEmailGoesTheWayEveryOtherDoes(): void
    {
        $site = new Foundations(['APP_SITE_MODE' => 'live', 'APP_TIMEZONE' => 'Africa/Johannesburg']);
        $site->mailer->sendTest('owner@example.com');

        $email = $site->transport->last();
        self::assertSame('Example Site: a test email', $email->getSubject());
        self::assertStringContainsString('9 November 2026, 12:00', (string) $email->getTextBody());
    }

    public function testNoSenderIsSaidPlainly(): void
    {
        $site = new Foundations(['MAIL_FROM_ADDRESS' => ' ']);

        $this->expectException(MailNotSent::class);
        $this->expectExceptionMessage('No From address is set');
        $site->mailer->send((new Email())->to('ada@example.org')->subject('Hello')->text('Words.'));
    }

    public function testATemplateGivesTheSubjectAndWordsUnescaped(): void
    {
        $site = new Foundations(['APP_SITE_MODE' => 'live', 'APP_NAME' => 'Bits & Bobs']);
        $site->mailer->sendTemplate('ada@example.org', '@CattoPHP/email/sign_in_link.txt.twig', ['name' => 'Bits & Bobs', 'link' => 'https://example.com/sign-in/link/x?a=1&b=2', 'lifetime' => '30 minutes', 'two_step' => false]);

        $email = $site->transport->last();
        self::assertSame('Sign in to Bits & Bobs', $email->getSubject());
        self::assertStringContainsString('https://example.com/sign-in/link/x?a=1&b=2', (string) $email->getTextBody());
        self::assertStringContainsString('you can ignore this email', (string) $email->getTextBody());
    }

    public function testAMailServerChangedInSettingsIsUsedFromTheNextEmail(): void
    {
        $site = new Foundations(['MAILER_DSN' => 'null://first']);
        $mailer = new \CattoPHP\Mail\Mailer($site->settings, $site->installation, $site->siteMode, new \Twig\Environment(new \Twig\Loader\ArrayLoader()), $site->clock);
        $built = new \ReflectionProperty(\CattoPHP\Mail\Mailer::class, 'builtFor');
        $mailer->send((new Email())->to('ada@example.org')->subject('One')->text('Words.'));
        self::assertSame('null://first', $built->getValue($mailer));
        $site->settings->set('MAILER_DSN', 'null://second');
        $mailer->send((new Email())->to('ada@example.org')->subject('Two')->text('Words.'));
        self::assertSame('null://second', $built->getValue($mailer), 'the changed server, without a restart');
    }
}
