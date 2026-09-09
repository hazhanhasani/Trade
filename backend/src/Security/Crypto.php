<?php

declare(strict_types=1);

namespace Trade\Security;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plaintext, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Invalid application encryption key.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('Unable to encrypt secret.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload, string $base64Key): string
    {
        $raw = base64_decode($payload, true);
        $key = base64_decode($base64Key, true);
        if ($raw === false || $key === false || strlen($key) !== 32 || strlen($raw) < 29) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt secret.');
        }

        return $plaintext;
    }
}
