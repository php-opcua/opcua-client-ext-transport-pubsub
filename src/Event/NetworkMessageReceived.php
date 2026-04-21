<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;

/**
 * Dispatched after a NetworkMessage has been decoded.
 */
final readonly class NetworkMessageReceived
{
    /**
     * @param NetworkMessage $message
     * @param string $transportUri
     */
    public function __construct(
        public NetworkMessage $message,
        public string $transportUri,
    ) {}
}
