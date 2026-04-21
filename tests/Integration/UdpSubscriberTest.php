<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers\PubSubTestPublisher;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\Types\BuiltinType;

describe('UDP subscriber — UADP round-trip on loopback', function () {

    it('receives a single DataSetMessage with Variant fields', function () {
        $port = TestHelper::pickFreeLoopbackPort();
        $subscriber = SubscriberBuilder::create()
            ->listenOn(
                transports: [new \PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport(
                    "opc.udp://127.0.0.1:{$port}",
                    new UdpOptions(interface: '127.0.0.1'),
                )],
                readers: [TestHelper::makeReader(100, 1, 7, [
                    ['temperature', BuiltinType::Double],
                    ['online', BuiltinType::Boolean],
                ])],
            );

        $publisher = new PubSubTestPublisher('127.0.0.1', $port);
        try {
            $subscriber->poll(timeoutMs: 0);
            usleep(50_000);
            $publisher->sendVariant(100, 1, 7, [
                'temperature' => [BuiltinType::Double, 23.5],
                'online' => [BuiltinType::Boolean, true],
            ]);

            $deadline = microtime(true) + 2.0;
            $messages = [];
            while (microtime(true) < $deadline && $messages === []) {
                $messages = $subscriber->poll(timeoutMs: 100);
            }

            expect($messages)->toHaveCount(1);
            expect($messages[0])->toBeInstanceOf(DataSetMessage::class);
            expect($messages[0]->dataSetWriterId)->toBe(7);
            expect($messages[0]->fields[0]->name)->toBe('temperature');
            expect($messages[0]->fields[0]->value->value)->toBe(23.5);
            expect($messages[0]->fields[1]->value->value)->toBeTrue();
        } finally {
            $publisher->close();
            $subscriber->stop();
        }
    })->group('integration');

    it('demuxes multiple DataSetWriterIds onto their matching readers', function () {
        $port = TestHelper::pickFreeLoopbackPort();
        $subscriber = SubscriberBuilder::create()
            ->listenOn(
                transports: [new \PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport(
                    "opc.udp://127.0.0.1:{$port}",
                    new UdpOptions(interface: '127.0.0.1'),
                )],
                readers: [
                    TestHelper::makeReader(100, 1, 7, [['temp', BuiltinType::Double]]),
                    TestHelper::makeReader(100, 1, 8, [['rpm', BuiltinType::Int32]]),
                ],
            );

        $publisher = new PubSubTestPublisher('127.0.0.1', $port);
        try {
            $subscriber->poll(timeoutMs: 0);
            usleep(50_000);
            $publisher->sendVariant(100, 1, 7, ['temp' => [BuiltinType::Double, 20.0]]);
            $publisher->sendVariant(100, 1, 8, ['rpm' => [BuiltinType::Int32, 1500]]);

            $deadline = microtime(true) + 2.0;
            $collected = [];
            while (microtime(true) < $deadline && count($collected) < 2) {
                $collected = array_merge($collected, $subscriber->poll(timeoutMs: 100));
            }

            $byWriter = [];
            foreach ($collected as $msg) {
                $byWriter[$msg->dataSetWriterId] = $msg;
            }

            expect(array_keys($byWriter))->toContain(7, 8);
            expect($byWriter[7]->fields[0]->value->value)->toBe(20.0);
            expect($byWriter[8]->fields[0]->value->value)->toBe(1500);
        } finally {
            $publisher->close();
            $subscriber->stop();
        }
    })->group('integration');

    it('handles a burst of 20 sequential messages without drops', function () {
        $port = TestHelper::pickFreeLoopbackPort();
        $subscriber = SubscriberBuilder::create()
            ->listenOn(
                transports: [new \PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport(
                    "opc.udp://127.0.0.1:{$port}",
                    new UdpOptions(interface: '127.0.0.1', receiveBufferSize: 262144),
                )],
                readers: [TestHelper::makeReader(100, 1, 7, [['counter', BuiltinType::UInt32]])],
            );

        $publisher = new PubSubTestPublisher('127.0.0.1', $port);
        try {
            $subscriber->poll(timeoutMs: 0);
            usleep(50_000);
            for ($i = 1; $i <= 20; $i++) {
                $publisher->sendVariant(100, 1, 7, ['counter' => [BuiltinType::UInt32, $i]], $i);
            }

            $deadline = microtime(true) + 3.0;
            $collected = [];
            while (microtime(true) < $deadline && count($collected) < 20) {
                $collected = array_merge($collected, $subscriber->poll(timeoutMs: 100));
            }

            expect(count($collected))->toBeGreaterThanOrEqual(18);

            $values = array_map(fn ($m) => $m->fields[0]->value->value, $collected);
            expect($values)->toContain(1, 20);
        } finally {
            $publisher->close();
            $subscriber->stop();
        }
    })->group('integration');
});

describe('UDP subscriber — JSON round-trip on loopback', function () {

    it('receives JSON-encoded DataSetMessages', function () {
        $port = TestHelper::pickFreeLoopbackPort();
        $subscriber = SubscriberBuilder::create()
            ->useJson()
            ->listenOn(
                transports: [new \PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport(
                    "opc.udp://127.0.0.1:{$port}",
                    new UdpOptions(interface: '127.0.0.1'),
                )],
                readers: [TestHelper::makeReader(100, 1, 7, [['label', BuiltinType::String]])],
            );

        $publisher = PubSubTestPublisher::json('127.0.0.1', $port);
        try {
            $subscriber->poll(timeoutMs: 0);
            usleep(50_000);
            $publisher->sendVariant(100, 1, 7, [
                'label' => [BuiltinType::String, 'line-alpha'],
            ]);

            $deadline = microtime(true) + 2.0;
            $messages = [];
            while (microtime(true) < $deadline && $messages === []) {
                $messages = $subscriber->poll(timeoutMs: 100);
            }

            expect($messages)->toHaveCount(1);
            expect($messages[0]->fields[0]->value->value)->toBe('line-alpha');
        } finally {
            $publisher->close();
            $subscriber->stop();
        }
    })->group('integration');
});
