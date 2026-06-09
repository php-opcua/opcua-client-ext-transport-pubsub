<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Tests\Unit\Kernel;

use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\ReceivedPayload;

/**
 * In-memory transport used by kernel and builder tests.
 *
 * Callers push raw payloads with `enqueue()`. Each `poll()` dequeues one
 * payload or returns null when the queue is empty.
 */
final class FakeTransport implements PubSubTransportInterface
{
    /** @var list<string> */
    private array $queue = [];

    private bool $open = false;

    public function __construct(
        public string $uri = 'fake://test',
    ) {
    }

    public function enqueue(string $bytes): void
    {
        $this->queue[] = $bytes;
    }

    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function poll(int $timeoutMs): ?ReceivedPayload
    {
        if ($this->queue === []) {
            return null;
        }

        $bytes = array_shift($this->queue);

        return new ReceivedPayload($bytes, $this->uri, microtime(true));
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function transportUri(): string
    {
        return $this->uri;
    }
}
