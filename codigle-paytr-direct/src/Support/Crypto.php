<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Support;

use RuntimeException;

final class Crypto
{
    private const PREFIX = 'cdg1:';

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        if (! function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('Sodium extension is unavailable.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox(
            $plain,
            $nonce,
            $this->key()
        );

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        if (! str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException('Unsupported encrypted token format.');
        }

        $raw = base64_decode(
            substr($encoded, strlen(self::PREFIX)),
            true
        );

        if (
            ! is_string($raw)
            || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {
            throw new RuntimeException('Encrypted token is invalid.');
        }

        $nonce = substr(
            $raw,
            0,
            SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        );
        $cipher = substr(
            $raw,
            SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        );
        $plain = sodium_crypto_secretbox_open(
            $cipher,
            $nonce,
            $this->key()
        );

        if (! is_string($plain)) {
            throw new RuntimeException('Encrypted token could not be opened.');
        }

        return $plain;
    }

    public function hash(string $token): string
    {
        return hash_hmac(
            'sha256',
            $token,
            wp_salt('nonce')
        );
    }

    private function key(): string
    {
        return hash(
            'sha256',
            wp_salt('auth') . wp_salt('secure_auth'),
            true
        );
    }
}
