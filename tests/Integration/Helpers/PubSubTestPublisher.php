<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers;

use PhpOpcua\Client\ExtTransportPubSub\Encoding\JsonNetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Encoding\NetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpNetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetField;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldEncoding;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

/**
 * In-process UDP publisher used by integration tests.
 */
final class PubSubTestPublisher
{
    private \Socket $socket;

    /**
     * @param string $host
     * @param int $port
     * @param NetworkMessageCodec $codec
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly NetworkMessageCodec $codec = new UadpNetworkMessageCodec(),
    ) {
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    }

    /**
     * @return void
     */
    public function close(): void
    {
        socket_close($this->socket);
    }

    /**
     * @param NetworkMessage $message
     */
    public function send(NetworkMessage $message): void
    {
        $bytes = $this->codec->encode($message);

        socket_sendto($this->socket, $bytes, strlen($bytes), 0, $this->host, $this->port);
    }

    /**
     * @param int|string $publisherId
     * @param int $writerGroupId
     * @param int $dataSetWriterId
     * @param array<string, array{0: BuiltinType, 1: mixed}> $fields
     * @param int $sequenceNumber
     */
    public function sendVariant(
        int|string $publisherId,
        int $writerGroupId,
        int $dataSetWriterId,
        array $fields,
        int $sequenceNumber = 1,
    ): void {
        $dsFields = [];
        foreach ($fields as $name => [$type, $value]) {
            $dsFields[] = new DataSetField($name, new Variant($type, $value));
        }

        $nm = new NetworkMessage(
            publisherId: $publisherId,
            writerGroupId: $writerGroupId,
            networkMessageNumber: 0,
            sequenceNumber: $sequenceNumber,
            timestamp: null,
            dataSetMessages: [
                new DataSetMessage(
                    dataSetWriterId: $dataSetWriterId,
                    fieldEncoding: FieldEncoding::Variant,
                    fields: $dsFields,
                    sequenceNumber: $sequenceNumber,
                ),
            ],
        );

        $this->send($nm);
    }

    /**
     * @param string $host
     * @param int $port
     * @return self
     */
    public static function json(string $host, int $port): self
    {
        return new self($host, $port, new JsonNetworkMessageCodec());
    }

    /**
     * @param string $host
     * @param int $port
     * @param PubSubSecurityOptions $security
     * @return self
     */
    public static function secured(string $host, int $port, PubSubSecurityOptions $security): self
    {
        return new self($host, $port, new UadpNetworkMessageCodec(security: $security));
    }
}
