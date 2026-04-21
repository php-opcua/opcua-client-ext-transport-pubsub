<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Transport;

/**
 * Raw payload handed from a transport to the kernel.
 */
final readonly class ReceivedPayload
{
    /**
     * @param string $data
     * @param string $sourceUri
     * @param float $receivedAt
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $data,
        public string $sourceUri,
        public float $receivedAt,
        public array $metadata = [],
    ) {}
}
