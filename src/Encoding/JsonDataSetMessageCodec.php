<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Encoding;

use DateTimeImmutable;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetField;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldEncoding;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

/**
 * JSON codec for one DataSetMessage (Part 14 §7.2.3, reversible form).
 */
final class JsonDataSetMessageCodec
{
    /**
     * @param array<string, mixed> $raw
     * @param int $dataSetWriterId
     * @param ?DataSetMetaData $metaData
     * @return DataSetMessage
     *
     * @throws PubSubDecodeException
     */
    public function decode(array $raw, int $dataSetWriterId, ?DataSetMetaData $metaData): DataSetMessage
    {
        $payload = $raw['Payload'] ?? null;
        if (! is_array($payload)) {
            throw new PubSubDecodeException('JSON DataSetMessage: missing or invalid "Payload" object');
        }

        $fields = [];
        foreach ($payload as $name => $entry) {
            $fields[] = new DataSetField((string) $name, $this->decodeField($entry));
        }

        return new DataSetMessage(
            dataSetWriterId: $dataSetWriterId,
            fieldEncoding: FieldEncoding::Variant,
            fields: $fields,
            sequenceNumber: is_int($raw['SequenceNumber'] ?? null) ? $raw['SequenceNumber'] : 0,
            timestamp: $this->parseTimestamp($raw['Timestamp'] ?? null),
            status: is_int($raw['Status'] ?? null) ? $raw['Status'] : 0,
            configVersionMajor: is_int($raw['MetaDataVersion']['MajorVersion'] ?? null) ? $raw['MetaDataVersion']['MajorVersion'] : 0,
            configVersionMinor: is_int($raw['MetaDataVersion']['MinorVersion'] ?? null) ? $raw['MetaDataVersion']['MinorVersion'] : 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function encode(DataSetMessage $message): array
    {
        $payload = [];
        foreach ($message->fields as $field) {
            $payload[$field->name] = $this->encodeField($field);
        }

        $out = [
            'DataSetWriterId' => $message->dataSetWriterId,
            'SequenceNumber' => $message->sequenceNumber,
            'Status' => $message->status,
            'Payload' => $payload,
        ];

        if ($message->configVersionMajor !== 0 || $message->configVersionMinor !== 0) {
            $out['MetaDataVersion'] = [
                'MajorVersion' => $message->configVersionMajor,
                'MinorVersion' => $message->configVersionMinor,
            ];
        }

        if ($message->timestamp !== null) {
            $out['Timestamp'] = $message->timestamp->format(DATE_RFC3339_EXTENDED);
        }

        return $out;
    }

    /**
     * @param mixed $entry
     * @throws PubSubDecodeException
     */
    private function decodeField(mixed $entry): Variant
    {
        if (! is_array($entry) || ! isset($entry['Type'], $entry['Body'])) {
            throw new PubSubDecodeException('JSON DataSetMessage: each field must be {"Type": int, "Body": value}');
        }

        $type = BuiltinType::tryFrom((int) $entry['Type'])
            ?? throw new PubSubDecodeException('JSON DataSetMessage: unknown Type ' . $entry['Type']);

        return new Variant($type, $entry['Body']);
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeField(DataSetField $field): array
    {
        $value = $field->value;
        if (! $value instanceof Variant) {
            throw new PubSubDecodeException('JSON DataSetMessage: field value must be a Variant for reversible encoding');
        }

        return ['Type' => $value->type->value, 'Body' => $value->value];
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
