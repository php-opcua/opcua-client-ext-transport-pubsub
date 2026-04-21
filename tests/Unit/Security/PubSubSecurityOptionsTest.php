<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Security\StaticGroupKeyProvider;

describe('PubSubSecurityOptions — construction', function () {

    it('requires a key provider for Sign mode', function () {
        expect(fn () => new PubSubSecurityOptions(PubSubSecurityMode::Sign))
            ->toThrow(PubSubSecurityException::class, 'require a GroupKeyProviderInterface');
    });

    it('requires a key provider for SignAndEncrypt mode', function () {
        expect(fn () => new PubSubSecurityOptions(PubSubSecurityMode::SignAndEncrypt))
            ->toThrow(PubSubSecurityException::class, 'require a GroupKeyProviderInterface');
    });

    it('accepts None without a key provider', function () {
        $opts = new PubSubSecurityOptions(PubSubSecurityMode::None);
        expect($opts->mode)->toBe(PubSubSecurityMode::None);
        expect($opts->keyProvider)->toBeNull();
    });

    it('exposes the key provider when configured', function () {
        $provider = new StaticGroupKeyProvider(
            signingKey: str_repeat("\x01", 32),
            encryptingKey: str_repeat("\x02", 32),
            keyNonce: str_repeat("\x03", 4),
        );
        $opts = new PubSubSecurityOptions(PubSubSecurityMode::SignAndEncrypt, $provider);

        expect($opts->mode)->toBe(PubSubSecurityMode::SignAndEncrypt);
        expect($opts->keyProvider)->toBe($provider);
    });
});
