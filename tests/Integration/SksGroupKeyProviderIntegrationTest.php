<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;
use PhpOpcua\Client\ExtTransportPubSub\Security\SksGroupKeyProvider;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers\TestHelper;

describe('SksGroupKeyProvider — happy path against opcua-sks', function () {

    it('fetches group keys via GetSecurityKeys and splits them per policy', function () {
        $client = ClientBuilder::create()->connect(TestHelper::ENDPOINT_SKS);

        try {
            $provider = new SksGroupKeyProvider(
                client: $client,
                securityGroupId: TestHelper::SKS_GROUP_ID,
                objectNodeId: TestHelper::SKS_OBJECT_NODE_ID,
                methodNodeId: TestHelper::SKS_METHOD_NODE_ID,
            );

            $provider->refresh();

            expect($provider->tokenId())->toBe(TestHelper::SKS_EXPECTED_TOKEN_ID);
            expect(strlen($provider->signingKey()))->toBe(32);
            expect(strlen($provider->encryptingKey()))->toBe(32);
            expect(strlen($provider->keyNonce()))->toBe(4);
            expect($provider->signingKey())->toBe(str_repeat("\x01", 32));
            expect($provider->encryptingKey())->toBe(str_repeat("\x02", 32));
            expect($provider->keyNonce())->toBe("\x03\x03\x03\x03");
            expect($provider->lifetimeSecondsRemaining())->toBeGreaterThan(0.0);
        } finally {
            $client->disconnect();
        }
    })->group('integration');

    it('rejects an unknown securityGroupId', function () {
        $client = ClientBuilder::create()->connect(TestHelper::ENDPOINT_SKS);

        try {
            $provider = new SksGroupKeyProvider(
                client: $client,
                securityGroupId: 'this-group-does-not-exist',
                objectNodeId: TestHelper::SKS_OBJECT_NODE_ID,
                methodNodeId: TestHelper::SKS_METHOD_NODE_ID,
            );

            expect(fn () => $provider->refresh())->toThrow(PubSubSecurityException::class);
        } finally {
            $client->disconnect();
        }
    })->group('integration');
});

describe('SksGroupKeyProvider — error paths on a server without GetSecurityKeys', function () {

    it('throws when the target method is not implemented on the server', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $provider = new SksGroupKeyProvider(
                client: $client,
                securityGroupId: 'does-not-exist',
            );

            expect(fn () => $provider->refresh())->toThrow(PubSubSecurityException::class);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('leaves key accessors unusable when refresh() has not succeeded', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $provider = new SksGroupKeyProvider($client, 'g');

            expect(fn () => $provider->signingKey())
                ->toThrow(PubSubSecurityException::class, 'before refresh');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
