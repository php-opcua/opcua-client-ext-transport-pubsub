<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Event\SecurityValidationFailed;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Security\StaticGroupKeyProvider;
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers\PubSubTestPublisher;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Unit\Kernel\CollectingDispatcher;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;
use PhpOpcua\Client\Types\BuiltinType;

function secureProvider(int $tokenId = 1): StaticGroupKeyProvider
{
    return new StaticGroupKeyProvider(
        signingKey: str_repeat("\x01", 32),
        encryptingKey: str_repeat("\x02", 32),
        keyNonce: str_repeat("\x03", 4),
        tokenId: $tokenId,
    );
}

describe('Secured PubSub stream — Sign mode', function () {

    it('receives and verifies an HMAC-signed UADP payload', function () {
        $port = TestHelper::pickFreeLoopbackPort();
        $security = new PubSubSecurityOptions(PubSubSecurityMode::Sign, secureProvider());

        $subscriber = SubscriberBuilder::create()
            ->listenOn(
                transports: [new UdpTransport(
                    "opc.udp://127.0.0.1:{$port}",
                    new UdpOptions(interface: '127.0.0.1'),
                )],
                readers: [TestHelper::makeReader(100, 1, 7, [['val', BuiltinType::Int32]])],
                security: $security,
            );

        $publisher = PubSubTestPublisher::secured('127.0.0.1', $port, $security);
        try {
            $subscriber->poll(timeoutMs: 0);
            usleep(50_000);
            $publisher->sendVariant(100, 1, 7, ['val' => [BuiltinType::Int32, 42]]);

            $deadline = microtime(true) + 2.0;
            $messages = [];
            while (microtime(true) < $deadline && $messages === []) {
                $messages = $subscriber->poll(timeoutMs: 100);
            }

            expect($messages)->toHaveCount(1);
            expect($messages[0]->fields[0]->value->value)->toBe(42);
        } finally {
            $publisher->close();
            $subscriber->stop();
        }
    })->group('integration');

    it('drops payloads signed with the wrong key and dispatches SecurityValidationFailed', function () {
        $port = TestHelper::pickFreeLoopbackPort();
        $dispatcher = new CollectingDispatcher();

        $subscriber = SubscriberBuilder::create()
            ->setEventDispatcher($dispatcher)
            ->listenOn(
                transports: [new UdpTransport(
                    "opc.udp://127.0.0.1:{$port}",
                    new UdpOptions(interface: '127.0.0.1'),
                )],
                readers: [TestHelper::makeReader(100, 1, 7, [['val', BuiltinType::Int32]])],
                security: new PubSubSecurityOptions(PubSubSecurityMode::Sign, secureProvider()),
            );

        $attackerProvider = new StaticGroupKeyProvider(
            signingKey: str_repeat("\x99", 32),
            encryptingKey: str_repeat("\x99", 32),
            keyNonce: str_repeat("\x99", 4),
        );

        $publisher = PubSubTestPublisher::secured(
            '127.0.0.1',
            $port,
            new PubSubSecurityOptions(PubSubSecurityMode::Sign, $attackerProvider),
        );
        try {
            $subscriber->poll(timeoutMs: 0);
            usleep(50_000);
            $publisher->sendVariant(100, 1, 7, ['val' => [BuiltinType::Int32, 999]]);

            $deadline = microtime(true) + 2.0;
            $messages = [];
            while (microtime(true) < $deadline) {
                $messages = array_merge($messages, $subscriber->poll(timeoutMs: 100));
                if ($dispatcher->of(SecurityValidationFailed::class) !== []) {
                    break;
                }
            }

            expect($messages)->toBe([]);
            expect($dispatcher->of(SecurityValidationFailed::class))->not->toBe([]);
        } finally {
            $publisher->close();
            $subscriber->stop();
        }
    })->group('integration');
});

describe('Secured PubSub stream — SignAndEncrypt mode', function () {

    it('decrypts and verifies an AES-256-CTR + HMAC payload', function () {
        $port = TestHelper::pickFreeLoopbackPort();
        $security = new PubSubSecurityOptions(PubSubSecurityMode::SignAndEncrypt, secureProvider());

        $subscriber = SubscriberBuilder::create()
            ->listenOn(
                transports: [new UdpTransport(
                    "opc.udp://127.0.0.1:{$port}",
                    new UdpOptions(interface: '127.0.0.1'),
                )],
                readers: [TestHelper::makeReader(100, 1, 7, [
                    ['secret', BuiltinType::String],
                    ['counter', BuiltinType::UInt32],
                ])],
                security: $security,
            );

        $publisher = PubSubTestPublisher::secured('127.0.0.1', $port, $security);
        try {
            $subscriber->poll(timeoutMs: 0);
            usleep(50_000);
            $publisher->sendVariant(100, 1, 7, [
                'secret' => [BuiltinType::String, 'topsecret-value'],
                'counter' => [BuiltinType::UInt32, 12345],
            ]);

            $deadline = microtime(true) + 2.0;
            $messages = [];
            while (microtime(true) < $deadline && $messages === []) {
                $messages = $subscriber->poll(timeoutMs: 100);
            }

            expect($messages)->toHaveCount(1);
            $byName = [];
            foreach ($messages[0]->fields as $f) {
                $byName[$f->name] = $f->value->value;
            }
            expect($byName['secret'])->toBe('topsecret-value');
            expect($byName['counter'])->toBe(12345);
        } finally {
            $publisher->close();
            $subscriber->stop();
        }
    })->group('integration');
});
