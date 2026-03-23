<?php

namespace Php\LaravelObfuscator;

final class ObfuscationCodec
{
    private const VERSION = 1;

    private string $encKey;
    private string $macKey;

    public function __construct(string $keyMaterial)
    {
        if ($keyMaterial === '') {
            throw new \InvalidArgumentException('Key material is required');
        }

        $ikm = $this->normalizeKeyMaterial($keyMaterial);
        $salt = hash('sha256', 'Php-ident-obf-v1', true);

        $this->encKey = hash_hkdf('sha256', $ikm, 32, 'Php-ident-obf-enc', $salt);
        $this->macKey = hash_hkdf('sha256', $ikm, 32, 'Php-ident-obf-mac', $salt);
    }

    public function obfuscate(string $typePrefix, string $original): string
    {
        $iv = substr(hash_hmac('sha256', $typePrefix . ':' . $original, $this->encKey, true), 0, 16);

        $ciphertext = openssl_encrypt($original, 'aes-256-ctr', $this->encKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Identifier encryption failed');
        }

        $tag = substr(hash_hmac('sha256', $original, $this->macKey, true), 0, 8);
        $payload = pack('C', self::VERSION) . $iv . $tag . $ciphertext;

        return $typePrefix . $this->base32Encode($payload);
    }

    public function deobfuscate(string $typePrefix, string $obfuscated): ?string
    {
        if (!str_starts_with($obfuscated, $typePrefix)) {
            return null;
        }

        $b32 = substr($obfuscated, strlen($typePrefix));
        $raw = $this->base32Decode($b32);

        if ($raw === null || strlen($raw) < 1 + 16 + 8 + 1) {
            return null;
        }

        $ver = ord($raw[0]);
        if ($ver !== self::VERSION) {
            return null;
        }

        $iv = substr($raw, 1, 16);
        $tag = substr($raw, 17, 8);
        $ciphertext = substr($raw, 25);

        $plain = openssl_decrypt($ciphertext, 'aes-256-ctr', $this->encKey, OPENSSL_RAW_DATA, $iv);
        if (!is_string($plain) || $plain === '') {
            return null;
        }

        $expected = substr(hash_hmac('sha256', $plain, $this->macKey, true), 0, 8);
        if (!hash_equals($expected, $tag)) {
            return null;
        }

        return $plain;
    }

    private function normalizeKeyMaterial(string $keyMaterial): string
    {
        if (str_starts_with($keyMaterial, 'base64:')) {
            $raw = base64_decode(substr($keyMaterial, 7), true);
            return $raw === false ? $keyMaterial : $raw;
        }

        return $keyMaterial;
    }

    private function base32Encode(string $raw): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $bits = 0;
        $value = 0;
        $out = '';

        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $value = $value << 8 | ord($raw[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $idx = $value >> $bits - 5 & 31;
                $bits -= 5;
                $out .= $alphabet[$idx];
            }
        }

        if ($bits > 0) {
            $idx = $value << 5 - $bits & 31;
            $out .= $alphabet[$idx];
        }

        return $out;
    }

    private function base32Decode(string $b32): ?string
    {
        $b32 = strtolower($b32);
        $map = array_flip(str_split('abcdefghijklmnopqrstuvwxyz234567'));

        $bits = 0;
        $value = 0;
        $out = '';

        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $ch = $b32[$i];
            if (!isset($map[$ch])) {
                return null;
            }

            $value = $value << 5 | $map[$ch];
            $bits += 5;

            if ($bits >= 8) {
                $byte = $value >> $bits - 8 & 255;
                $bits -= 8;
                $out .= chr($byte);
            }
        }

        return $out;
    }
}

