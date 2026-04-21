<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Encoding;

use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;

/**
 * Encode and decode a NetworkMessage using a specific wire format (UADP, JSON).
 */
interface NetworkMessageCodec
{
    /**
     * @param string $payload
     * @param array<string, DataSetReaderConfig> $readersByKey
     * @return NetworkMessage
     *
     * @throws \PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException
     */
    public function decode(string $payload, array $readersByKey): NetworkMessage;

    /**
     * @param NetworkMessage $message
     * @return string
     *
     * @throws \PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException
     */
    public function encode(NetworkMessage $message): string;
}
