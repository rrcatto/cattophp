<?php

declare(strict_types=1);

namespace CattoPHP\Security;

use RuntimeException;

/**
 * Encrypts a secret before it is stored (a key or password saved in Settings) and decrypts it when it is needed:
 * AES-256-GCM under a key derived from the application's key. The stored form is "v1:" and base64 of the IV, the tag
 * and the ciphertext; it is the form cattolms has stored since it began, so its secrets read back unchanged. Changing
 * the application's key makes every stored secret unreadable.
 */
final class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const PREFIX = 'v1:';

    public function __construct(private readonly string $applicationKey)
    {
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if (!is_string($ciphertext)) {
            throw new RuntimeException('The secret could not be encrypted.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException('The stored secret is not in a form this site writes.');
        }
        $payload = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('The stored secret is damaged.');
        }
        $iv = substr($payload, 0, self::IV_BYTES);
        $tag = substr($payload, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, self::IV_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plaintext)) {
            throw new RuntimeException('The stored secret could not be decrypted: the application key is not the one it was saved with.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        if ($this->applicationKey === '') {
            throw new RuntimeException('The application key (APP_KEY) must be set before a secret can be stored.');
        }

        return hash('sha256', $this->applicationKey, true);
    }
}
