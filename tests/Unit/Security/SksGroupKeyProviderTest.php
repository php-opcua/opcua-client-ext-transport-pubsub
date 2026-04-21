<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;
use PhpOpcua\Client\ExtTransportPubSub\Security\SksGroupKeyProvider;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

function aes256Key(): string
{
    return str_repeat("\x11", 32) . str_repeat("\x22", 32) . str_repeat("\x33", 4);
}

function aes128Key(): string
{
    return str_repeat("\x44", 32) . str_repeat("\x55", 16) . str_repeat("\x66", 4);
}

function stubSksCall(string $policyUri, array $keys, float $timeToNext = 300000.0, float $lifetime = 600000.0, int $status = 0): callable
{
    return fn () => new CallResult(
        statusCode: $status,
        inputArgumentResults: [0, 0, 0],
        outputArguments: [
            new Variant(BuiltinType::String, $policyUri),
            new Variant(BuiltinType::UInt32, 7),
            new Variant(BuiltinType::ByteString, $keys),
            new Variant(BuiltinType::Double, $timeToNext),
            new Variant(BuiltinType::Double, $lifetime),
        ],
    );
}

describe('SksGroupKeyProvider::refresh — AES-256-CTR', function () {

    it('fetches and splits a 68-byte key into (signing, encrypting, nonce)', function () {
        $client = MockClient::create();
        $client->onCall('i=14443', 'i=15215', stubSksCall(
            policyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
            keys: [aes256Key()],
        ));

        $provider = new SksGroupKeyProvider($client, 'group-a');
        $provider->refresh();

        expect(strlen($provider->signingKey()))->toBe(32);
        expect(strlen($provider->encryptingKey()))->toBe(32);
        expect(strlen($provider->keyNonce()))->toBe(4);
        expect($provider->signingKey())->toBe(str_repeat("\x11", 32));
        expect($provider->encryptingKey())->toBe(str_repeat("\x22", 32));
        expect($provider->keyNonce())->toBe(str_repeat("\x33", 4));
        expect($provider->currentTokenId())->toBe(7);
    });

    it('reports remaining lifetime within expected bounds', function () {
        $client = MockClient::create();
        $client->onCall('i=14443', 'i=15215', stubSksCall(
            policyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
            keys: [aes256Key()],
            timeToNext: 60000.0,
            lifetime: 300000.0,
        ));

        $provider = new SksGroupKeyProvider($client, 'group-a');
        $provider->refresh();

        $remaining = $provider->lifetimeSecondsRemaining();
        expect($remaining)->toBeGreaterThan(59.0);
        expect($remaining)->toBeLessThanOrEqual(60.0);
    });
});

describe('SksGroupKeyProvider::refresh — AES-128-CTR', function () {

    it('accepts the shorter 52-byte key layout', function () {
        $client = MockClient::create();
        $client->onCall('i=14443', 'i=15215', stubSksCall(
            policyUri: SksGroupKeyProvider::POLICY_AES128_CTR,
            keys: [aes128Key()],
        ));

        $provider = new SksGroupKeyProvider(
            $client,
            'group-a',
            securityPolicyUri: SksGroupKeyProvider::POLICY_AES128_CTR,
        );
        $provider->refresh();

        expect(strlen($provider->signingKey()))->toBe(32);
        expect(strlen($provider->encryptingKey()))->toBe(16);
        expect(strlen($provider->keyNonce()))->toBe(4);
    });
});

describe('SksGroupKeyProvider — error paths', function () {

    it('throws when the method call returns a bad status', function () {
        $client = MockClient::create();
        $client->onCall('i=14443', 'i=15215', stubSksCall(
            policyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
            keys: [aes256Key()],
            status: 0x80010000,
        ));

        $provider = new SksGroupKeyProvider($client, 'group-a');

        expect(fn () => $provider->refresh())
            ->toThrow(PubSubSecurityException::class, 'bad status');
    });

    it('throws when the server returns an empty keys array', function () {
        $client = MockClient::create();
        $client->onCall('i=14443', 'i=15215', stubSksCall(
            policyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
            keys: [],
        ));

        $provider = new SksGroupKeyProvider($client, 'group-a');

        expect(fn () => $provider->refresh())
            ->toThrow(PubSubSecurityException::class, 'empty Keys array');
    });

    it('throws when the key is shorter than the policy layout expects', function () {
        $client = MockClient::create();
        $client->onCall('i=14443', 'i=15215', stubSksCall(
            policyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
            keys: [str_repeat("\x00", 10)],
        ));

        $provider = new SksGroupKeyProvider($client, 'group-a');

        expect(fn () => $provider->refresh())
            ->toThrow(PubSubSecurityException::class, '68-byte layout');
    });

    it('rejects an unsupported SecurityPolicyUri', function () {
        $client = MockClient::create();
        $client->onCall('i=14443', 'i=15215', stubSksCall(
            policyUri: 'http://opcfoundation.org/UA/SecurityPolicy#PubSub-Chacha20-Poly1305',
            keys: [aes256Key()],
        ));

        $provider = new SksGroupKeyProvider($client, 'group-a');

        expect(fn () => $provider->refresh())
            ->toThrow(PubSubSecurityException::class, 'unsupported SecurityPolicyUri');
    });

    it('requires refresh() before the key accessors are callable', function () {
        $provider = new SksGroupKeyProvider(MockClient::create(), 'group-a');

        expect(fn () => $provider->signingKey())
            ->toThrow(PubSubSecurityException::class, 'before refresh');
        expect(fn () => $provider->encryptingKey())
            ->toThrow(PubSubSecurityException::class, 'before refresh');
        expect(fn () => $provider->keyNonce())
            ->toThrow(PubSubSecurityException::class, 'before refresh');
    });
});
