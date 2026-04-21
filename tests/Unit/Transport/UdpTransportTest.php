<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\UnsupportedTransportException;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;

function pickFreePort(): int
{
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($socket, '127.0.0.1', 0);
    socket_getsockname($socket, $addr, $port);
    socket_close($socket);

    return $port;
}

describe('UdpTransport — endpoint parsing', function () {

    it('rejects an endpoint missing port', function () {
        expect(fn () => new UdpTransport('opc.udp://127.0.0.1'))
            ->toThrow(UnsupportedTransportException::class, 'invalid endpoint');
    });

    it('accepts opc.udp:// scheme', function () {
        $transport = new UdpTransport('opc.udp://127.0.0.1:4840');
        expect($transport->transportUri())->toBe('opc.udp://127.0.0.1:4840');
    });
});

describe('UdpTransport — unicast loopback round-trip', function () {
    if (! function_exists('socket_create')) {
        test()->skip('ext-sockets not available');

        return;
    }

    it('opens, receives a unicast datagram, and closes cleanly', function () {
        $port = pickFreePort();
        $transport = new UdpTransport(
            "opc.udp://127.0.0.1:{$port}",
            new UdpOptions(interface: '127.0.0.1'),
        );

        $transport->open();
        try {
            expect($transport->isOpen())->toBeTrue();

            $sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_sendto($sender, 'hello-pubsub', 12, 0, '127.0.0.1', $port);
            socket_close($sender);

            $payload = $transport->poll(timeoutMs: 500);
            expect($payload)->not->toBeNull();
            expect($payload->data)->toBe('hello-pubsub');
            expect($payload->sourceUri)->toBe("opc.udp://127.0.0.1:{$port}");
        } finally {
            $transport->close();
        }

        expect($transport->isOpen())->toBeFalse();
    });

    it('poll() returns null when no datagram arrives within the timeout', function () {
        $port = pickFreePort();
        $transport = new UdpTransport(
            "opc.udp://127.0.0.1:{$port}",
            new UdpOptions(interface: '127.0.0.1'),
        );

        $transport->open();
        try {
            expect($transport->poll(timeoutMs: 20))->toBeNull();
        } finally {
            $transport->close();
        }
    });

    it('throws when poll() is called before open()', function () {
        $transport = new UdpTransport('opc.udp://127.0.0.1:1');

        expect(fn () => $transport->poll(0))
            ->toThrow(UnsupportedTransportException::class, 'before open()');
    });

    it('close() is idempotent', function () {
        $port = pickFreePort();
        $transport = new UdpTransport(
            "opc.udp://127.0.0.1:{$port}",
            new UdpOptions(interface: '127.0.0.1'),
        );
        $transport->open();
        $transport->close();
        $transport->close();

        expect($transport->isOpen())->toBeFalse();
    });
});
