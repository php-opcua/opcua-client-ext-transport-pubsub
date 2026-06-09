<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Encoding;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetField;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldEncoding;
use PhpOpcua\Client\Types\Variant;

/**
 * UADP binary codec for one DataSetMessage (Part 14 §6.2.5).
 */
final class UadpDataSetMessageCodec
{
    private const FLAG1_VALID = 0x01;

    private const FLAG1_FIELD_ENCODING_MASK = 0x06;

    private const FLAG1_FIELD_ENCODING_SHIFT = 1;

    private const FLAG1_SEQ_NUMBER = 0x08;

    private const FLAG1_STATUS = 0x10;

    private const FLAG1_CFG_MAJOR = 0x20;

    private const FLAG1_CFG_MINOR = 0x40;

    private const FLAG1_FLAGS2 = 0x80;

    private const FLAG2_MESSAGE_TYPE_MASK = 0x0F;

    private const FLAG2_TIMESTAMP = 0x10;

    private const FLAG2_PICOSECONDS = 0x20;

    private const MESSAGE_TYPE_DATA_KEYFRAME = 0x00;

    /**
     * @param BinaryDecoder $decoder
     * @param int $dataSetWriterId
     * @param ?DataSetMetaData $metaData
     * @return DataSetMessage
     *
     * @throws PubSubDecodeException
     */
    public function decode(BinaryDecoder $decoder, int $dataSetWriterId, ?DataSetMetaData $metaData): DataSetMessage
    {
        $flags1 = $decoder->readByte();

        if (($flags1 & self::FLAG1_VALID) === 0) {
            throw new PubSubDecodeException('UADP DataSetMessage: valid bit (Flags1 bit 0) not set');
        }

        $fieldEncodingBits = ($flags1 & self::FLAG1_FIELD_ENCODING_MASK) >> self::FLAG1_FIELD_ENCODING_SHIFT;
        $fieldEncoding = FieldEncoding::tryFrom($fieldEncodingBits)
            ?? throw new PubSubDecodeException("UADP DataSetMessage: reserved FieldEncoding bits {$fieldEncodingBits}");

        $flags2 = ($flags1 & self::FLAG1_FLAGS2) !== 0 ? $decoder->readByte() : 0;

        $messageType = $flags2 & self::FLAG2_MESSAGE_TYPE_MASK;
        if ($messageType !== self::MESSAGE_TYPE_DATA_KEYFRAME) {
            throw new PubSubDecodeException(
                "UADP DataSetMessage: unsupported MessageType {$messageType} (only DataKeyFrame is supported in v1)",
            );
        }

        $sequenceNumber = ($flags1 & self::FLAG1_SEQ_NUMBER) !== 0 ? $decoder->readUInt16() : 0;
        $timestamp = ($flags2 & self::FLAG2_TIMESTAMP) !== 0 ? $decoder->readDateTime() : null;
        if (($flags2 & self::FLAG2_PICOSECONDS) !== 0) {
            $decoder->readUInt16();
        }
        $status = ($flags1 & self::FLAG1_STATUS) !== 0 ? $decoder->readUInt16() : 0;
        $cfgMajor = ($flags1 & self::FLAG1_CFG_MAJOR) !== 0 ? $decoder->readUInt32() : 0;
        $cfgMinor = ($flags1 & self::FLAG1_CFG_MINOR) !== 0 ? $decoder->readUInt32() : 0;

        $fields = $this->decodeFields($decoder, $fieldEncoding, $metaData);

        return new DataSetMessage(
            dataSetWriterId: $dataSetWriterId,
            fieldEncoding: $fieldEncoding,
            fields: $fields,
            sequenceNumber: $sequenceNumber,
            timestamp: $timestamp,
            status: $status,
            configVersionMajor: $cfgMajor,
            configVersionMinor: $cfgMinor,
        );
    }

