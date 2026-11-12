<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Security;

use CattoPHP\Security\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testTheSecretsTextCarriesAreHidden(): void
    {
        $key = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAexample\n-----END RSA PRIVATE KEY-----";
        self::assertSame('Signing with ••••.', Redactor::text('Signing with ' . $key . '.'));
        self::assertSame('Sending through smtp://site:••••@mail.example.com:587 now.', Redactor::text('Sending through smtp://site:hunter2@mail.example.com:587 now.'));
        self::assertSame('pdo-pgsql://app:••••@127.0.0.1:5432/app', Redactor::text('pdo-pgsql://app:s3cret@127.0.0.1:5432/app'));
        self::assertSame('password=•••• and api_key: •••• and "client_secret": "••••"', Redactor::text('password=hunter2 and api_key: abc123 and "client_secret": "def456"'));
        self::assertSame('STITCH_CLIENT_SECRET=•••• MAIL_TOKEN=••••', Redactor::text('STITCH_CLIENT_SECRET=xyz MAIL_TOKEN=tok_1'));
        self::assertSame('Authorization: Bearer ••••', Redactor::text('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.e30.abc'));
        self::assertSame('https://example.com/open?token=••••&page=2', Redactor::text('https://example.com/open?token=a1b2c3&page=2'));
    }

    public function testOrdinaryWordsAreLeftAlone(): void
    {
        foreach ([
            'Set: Example Learning.',
            'Offered: eft, card. The EFT bank account is not complete.',
            'Put DIDIT_API_KEY and DIDIT_WEBHOOK_SECRET in .env and enter the webhook address.',
            'Running: last run 10:42 today.',
            'https://example.com/courses/fin-101',
        ] as $words) {
            self::assertSame($words, Redactor::text($words));
        }
    }

    public function testValuesNamedAsSecretsAreHiddenWhole(): void
    {
        self::assertSame([
            'password' => '••••',
            'nested' => ['access_token' => '••••', 'note' => 'password=••••', 'count' => 3],
            'transport' => 'smtp://user:••••@mail.example.com',
            'empty_password' => '',
            'secret_changed' => true,
            'name' => 'kept',
        ], Redactor::data([
            'password' => 'hunter2',
            'nested' => ['access_token' => 'abc', 'note' => 'password=hunter2', 'count' => 3],
            'transport' => 'smtp://user:hunter2@mail.example.com',
            'empty_password' => '',
            'secret_changed' => true,
            'name' => 'kept',
        ]));
    }
}
