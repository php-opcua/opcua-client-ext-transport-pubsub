<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Encoding;

use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;

/**
 * JSON codec for a NetworkMessage (Part 14 §7.2.2, reversible form).
 */
final class JsonNetworkMessageCodec implements NetworkMessageCodec
{
    private readonly JsonDataSetMessageCodec $dataSetCodec;

    public function __construct(?JsonDataSetMessageCodec $dataSetCodec = null)
    {
        $this->dataSetCodec = $dataSetCodec ?? new JsonDataSetMessageCodec();
    }

    /**
     * {@inheritDoc}
     */
    public function decode(string $payload, array $readersByKey): NetworkMessage
    {
        try {
            $decoded = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PubSubDecodeException('JSON NetworkMessage: invalid JSON — ' . $e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new PubSubDecodeException('JSON NetworkMessage: root must be an object');
        }

        if (($decoded['MessageType'] ?? null) !== 'ua-data') {
            throw new PubSubDecodeException(
                'JSON NetworkMessage: MessageType must be "ua-data" (got ' . var_export($decoded['MessageType'] ?? null, true) . ')',
            );
        }

        $publisherId = $this->normalizePublisherId($decoded['PublisherId'] ?? null);
        $writerGroupId = is_int($decoded['WriterGroupId'] ?? null) ? $decoded['WriterGroupId'] : 0;

        $messages = $decoded['Messages'] ?? null;
        if (! is_array($messages)) {
            throw new PubSubDecodeException('JSON NetworkMessage: missing or invalid "Messages" array');
        }

        $dataSetMessages = [];
        foreach ($messages as $i => $msg) {
            if (! is_array($msg)) {
                throw new PubSubDecodeException("JSON NetworkMessage: Messages[{$i}] must be an object");
            }

            $writerId = is_int($msg['DataSetWriterId'] ?? null) ? $msg['DataSetWriterId'] : 0;
            $reader = $this->lookupReader($publisherId, $writerGroupId, $writerId, $readersByKey);
            $dataSetMessages[] = $this->dataSetCodec->decode($msg, $writerId, $reader?->dataSetMetaData);
        }

        return new NetworkMessage(
            publisherId: $publisherId,
            writerGroupId: $writerGroupId,
            networkMessageNumber: 0,
            sequenceNumber: 0,
            timestamp: null,
            dataSetMessages: $dataSetMessages,
            dataSetClassId: is_string($decoded['DataSetClassId'] ?? null) ? $decoded['DataSetClassId'] : null,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function encode(NetworkMessage $message): string
    {
        $encoded = [
            'MessageId' => $this->randomUuid(),
            'MessageType' => 'ua-data',
            'PublisherId' => (string) $message->publisherId,
            'WriterGroupId' => $message->writerGroupId,
            'Messages' => array_map(
                fn ($dsm) => $this->dataSetCodec->encode($dsm),
                $message->dataSetMessages,
            ),
        ];

        if ($message->dataSetClassId !== null) {
            $encoded['DataSetClassId'] = $message->dataSetClassId;
        }

        return json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function normalizePublisherId(mixed $value): int|string
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_int($value)) {
            return $value;
        }

        return (string) ($value ?? 0);
    }

    /**
     * @param array<string, DataSetReaderConfig> $readersByKey
     */
    private function lookupReader(
        int|string $publisherId,
        int $writerGroupId,
        int $writerId,
        array $readersByKey,
    ): ?DataSetReaderConfig {
        $key = (string) $publisherId . '|' . $writerGroupId . '|' . $writerId;
        if (isset($readersByKey[$key])) {
            return $readersByKey[$key];
        }

        $any = (string) $publisherId . '|0|' . $writerId;
        if (isset($readersByKey[$any])) {
            return $readersByKey[$any];
        }

        return null;
    }

    private function randomUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
