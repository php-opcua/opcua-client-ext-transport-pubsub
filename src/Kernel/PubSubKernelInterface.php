<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Kernel;

use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Kernel surface exposed to PubSubModule instances.
 */
interface PubSubKernelInterface
{
    /**
     * @return LoggerInterface
     */
    public function logger(): LoggerInterface;

    /**
     * @return EventDispatcherInterface
     */
    public function eventDispatcher(): EventDispatcherInterface;

    /**
     * @return list<PubSubTransportInterface>
     */
    public function transports(): array;

    /**
     * @param object $event
     */
    public function dispatch(object $event): void;
}
