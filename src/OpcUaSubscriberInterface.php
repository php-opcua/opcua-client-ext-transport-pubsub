<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub;

use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;

/**
 * Public API for a connected PubSub subscriber.
 */
interface OpcUaSubscriberInterface
{
    /**
     * @return void
     */
    public function run(): void;

    /**
     * @param int $timeoutMs
     * @return list<DataSetMessage>
     */
    public function poll(int $timeoutMs): array;

    /**
     * @return void
     */
    public function stop(): void;

    /**
     * @return bool
     */
    public function isRunning(): bool;
}
