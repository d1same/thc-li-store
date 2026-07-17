<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . strtolower(trim($email)));
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function encryptSecret(string $secret): string
    {
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('MFA secret encryption failed.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decryptSecret(string $encrypted): string
    {
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 29) {
            throw new RuntimeException('The MFA secret is not valid.');
        }
        $plain = openssl_decrypt(
            substr($data, 28),
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            substr($data, 0, 12),
            substr($data, 12, 16)
        );
        if ($plain === false) {
            throw new RuntimeException('The MFA secret could not be decrypted. Check APP_KEY.');
        }
        return $plain;
    }

    public static function recoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = '';
            for ($j = 0; $j < 12; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        }
        return $codes;
    }

    public static function recoveryHash(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        return hash_hmac('sha256', $normalized, self::encryptionKey());
    }

    private static function code(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function encryptionKey(): string
    {
        $key = (string) getenv('APP_KEY');
        if (strlen($key) < 32) {
            throw new RuntimeException('APP_KEY must be at least 32 characters before MFA can be enabled.');
        }
        return hash('sha256', $key . '|mfa', true);
    }

    private static function base32Encode(string $data): string
    {
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (unpack('C*', $data) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }
        return $output;
    }

    private static function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (str_split($value) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if ($index === false) {
                throw new RuntimeException('Invalid MFA secret.');
            }
            $buffer = ($buffer << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $output;
    }
}
