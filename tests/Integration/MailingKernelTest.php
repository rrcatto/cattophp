<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Mailing\CattoMail\CattoMailConnection;
use CattoPHP\Mailing\CattoMail\CattoMailWebhook;
use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Mailing\MailingTransports;
use CattoPHP\Mailing\SmtpMailingTransport;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use ReflectionProperty;

/** The mailing module as a project gets it: wired from its configuration, beside Symfony's own mailer. */
final class MailingKernelTest extends DatabaseTestCase
{
    public function testAProjectGetsTheTransportsTheConnectionAndTheWebhookFromItsSettings(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), mailing: true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $smtp = $container->get(SmtpMailingTransport::class);
            self::assertInstanceOf(SmtpMailingTransport::class, $smtp);
            self::assertNull((new ReflectionProperty(SmtpMailingTransport::class, 'transport'))->getValue($smtp), 'The mail server is the settings\' own, never the project\'s mailer transport.');
            $transports = $container->get(MailingTransports::class);
            self::assertInstanceOf(MailingTransports::class, $transports);
            self::assertSame('smtp', $transports->current()->name());
            $settings = $container->get(MailingSettings::class);
            self::assertInstanceOf(MailingSettings::class, $settings);
            self::assertSame('environment', $settings->source('from_address'));
            $connection = $container->get(CattoMailConnection::class);
            self::assertInstanceOf(CattoMailConnection::class, $connection);
            self::assertFalse($connection->connected());
            $webhook = $container->get(CattoMailWebhook::class);
            self::assertInstanceOf(CattoMailWebhook::class, $webhook);
            self::assertSame([503, ['error' => 'not configured']], $webhook->answer('', '{}'), 'Nothing is taken before catto-mail is connected.');
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }
}
