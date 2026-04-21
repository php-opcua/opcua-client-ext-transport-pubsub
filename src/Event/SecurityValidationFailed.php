<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

use Throwable;

/**
 * Dispatched when a secured NetworkMessage fails signature or decryption.
 */
final readonly class SecurityValidationFailed
{
    /**
     * @param Throwable $error
     * @param string $transportUri
     * @param string $reason
     */
    public function __construct(
        public Throwable $error,
        public string $transportUri,
        public string $reason,
    ) {}
}
