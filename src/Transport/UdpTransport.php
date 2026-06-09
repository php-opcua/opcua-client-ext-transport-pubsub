<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Transport;

use PhpOpcua\Client\ExtTransportPubSub\Exception\UnsupportedTransportException;
use Socket;

/**
 * UDP PubSub transport for unicast and IPv4 multicast.
 */
final class UdpTransport implements PubSubTransportInterface
{
    private const MULTICAST_IPV4_START = 0xE0000000;

    private const MULTICAST_IPV4_END = 0xEFFFFFFF;

    private readonly UdpOptions $options;

    private ?Socket $socket = null;

    private readonly string $host;

    private readonly int $port;

    private readonly bool $isMulticast;

    /**
     * @param string $endpoint
     * @param ?UdpOptions $options
     */
    public function __construct(
        private readonly string $endpoint,
        ?UdpOptions $options = null,
    ) {
        $this->options = $options ?? new UdpOptions();
        [$host, $port] = $this->parseEndpoint($endpoint);
        $this->host = $host;
        $this->port = $port;
        $this->isMulticast = $this->isIpv4Multicast($host);
    }

    /**
     * @throws UnsupportedTransportException
     */
    public function open(): void
    {
        if ($this->socket !== null) {
            return;
        }

        if (! function_exists('socket_create')) {
            throw new UnsupportedTransportException('UdpTransport requires ext-sockets');
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new UnsupportedTransportException('UdpTransport: socket_create failed: ' . $this->lastSocketError(null));
        }

        if ($this->options->reuseAddress) {
            @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
            if (defined('SO_REUSEPORT')) {
                @socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);
            }
        }

        if ($this->options->receiveBufferSize > 0) {
            @socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, $this->options->receiveBufferSize);
        }

        $bindAddr = $this->isMulticast ? $this->options->interface : $this->host;
        if (@socket_bind($socket, $bindAddr, $this->port) === false) {
            $message = 'UdpTransport: bind failed on ' . $bindAddr . ':' . $this->port . ' — ' . $this->lastSocketError($socket);
            @socket_close($socket);

            throw new UnsupportedTransportException($message);
        }

        if ($this->isMulticast) {
            $joined = @socket_set_option($socket, IPPROTO_IP, MCAST_JOIN_GROUP, [
                'group' => $this->host,
                'interface' => 0,
            ]);

            if ($joined === false) {
                $joined = @socket_set_option($socket, IPPROTO_IP, 12, [
                    'group' => $this->host,
                    'interface' => $this->options->interface,
                ]);
            }

            if ($joined === false) {
                $message = 'UdpTransport: multicast join failed for ' . $this->host . ' — ' . $this->lastSocketError($socket);
                @socket_close($socket);

                throw new UnsupportedTransportException($message);
            }

            @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, $this->options->ttl);
        }

        @socket_set_nonblock($socket);

        $this->socket = $socket;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if ($this->socket === null) {
            return;
        }

        @socket_close($this->socket);
        $this->socket = null;
    }

    /**
     * @param int $timeoutMs
     * @return ?ReceivedPayload
     *
     * @throws UnsupportedTransportException
     */
    public function poll(int $timeoutMs): ?ReceivedPayload
    {
        if ($this->socket === null) {
            throw new UnsupportedTransportException('UdpTransport::poll called before open()');
        }

        $read = [$this->socket];
        $write = null;
        $except = null;

        $tvSec = intdiv(max($timeoutMs, 0), 1000);
        $tvUsec = (max($timeoutMs, 0) % 1000) * 1000;

        $ready = @socket_select($read, $write, $except, $tvSec, $tvUsec);
        if ($ready === false || $ready === 0) {
            return null;
        }

        $buffer = '';
        $from = '';
        $fromPort = 0;
        $received = @socket_recvfrom($this->socket, $buffer, 65535, 0, $from, $fromPort);
        if ($received === false || $received === 0) {
            return null;
        }

        return new ReceivedPayload(
            data: substr($buffer, 0, $received),
            sourceUri: $this->endpoint,
            receivedAt: microtime(true),
            metadata: ['peerAddr' => $from, 'peerPort' => $fromPort],
        );
    }

    /**
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->socket !== null;
    }

    /**
     * @return string
     */
    public function transportUri(): string
    {
        return $this->endpoint;
    }

    /**
     * @return UdpOptions
     */
    public function getOptions(): UdpOptions
    {
        return $this->options;
    }

    /**
     * @param string $endpoint
     * @return array{0: string, 1: int}
     *
     * @throws UnsupportedTransportException
     */
    private function parseEndpoint(string $endpoint): array
    {
        $withScheme = preg_replace('#^opc\.udp://#i', 'udp://', $endpoint);
        $parts = parse_url($withScheme);

        if (! is_array($parts) || ! isset($parts['host'], $parts['port'])) {
            throw new UnsupportedTransportException(
                "UdpTransport: invalid endpoint '{$endpoint}', expected opc.udp://HOST:PORT",
            );
        }

        return [(string) $parts['host'], (int) $parts['port']];
    }

    /**
     * @param string $host
     * @return bool
     */
    private function isIpv4Multicast(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $int = ip2long($host);
        if ($int === false) {
            return false;
        }

        $u = $int & 0xFFFFFFFF;

        return $u >= self::MULTICAST_IPV4_START && $u <= self::MULTICAST_IPV4_END;
    }

    /**
     * @param ?Socket $socket
     * @return string
     */
    private function lastSocketError(?Socket $socket): string
    {
        $code = $socket !== null ? socket_last_error($socket) : socket_last_error();

        return socket_strerror($code);
    }
}
