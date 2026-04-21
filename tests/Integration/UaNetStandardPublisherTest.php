<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;
use PhpOpcua\Client\Types\BuiltinType;

// The uanetstandard-test-suite ships two paired services for PubSub
// interop: `opcua-pubsub` (UA-.NETStandard publisher) and
// `opcua-pubsub-relay` (stateless socat forwarder to the physical host
// on UDP 14850). Defaults here match that pairing. Override the port or
// interface from the environment if your local setup differs.
function pubsubPublisherEndpoint(): string
{
    return getenv('OPCUA_PUBSUB_ENDPOINT') ?: 'opc.udp://127.0.0.1:14850';
}

function pubsubPublisherInterface(): string
{
    return getenv('OPCUA_PUBSUB_INTERFACE') ?: '127.0.0.1';
}

describe('Interoperability with the UA-.NETStandard publisher', function () {

    it('receives and decodes UADP NetworkMessages from the reference publisher', function () {
        $subscriber = SubscriberBuilder::create()
            ->listenOn(
                transports: [new UdpTransport(
                    pubsubPublisherEndpoint(),
                    new UdpOptions(interface: pubsubPublisherInterface()),
                )],
                readers: [TestHelper::makeReader(100, 1, 1, [
                    ['counter', BuiltinType::UInt32],
                    ['timestamp', BuiltinType::DateTime],
                    ['value', BuiltinType::Double],
                ])],
            );

        try {
            $deadline = microtime(true) + 5.0;
            $messages = [];
            while (microtime(true) < $deadline && $messages === []) {
                $messages = $subscriber->poll(timeoutMs: 500);
            }

            expect($messages)->not->toBe([]);

            $msg = $messages[0];
            expect($msg->dataSetWriterId)->toBe(1);
            expect(count($msg->fields))->toBeGreaterThanOrEqual(1);
        } finally {
            $subscriber->stop();
        }
    })->group('integration');

    it('observes monotonically increasing counters across successive messages', function () {
        $subscriber = SubscriberBuilder::create()
            ->listenOn(
                transports: [new UdpTransport(
                    pubsubPublisherEndpoint(),
                    new UdpOptions(interface: pubsubPublisherInterface()),
                )],
                readers: [TestHelper::makeReader(100, 1, 1, [
                    ['counter', BuiltinType::UInt32],
                    ['timestamp', BuiltinType::DateTime],
                    ['value', BuiltinType::Double],
                ])],
            );

        try {
            $deadline = microtime(true) + 6.0;
            $counters = [];
            while (microtime(true) < $deadline && count($counters) < 3) {
                foreach ($subscriber->poll(timeoutMs: 500) as $msg) {
                    foreach ($msg->fields as $field) {
                        if ($field->name === 'counter') {
                            $counters[] = $field->value->value;
                            break;
                        }
                    }
                }
            }

            expect(count($counters))->toBeGreaterThanOrEqual(2);
            for ($i = 1; $i < count($counters); $i++) {
                expect($counters[$i])->toBeGreaterThan($counters[$i - 1]);
            }
        } finally {
            $subscriber->stop();
        }
    })->group('integration');
});
