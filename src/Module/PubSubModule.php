<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Module;

use PhpOpcua\Client\ExtTransportPubSub\Kernel\PubSubKernelInterface;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;

/**
 * Base class for every PubSub subscriber-side module.
 */
abstract class PubSubModule
{
    protected ?PubSubKernelInterface $kernel = null;

    /**
     * @param PubSubKernelInterface $kernel
     */
    public function boot(PubSubKernelInterface $kernel): void
    {
        $this->kernel = $kernel;
    }

    /**
     * @param DataSetMessage $message
     * @param int|string $publisherId
     * @param int $writerGroupId
     * @param string $transportUri
     */
    public function onDataSetMessage(DataSetMessage $message, int|string $publisherId, int $writerGroupId, string $transportUri): void
    {
    }

    /**
     * @return void
     */
    public function reset(): void
    {
    }
}
