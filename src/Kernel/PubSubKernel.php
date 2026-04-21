<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Kernel;

use PhpOpcua\Client\ExtTransportPubSub\Encoding\NetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetFieldReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\MessageDecodeError;
use PhpOpcua\Client\ExtTransportPubSub\Event\NetworkMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\SecurityValidationFailed;
use PhpOpcua\Client\ExtTransportPubSub\Event\TransportClosed;
use PhpOpcua\Client\ExtTransportPubSub\Event\TransportError;
use PhpOpcua\Client\ExtTransportPubSub\Event\TransportOpened;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;
use PhpOpcua\Client\ExtTransportPubSub\Module\DataSetReaderModule;
use PhpOpcua\Client\ExtTransportPubSub\Module\PubSubModule;
use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\ReceivedPayload;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Event-loop kernel for a subscriber.
 */
final class PubSubKernel implements PubSubKernelInterface
{
    private bool $running = false;

    /** @var list<callable(DataSetMessage, int|string, int, string): void> */
    private array $dataSetCallbacks = [];

    /** @var list<PubSubModule> */
    private array $modules = [];

    /**
     * @param list<PubSubTransportInterface> $transports
     * @param NetworkMessageCodec $codec
     * @param list<PubSubModule> $modules
     * @param LoggerInterface $logger
     * @param ?EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        private readonly array $transports,
        private readonly NetworkMessageCodec $codec,
        array $modules,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        foreach ($modules as $module) {
            $this->attachModule($module);
        }

        if ($this->readerModule() === null) {
            throw new \InvalidArgumentException('PubSubKernel requires a DataSetReaderModule');
        }
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function eventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher ?? new NullPubSubEventDispatcher();
    }

    /**
     * @return list<PubSubTransportInterface>
     */
    public function transports(): array
    {
        return $this->transports;
    }

    public function dispatch(object $event): void
    {
        $this->eventDispatcher?->dispatch($event);
    }

    /**
     * @param callable(DataSetMessage, int|string, int, string): void $callback
     */
    public function onDataSetMessage(callable $callback): void
    {
        $this->dataSetCallbacks[] = $callback;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        $this->openAll();
        $this->running = true;

        try {
            while ($this->running) {
                $this->tick(timeoutMs: 100);
            }
        } finally {
            $this->closeAll();
        }
    }

    /**
     * @param int $timeoutMs
     * @return list<DataSetMessage>
     */
    public function poll(int $timeoutMs): array
    {
        $this->openAll();

        $collected = [];
        $onMessage = function (DataSetMessage $dsm) use (&$collected) {
            $collected[] = $dsm;
        };
        $this->dataSetCallbacks[] = $onMessage;

        try {
            $this->tick($timeoutMs);
        } finally {
            array_pop($this->dataSetCallbacks);
        }

        return $collected;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->closeAll();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function attachModule(PubSubModule $module): void
    {
        $module->boot($this);
        $this->modules[] = $module;
    }

    private function readerModule(): ?DataSetReaderModule
    {
        foreach ($this->modules as $module) {
            if ($module instanceof DataSetReaderModule) {
                return $module;
            }
        }

        return null;
    }

    private function openAll(): void
    {
        foreach ($this->transports as $transport) {
            if ($transport->isOpen()) {
                continue;
            }

            try {
                $transport->open();
                $this->dispatch(new TransportOpened($transport->transportUri()));
            } catch (Throwable $e) {
                $this->logger->error('PubSub transport open failed', ['uri' => $transport->transportUri(), 'error' => $e->getMessage()]);
                $this->dispatch(new TransportError($e, $transport->transportUri()));
            }
        }
    }

    private function closeAll(): void
    {
        foreach ($this->transports as $transport) {
            if (! $transport->isOpen()) {
                continue;
            }

            $transport->close();
            $this->dispatch(new TransportClosed($transport->transportUri()));
        }

        foreach ($this->modules as $module) {
            $module->reset();
        }
    }

    private function tick(int $timeoutMs): void
    {
        $perTransportTimeout = max(1, intdiv($timeoutMs, max(count($this->transports), 1)));

        foreach ($this->transports as $transport) {
            if (! $transport->isOpen()) {
                continue;
            }

            try {
                $payload = $transport->poll($perTransportTimeout);
            } catch (Throwable $e) {
                $this->dispatch(new TransportError($e, $transport->transportUri()));
                continue;
            }

            if ($payload === null) {
                continue;
            }

            $this->handlePayload($payload);
        }
    }

    private function handlePayload(ReceivedPayload $payload): void
    {
        $readerModule = $this->readerModule();
        $readersByKey = $readerModule?->readersByKey() ?? [];

        try {
            $network = $this->codec->decode($payload->data, $readersByKey);
        } catch (PubSubSecurityException $e) {
            $this->dispatch(new SecurityValidationFailed($e, $payload->sourceUri, $e->getMessage()));

            return;
        } catch (Throwable $e) {
            $preview = substr(bin2hex($payload->data), 0, 64);
            $this->dispatch(new MessageDecodeError($e, $payload->sourceUri, $preview));

            return;
        }

        $this->dispatch(new NetworkMessageReceived($network, $payload->sourceUri));

        $this->dispatchDataSets($network, $payload->sourceUri);
    }

    private function dispatchDataSets(NetworkMessage $network, string $transportUri): void
    {
        foreach ($network->dataSetMessages as $dsm) {
            $this->dispatch(new DataSetMessageReceived($dsm, $transportUri, $network->publisherId, $network->writerGroupId));

            foreach ($dsm->fields as $field) {
                $this->dispatch(new DataSetFieldReceived($field, $dsm->dataSetWriterId, $network->publisherId, $network->writerGroupId));
            }

            foreach ($this->dataSetCallbacks as $callback) {
                $callback($dsm, $network->publisherId, $network->writerGroupId, $transportUri);
            }

            foreach ($this->modules as $module) {
                $module->onDataSetMessage($dsm, $network->publisherId, $network->writerGroupId, $transportUri);
            }
        }
    }
}
