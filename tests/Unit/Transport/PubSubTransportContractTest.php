<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\ReceivedPayload;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;

describe('PubSubTransportInterface', function () {

    it('declares the contract methods', function () {
        $reflection = new ReflectionClass(PubSubTransportInterface::class);

        expect($reflection->isInterface())->toBeTrue();
        expect($reflection->hasMethod('open'))->toBeTrue();
        expect($reflection->hasMethod('close'))->toBeTrue();
        expect($reflection->hasMethod('poll'))->toBeTrue();
        expect($reflection->hasMethod('isOpen'))->toBeTrue();
        expect($reflection->hasMethod('transportUri'))->toBeTrue();
    });
});

describe('ReceivedPayload', function () {

    it('is a readonly DTO', function () {
        $reflection = new ReflectionClass(ReceivedPayload::class);

        expect($reflection->isReadOnly())->toBeTrue();
        expect($reflection->isFinal())->toBeTrue();
    });

    it('exposes the four public properties from the contract', function () {
        $payload = new ReceivedPayload(
            data: 'raw-bytes',
            sourceUri: 'opc.udp://239.0.0.1:4840',
            receivedAt: 1_700_000_000.123456,
            metadata: ['qos' => 1],
        );

        expect($payload->data)->toBe('raw-bytes');
        expect($payload->sourceUri)->toBe('opc.udp://239.0.0.1:4840');
        expect($payload->receivedAt)->toBe(1_700_000_000.123456);
        expect($payload->metadata)->toBe(['qos' => 1]);
    });

    it('defaults metadata to an empty array', function () {
        $payload = new ReceivedPayload('x', 'opc.udp://host', 0.0);

        expect($payload->metadata)->toBe([]);
    });
});

describe('UdpOptions', function () {

    it('is a readonly DTO', function () {
        $reflection = new ReflectionClass(UdpOptions::class);

        expect($reflection->isReadOnly())->toBeTrue();
        expect($reflection->isFinal())->toBeTrue();
    });

    it('has sensible defaults', function () {
        $options = new UdpOptions();

        expect($options->interface)->toBe('0.0.0.0');
        expect($options->receiveBufferSize)->toBe(65536);
        expect($options->ttl)->toBe(32);
        expect($options->reuseAddress)->toBeTrue();
    });

    it('accepts explicit overrides', function () {
        $options = new UdpOptions(
            interface: '::',
            receiveBufferSize: 131072,
            ttl: 8,
            reuseAddress: false,
        );

        expect($options->interface)->toBe('::');
        expect($options->receiveBufferSize)->toBe(131072);
        expect($options->ttl)->toBe(8);
        expect($options->reuseAddress)->toBeFalse();
    });
});

describe('UdpTransport', function () {

    it('implements PubSubTransportInterface', function () {
        $transport = new UdpTransport('opc.udp://239.0.0.1:4840');

        expect($transport)->toBeInstanceOf(PubSubTransportInterface::class);
    });

    it('reports the endpoint via transportUri()', function () {
        $transport = new UdpTransport('opc.udp://239.0.0.1:4840');

        expect($transport->transportUri())->toBe('opc.udp://239.0.0.1:4840');
    });

    it('is not open until open() is called', function () {
        $transport = new UdpTransport('opc.udp://239.0.0.1:4840');

        expect($transport->isOpen())->toBeFalse();
    });

    it('exposes the UdpOptions it was constructed with', function () {
        $options = new UdpOptions(interface: '127.0.0.1');
        $transport = new UdpTransport('opc.udp://127.0.0.1:4840', $options);

        expect($transport->getOptions())->toBe($options);
    });
});
