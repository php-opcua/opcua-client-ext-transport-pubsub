<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub;

use PhpOpcua\Client\ExtTransportPubSub\Kernel\PubSubKernel;

/**
 * Thin proxy over PubSubKernel implementing OpcUaSubscriberInterface.
 */
final class Subscriber implements OpcUaSubscriberInterface
{
    /**
     * @param PubSubKernel $kernel
     */
    public function __construct(
        private readonly PubSubKernel $kernel,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function run(): void
    {
        $this->kernel->run();
    }

    /**
     * {@inheritDoc}
     */
    public function poll(int $timeoutMs): array
    {
        return $this->kernel->poll($timeoutMs);
    }

    /**
     * {@inheritDoc}
     */
    public function stop(): void
    {
        $this->kernel->stop();
    }

    /**
     * {@inheritDoc}
     */
    public function isRunning(): bool
    {
        return $this->kernel->isRunning();
    }

    /**
     * @return PubSubKernel
     */
    public function kernel(): PubSubKernel
    {
        return $this->kernel;
    }
}
