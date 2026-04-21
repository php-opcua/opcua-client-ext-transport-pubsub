<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Security;

use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;

/**
 * Low-level PubSub security primitives (Part 14 §8).
 *
 * AES-256-CTR payload encryption with counter block
 *   `KeyNonce(4) ‖ MessageNonce(8) ‖ BlockCounter(4, big-endian)`
 *
 * HMAC-SHA256 signature over the full NetworkMessage up to the signature
 * bytes (headers + SecurityHeader + payload + optional SecurityFooter).
 */
final class PubSubSecurityCodec
{
    public const SIGNATURE_LENGTH = 32;

    public const MESSAGE_NONCE_LENGTH = 8;

    public const KEY_NONCE_LENGTH = 4;

    /**
     * @param string $body
     * @param string $signingKey
     * @return string
     */
    public static function sign(string $body, string $signingKey): string
    {
        return hash_hmac('sha256', $body, $signingKey, binary: true);
    }

    /**
     * @param string $body
     * @param string $signature
     * @param string $signingKey
     * @return bool
     */
    public static function verify(string $body, string $signature, string $signingKey): bool
    {
        if (strlen($signature) !== self::SIGNATURE_LENGTH) {
            return false;
        }

        $expected = self::sign($body, $signingKey);

        return hash_equals($expected, $signature);
    }

    /**
     * @param string $plaintext
     * @param string $keyNonce
     * @param string $messageNonce
     * @param string $encryptingKey
     * @return string
     *
     * @throws PubSubSecurityException
     */
    public static function encryptCtr(
        string $plaintext,
        string $keyNonce,
        string $messageNonce,
        string $encryptingKey,
    ): string {
        $iv = self::counterIv($keyNonce, $messageNonce);
        $cipher = self::cipherFor($encryptingKey);

        $result = openssl_encrypt(
            $plaintext,
            $cipher,
            $encryptingKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );

        if ($result === false) {
            throw new PubSubSecurityException('AES-CTR encryption failed');
        }

        return $result;
    }

    /**
     * @param string $ciphertext
     * @param string $keyNonce
     * @param string $messageNonce
     * @param string $encryptingKey
     * @return string
     *
     * @throws PubSubSecurityException
     */
    public static function decryptCtr(
        string $ciphertext,
        string $keyNonce,
        string $messageNonce,
        string $encryptingKey,
    ): string {
        $iv = self::counterIv($keyNonce, $messageNonce);
        $cipher = self::cipherFor($encryptingKey);

        $result = openssl_decrypt(
            $ciphertext,
            $cipher,
            $encryptingKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );

        if ($result === false) {
            throw new PubSubSecurityException('AES-CTR decryption failed');
        }

        return $result;
    }

    /**
     * @param string $keyNonce
     * @param string $messageNonce
     * @return string
     *
     * @throws PubSubSecurityException
     */
    private static function counterIv(string $keyNonce, string $messageNonce): string
    {
        if (strlen($keyNonce) !== self::KEY_NONCE_LENGTH) {
            throw new PubSubSecurityException(
                'KeyNonce must be ' . self::KEY_NONCE_LENGTH . ' bytes (got ' . strlen($keyNonce) . ')',
            );
        }

        if (strlen($messageNonce) !== self::MESSAGE_NONCE_LENGTH) {
            throw new PubSubSecurityException(
                'MessageNonce must be ' . self::MESSAGE_NONCE_LENGTH . ' bytes (got ' . strlen($messageNonce) . ')',
            );
        }

        return $keyNonce . $messageNonce . "\x00\x00\x00\x00";
    }

    /**
     * @param string $encryptingKey
     * @return string
     *
     * @throws PubSubSecurityException
     */
    private static function cipherFor(string $encryptingKey): string
    {
        return match (strlen($encryptingKey)) {
            32 => 'aes-256-ctr',
            16 => 'aes-128-ctr',
            default => throw new PubSubSecurityException(
                'Unsupported encrypting-key length ' . strlen($encryptingKey) . ' (expected 16 for AES-128-CTR or 32 for AES-256-CTR)',
            ),
        };
    }

    /**
     * @param int $length
     * @return string
     */
    public static function newMessageNonce(int $length = self::MESSAGE_NONCE_LENGTH): string
    {
        return random_bytes($length);
    }
}
