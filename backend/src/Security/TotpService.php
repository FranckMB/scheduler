<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/** RFC 6238 SHA-1 TOTP compatible with Google Authenticator. */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly string $appSecret) {}

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0', \STR_PAD_RIGHT))];
        }

        return $secret;
    }

    public function verifyEncrypted(string $encryptedSecret, string $code, ?int $timestamp = null): bool
    {
        if (1 !== preg_match('/^\d{6}$/D', $code)) {
            return false;
        }
        $secret = $this->decrypt($encryptedSecret);
        $timestamp ??= time();
        for ($offset = -1; $offset <= 1; ++$offset) {
            if (hash_equals($this->code($secret, $timestamp + 30 * $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function code(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, 30);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($secret), true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1_000_000), 6, '0', \STR_PAD_LEFT);
    }

    public function encrypt(string $secret): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $this->key(), \OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $cipher) {
            throw new RuntimeException('Unable to encrypt TOTP secret.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function provisioningUri(string $email, string $secret): string
    {
        $label = rawurlencode('ClubScheduler:' . strtolower($email));

        return "otpauth://totp/{$label}?secret={$secret}&issuer=ClubScheduler&algorithm=SHA1&digits=6&period=30";
    }

    private function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        if (false === $decoded || \strlen($decoded) < 29) {
            throw new RuntimeException('Invalid encrypted TOTP secret.');
        }
        $plain = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', $this->key(), \OPENSSL_RAW_DATA, substr($decoded, 0, 12), substr($decoded, 12, 16));
        if (false === $plain) {
            throw new RuntimeException('Unable to decrypt TOTP secret.');
        }

        return $plain;
    }

    private function key(): string
    {
        return hash('sha256', $this->appSecret, true);
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if (false === $position) {
                throw new RuntimeException('Invalid TOTP secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', \STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $result .= \chr((int) bindec($chunk));
            }
        }

        return $result;
    }
}
