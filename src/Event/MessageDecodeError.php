<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

use Throwable;

/**
 * Dispatched when a transport payload cannot be decoded.
 */
final readonly class MessageDecodeError
{
    /**
     * @param Throwable $error
     * @param string $transportUri
     * @param string $payloadPreview
     */
    public function __construct(
        public Throwable $error,
        public string $transportUri,
        public string $payloadPreview,
    ) {}
}
