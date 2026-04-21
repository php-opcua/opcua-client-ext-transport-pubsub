<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

/**
 * Dispatched after a transport's open() succeeds.
 */
final readonly class TransportOpened
{
    /**
     * @param string $transportUri
     */
    public function __construct(
        public string $transportUri,
    ) {}
}
