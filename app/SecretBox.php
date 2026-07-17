<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class SecretBox
{
    public static function encrypt(string $plainText, string $purpose): string
    {
        if ($plainText === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            self::key($purpose),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($cipherText === false) {
            throw new RuntimeException('The secret could not be encrypted.');
        }
        return base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $encrypted, string $purpose): string
    {
        if ($encrypted === '') {
            return '';
        }
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 29) {
            throw new RuntimeException('The saved secret is not valid.');
        }
        $plainText = openssl_decrypt(
            substr($data, 28),
            'aes-256-gcm',
            self::key($purpose),
            OPENSSL_RAW_DATA,
            substr($data, 0, 12),
            substr($data, 12, 16)
        );
        if ($plainText === false) {
            throw new RuntimeException('The saved secret could not be decrypted. Check APP_KEY.');
        }
        return $plainText;
    }

    private static function key(string $purpose): string
    {
        $appKey = (string) getenv('APP_KEY');
        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY must be at least 32 characters before secrets can be saved.');
        }
        return hash('sha256', $appKey . '|secret-box|' . $purpose, true);
    }
}
