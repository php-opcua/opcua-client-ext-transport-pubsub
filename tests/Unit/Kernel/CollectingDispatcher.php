<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Tests\Unit\Kernel;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Test dispatcher that records every event dispatched to it.
 */
final class CollectingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @param class-string $className
     * @return list<object>
     */
    public function of(string $className): array
    {
        return array_values(array_filter($this->events, fn ($e) => $e instanceof $className));
    }
}
