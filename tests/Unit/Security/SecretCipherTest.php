<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Security;

use CattoPHP\Security\KeyedHash;
use CattoPHP\Security\SecretCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretCipherTest extends TestCase
{
    private const KEY = 'cattophp-test-key-not-used-anywhere';

    /** Encrypted by cattolms 1.1.2's own cipher under KEY: what CattoPHP must go on reading. */
    private const STORED_BY_CATTOLMS = 'v1:4ylS/+YDo+irabl1c3a4LE0XPv9N0Kjo0Zl0CKE/l17oKXJu3ts4lAwHpg==';

    public function testASecretComesBackAsItWent(): void
    {
        $cipher = new SecretCipher(self::KEY);
        $stored = $cipher->encrypt('a stored secret');
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('a stored secret', $stored);
        self::assertNotSame($stored, $cipher->encrypt('a stored secret'), 'Each encryption has its own IV.');
        self::assertSame('a stored secret', $cipher->decrypt($stored));
        self::assertSame('', $cipher->encrypt(''), 'Nothing stays nothing.');
        self::assertSame('', $cipher->decrypt(''));
    }

    public function testWhatCattolmsStoredStillReads(): void
    {
        self::assertSame('a stored secret', (new SecretCipher(self::KEY))->decrypt(self::STORED_BY_CATTOLMS));
    }

    public function testAnotherKeyCannotReadIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the application key is not the one it was saved with');
        (new SecretCipher('another key'))->decrypt(self::STORED_BY_CATTOLMS);
    }

    public function testADamagedOrForeignValueIsRefusedInWords(): void
    {
        $cipher = new SecretCipher(self::KEY);
        foreach (['plain text' => 'not in a form this site writes', 'v1:bm90IGVub3VnaA==' => 'damaged', 'v1:***' => 'damaged'] as $value => $words) {
            try {
                $cipher->decrypt($value);
                self::fail($value . ' is refused.');
            } catch (RuntimeException $refused) {
                self::assertStringContainsString($words, $refused->getMessage(), $value);
            }
        }
        $tampered = substr(self::STORED_BY_CATTOLMS, 0, -4) . 'AAA=';
        $this->expectException(RuntimeException::class);
        $cipher->decrypt($tampered);
    }

    public function testNoKeyNoSecrets(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');
        (new SecretCipher(''))->encrypt('anything');
    }

    public function testAKeyedHashIsKeyedAndNamesItsPurpose(): void
    {
        $hash = new KeyedHash(self::KEY);
        self::assertSame(64, strlen($hash->of('a purpose', 'a message')));
        self::assertSame($hash->of('a purpose', 'a message'), $hash->of('a purpose', 'a message'));
        self::assertNotSame($hash->of('a purpose', 'a message'), $hash->of('another purpose', 'a message'), 'One purpose never matches another.');
        self::assertNotSame($hash->of('a purpose', 'a message'), (new KeyedHash('another key'))->of('a purpose', 'a message'));
        self::assertNotSame(hash('sha256', 'a message'), $hash->of('a purpose', 'a message'));
        self::assertTrue($hash->matches('a purpose', 'a message', $hash->of('a purpose', 'a message')));
        self::assertFalse($hash->matches('a purpose', 'another message', $hash->of('a purpose', 'a message')));
    }
}
