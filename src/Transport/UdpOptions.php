<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Transport;

/**
 * UDP transport configuration.
 */
final readonly class UdpOptions
{
    /**
     * @param string $interface
     * @param int $receiveBufferSize
     * @param int $ttl
     * @param bool $reuseAddress
     */
    public function __construct(
        public string $interface = '0.0.0.0',
        public int $receiveBufferSize = 65536,
        public int $ttl = 32,
        public bool $reuseAddress = true,
    ) {}
}
