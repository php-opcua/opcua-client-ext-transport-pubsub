<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

/**
 * Dispatched after a transport's close() completes.
 */
final readonly class TransportClosed
{
    /**
     * @param string $transportUri
     */
    public function __construct(
        public string $transportUri,
    ) {}
}
