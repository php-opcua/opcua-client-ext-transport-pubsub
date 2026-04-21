<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Kernel;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Zero-overhead PSR-14 dispatcher used when no dispatcher is configured.
 */
final class NullPubSubEventDispatcher implements EventDispatcherInterface
{
    /**
     * @param object $event
     * @return object
     */
    public function dispatch(object $event): object
    {
        return $event;
    }
}
