<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Transport;

/**
 * Contract for every PubSub inbound transport.
 */
interface PubSubTransportInterface
{
    /**
     * Open the underlying transport.
     *
     * @throws \PhpOpcua\Client\ExtTransportPubSub\Exception\UnsupportedTransportException
     */
    public function open(): void;

    /**
     * @return void
     */
    public function close(): void;

    /**
     * @param int $timeoutMs
     * @return ?ReceivedPayload
     */
    public function poll(int $timeoutMs): ?ReceivedPayload;

    /**
     * @return bool
     */
    public function isOpen(): bool;

    /**
     * @return string
     */
    public function transportUri(): string;
}