    /**
     * @param BinaryEncoder $encoder
     * @param DataSetMessage $message
     * @param ?DataSetMetaData $metaData
     *
     * @throws PubSubDecodeException
     */
    public function encode(BinaryEncoder $encoder, DataSetMessage $message, ?DataSetMetaData $metaData): void
    {
        $flags1 = self::FLAG1_VALID;
        $flags1 |= ($message->fieldEncoding->value << self::FLAG1_FIELD_ENCODING_SHIFT) & self::FLAG1_FIELD_ENCODING_MASK;

        if ($message->sequenceNumber !== 0) {
            $flags1 |= self::FLAG1_SEQ_NUMBER;
        }
        if ($message->status !== 0) {
            $flags1 |= self::FLAG1_STATUS;
        }
        if ($message->configVersionMajor !== 0) {
            $flags1 |= self::FLAG1_CFG_MAJOR;
        }
        if ($message->configVersionMinor !== 0) {
            $flags1 |= self::FLAG1_CFG_MINOR;
        }

        $flags2 = 0;
        if ($message->timestamp !== null) {
            $flags2 |= self::FLAG2_TIMESTAMP;
        }
        if ($flags2 !== 0) {
            $flags1 |= self::FLAG1_FLAGS2;
        }

        $encoder->writeByte($flags1);
        if (($flags1 & self::FLAG1_FLAGS2) !== 0) {
            $encoder->writeByte($flags2);
        }

        if (($flags1 & self::FLAG1_SEQ_NUMBER) !== 0) {
            $encoder->writeUInt16($message->sequenceNumber);
        }
        if (($flags2 & self::FLAG2_TIMESTAMP) !== 0) {
            $encoder->writeDateTime($message->timestamp);
        }
        if (($flags1 & self::FLAG1_STATUS) !== 0) {
            $encoder->writeUInt16($message->status);
        }
        if (($flags1 & self::FLAG1_CFG_MAJOR) !== 0) {
            $encoder->writeUInt32($message->configVersionMajor);
        }
        if (($flags1 & self::FLAG1_CFG_MINOR) !== 0) {
            $encoder->writeUInt32($message->configVersionMinor);
        }

        $this->encodeFields($encoder, $message, $metaData);
    }

    /**
     * @param BinaryDecoder $decoder
     * @param FieldEncoding $encoding
     * @param ?DataSetMetaData $metaData
     * @return list<DataSetField>
     * @throws PubSubDecodeException
     */
    private function decodeFields(BinaryDecoder $decoder, FieldEncoding $encoding, ?DataSetMetaData $metaData): array
    {
        $fieldCount = $decoder->readUInt16();
        $metaFields = $metaData?->fields ?? [];

        $out = [];
        for ($i = 0; $i < $fieldCount; $i++) {
            $name = $metaFields[$i]->name ?? ('field' . $i);

            $value = match ($encoding) {
                FieldEncoding::Variant => $decoder->readVariant(),
                FieldEncoding::RawData => $this->decodeRawField($decoder, $metaFields[$i] ?? null),
                FieldEncoding::DataValue => $decoder->readDataValue(),
            };

            $out[] = new DataSetField($name, $value);
        }

        return $out;
    }

    /**
     * @param BinaryDecoder $decoder
     * @param null|\PhpOpcua\Client\ExtTransportPubSub\Types\FieldMetaData $field
     * @throws PubSubDecodeException
     */
    private function decodeRawField(BinaryDecoder $decoder, mixed $field): mixed
    {
        if ($field === null) {
            throw new PubSubDecodeException(
                'UADP DataSetMessage: RawData encoding requires DataSetMetaData — no metadata matched the incoming field count',
            );
        }

        return $decoder->readVariantValue($field->builtInType);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param DataSetMessage $message
     * @param ?DataSetMetaData $metaData
     * @throws PubSubDecodeException
     */
    private function encodeFields(BinaryEncoder $encoder, DataSetMessage $message, ?DataSetMetaData $metaData): void
    {
        $encoder->writeUInt16(count($message->fields));
        $metaFields = $metaData?->fields ?? [];

        foreach ($message->fields as $i => $field) {
            match ($message->fieldEncoding) {
                FieldEncoding::Variant => $encoder->writeVariant($this->asVariant($field->value)),
                FieldEncoding::RawData => $this->encodeRawField($encoder, $field->value, $metaFields[$i] ?? null),
                FieldEncoding::DataValue => $encoder->writeDataValue($field->value),
            };
        }
    }

    /**
     * @param mixed $value
     * @throws PubSubDecodeException
     */
    private function asVariant(mixed $value): Variant
    {
        if (! $value instanceof Variant) {
            throw new PubSubDecodeException('UADP DataSetMessage (Variant encoding): field value must be a Variant');
        }

        return $value;
    }

    /**
     * @param BinaryEncoder $encoder
     * @param mixed $value
     * @param null|\PhpOpcua\Client\ExtTransportPubSub\Types\FieldMetaData $field
     * @throws PubSubDecodeException
     */
    private function encodeRawField(BinaryEncoder $encoder, mixed $value, mixed $field): void
    {
        if ($field === null) {
            throw new PubSubDecodeException(
                'UADP DataSetMessage: RawData encoding requires DataSetMetaData for every field',
            );
        }

        $encoder->writeVariantValue($field->builtInType, $value);
    }
}
