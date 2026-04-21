<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

use Throwable;

/**
 * Dispatched when a transport raises an unexpected error.
 */
final readonly class TransportError
{
    /**
     * @param Throwable $error
     * @param string $transportUri
     */
    public function __construct(
        public Throwable $error,
        public string $transportUri,
    ) {}
}
