<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub;

use PhpOpcua\Client\ExtTransportPubSub\Encoding\JsonNetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Encoding\NetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpNetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Kernel\PubSubKernel;
use PhpOpcua\Client\ExtTransportPubSub\Module\DataSetReaderModule;
use PhpOpcua\Client\ExtTransportPubSub\Module\PubSubModule;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Entry point for constructing a Subscriber.
 */
final class SubscriberBuilder
{
    private LoggerInterface $logger;

    private ?EventDispatcherInterface $eventDispatcher = null;

    /** @var list<PubSubModule> */
    private array $extraModules = [];

    /** @var list<callable(DataSetMessage, int|string, int, string): void> */
    private array $dataSetCallbacks = [];

    private ?NetworkMessageCodec $codecOverride = null;

    private function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param EventDispatcherInterface $dispatcher
     * @return $this
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): self
    {
        $this->eventDispatcher = $dispatcher;

        return $this;
    }

    /**
     * @param callable(DataSetMessage, int|string, int, string): void $callback
     * @return $this
     */
    public function onDataSetMessage(callable $callback): self
    {
        $this->dataSetCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param PubSubModule $module
     * @return $this
     */
    public function addModule(PubSubModule $module): self
    {
        $this->extraModules[] = $module;

        return $this;
    }

    /**
     * @param NetworkMessageCodec $codec
     * @return $this
     */
    public function setCodec(NetworkMessageCodec $codec): self
    {
        $this->codecOverride = $codec;

        return $this;
    }

    /**
     * @return $this
     */
    public function useJson(): self
    {
        return $this->setCodec(new JsonNetworkMessageCodec());
    }

    /**
     * @param string $endpoint
     * @param UdpOptions $transport
     * @param list<DataSetReaderConfig> $readers
     * @param ?PubSubSecurityOptions $security
     * @return Subscriber
     */
    public function listenUdp(
        string $endpoint,
        UdpOptions $transport = new UdpOptions(),
        array $readers = [],
        ?PubSubSecurityOptions $security = null,
    ): Subscriber {
        $udp = new UdpTransport($endpoint, $transport);

        return $this->buildSubscriber([$udp], $readers, $security);
    }

    /**
     * @param list<PubSubTransportInterface> $transports
     * @param list<DataSetReaderConfig> $readers
     * @param ?PubSubSecurityOptions $security
     * @return Subscriber
     */
    public function listenOn(
        array $transports,
        array $readers,
        ?PubSubSecurityOptions $security = null,
    ): Subscriber {
        return $this->buildSubscriber($transports, $readers, $security);
    }

    /**
     * @param list<PubSubTransportInterface> $transports
     * @param list<DataSetReaderConfig> $readers
     * @param ?PubSubSecurityOptions $security
     * @return Subscriber
     */
    private function buildSubscriber(
        array $transports,
        array $readers,
        ?PubSubSecurityOptions $security,
    ): Subscriber {
        $codec = $this->codecOverride ?? new UadpNetworkMessageCodec(security: $security);
        $readerModule = new DataSetReaderModule($readers);

        $modules = array_merge([$readerModule], $this->extraModules);

        $kernel = new PubSubKernel(
            transports: $transports,
            codec: $codec,
            modules: $modules,
            logger: $this->logger,
            eventDispatcher: $this->eventDispatcher,
        );

        foreach ($this->dataSetCallbacks as $callback) {
            $kernel->onDataSetMessage($callback);
        }

        return new Subscriber($kernel);
    }
}
