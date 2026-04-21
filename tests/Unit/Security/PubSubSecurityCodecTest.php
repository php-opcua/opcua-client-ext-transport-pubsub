<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityCodec;

describe('PubSubSecurityCodec::sign and verify', function () {

    it('HMAC-SHA256 round-trips', function () {
        $key = str_repeat("\x01", 32);
        $body = 'body-bytes-of-a-network-message';

        $sig = PubSubSecurityCodec::sign($body, $key);
        expect(strlen($sig))->toBe(32);
        expect(PubSubSecurityCodec::verify($body, $sig, $key))->toBeTrue();
    });

    it('rejects a tampered body', function () {
        $key = str_repeat("\x01", 32);
        $sig = PubSubSecurityCodec::sign('original', $key);

        expect(PubSubSecurityCodec::verify('tampered', $sig, $key))->toBeFalse();
    });

    it('rejects a signature of the wrong length', function () {
        $key = str_repeat("\x01", 32);

        expect(PubSubSecurityCodec::verify('body', str_repeat("\x00", 16), $key))->toBeFalse();
    });
});

describe('PubSubSecurityCodec::encryptCtr / decryptCtr — AES-256-CTR', function () {

    it('round-trips arbitrary-length plaintext (not a multiple of 16)', function () {
        $keyNonce = str_repeat("\x03", PubSubSecurityCodec::KEY_NONCE_LENGTH);
        $messageNonce = str_repeat("\x04", PubSubSecurityCodec::MESSAGE_NONCE_LENGTH);
        $encryptingKey = str_repeat("\x02", 32);

        $plaintext = 'arbitrary-length-plaintext-that-is-not-multiple-of-16-bytes-and-includes-utf-8-€';
        $cipher = PubSubSecurityCodec::encryptCtr($plaintext, $keyNonce, $messageNonce, $encryptingKey);

        expect(strlen($cipher))->toBe(strlen($plaintext));
        expect($cipher)->not->toBe($plaintext);

        $decrypted = PubSubSecurityCodec::decryptCtr($cipher, $keyNonce, $messageNonce, $encryptingKey);
        expect($decrypted)->toBe($plaintext);
    });

    it('produces different ciphertext for different MessageNonces', function () {
        $keyNonce = str_repeat("\x03", 4);
        $key = str_repeat("\x02", 32);

        $c1 = PubSubSecurityCodec::encryptCtr('same-plaintext', $keyNonce, str_repeat("\xAA", 8), $key);
        $c2 = PubSubSecurityCodec::encryptCtr('same-plaintext', $keyNonce, str_repeat("\xBB", 8), $key);

        expect($c1)->not->toBe($c2);
    });

    it('rejects a KeyNonce of the wrong length', function () {
        $key = str_repeat("\x02", 32);

        expect(fn () => PubSubSecurityCodec::encryptCtr('x', 'short', str_repeat("\x04", 8), $key))
            ->toThrow(PubSubSecurityException::class, 'KeyNonce');
    });

    it('rejects a MessageNonce of the wrong length', function () {
        $key = str_repeat("\x02", 32);

        expect(fn () => PubSubSecurityCodec::encryptCtr('x', str_repeat("\x03", 4), 'nope', $key))
            ->toThrow(PubSubSecurityException::class, 'MessageNonce');
    });

    it('rejects an encrypting key that is not 16 or 32 bytes', function () {
        expect(fn () => PubSubSecurityCodec::encryptCtr(
            'x',
            str_repeat("\x03", 4),
            str_repeat("\x04", 8),
            str_repeat("\x02", 24),
        ))->toThrow(PubSubSecurityException::class, 'Unsupported encrypting-key length');
    });
});

describe('PubSubSecurityCodec — AES-128-CTR', function () {

    it('accepts a 16-byte encrypting key (AES-128-CTR)', function () {
        $plaintext = 'aes128-plaintext';
        $cipher = PubSubSecurityCodec::encryptCtr(
            $plaintext,
            str_repeat("\x03", 4),
            str_repeat("\x04", 8),
            str_repeat("\x02", 16),
        );

        $decrypted = PubSubSecurityCodec::decryptCtr(
            $cipher,
            str_repeat("\x03", 4),
            str_repeat("\x04", 8),
            str_repeat("\x02", 16),
        );

        expect($decrypted)->toBe($plaintext);
    });
});

describe('PubSubSecurityCodec::newMessageNonce', function () {

    it('returns 8 bytes by default', function () {
        expect(strlen(PubSubSecurityCodec::newMessageNonce()))->toBe(8);
    });

    it('returns requested length', function () {
        expect(strlen(PubSubSecurityCodec::newMessageNonce(16)))->toBe(16);
    });
});
