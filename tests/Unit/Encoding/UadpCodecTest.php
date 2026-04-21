<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpNetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetField;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldEncoding;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

function variantDataSet(int $writerId, int $seq, array $fields): DataSetMessage
{
    $dsFields = [];
    foreach ($fields as [$name, $type, $value]) {
        $dsFields[] = new DataSetField($name, new Variant($type, $value));
    }

    return new DataSetMessage(
        dataSetWriterId: $writerId,
        fieldEncoding: FieldEncoding::Variant,
        fields: $dsFields,
        sequenceNumber: $seq,
    );
}

function readerConfigs(int|string $publisherId, int $groupId, array $writerIds): array
{
    $out = [];
    foreach ($writerIds as $wid) {
        $cfg = new DataSetReaderConfig(
            publisherId: $publisherId,
            writerGroupId: $groupId,
            dataSetWriterId: $wid,
            dataSetMetaData: new DataSetMetaData(
                name: "writer-{$wid}",
                fields: [new FieldMetaData('unused', BuiltinType::Int32)],
            ),
        );
        $out[$cfg->demuxKey()] = $cfg;
    }

    return $out;
}

describe('UadpNetworkMessageCodec — round-trip (Variant encoding)', function () {

    it('round-trips a single DataSetMessage with a single field', function () {
        $codec = new UadpNetworkMessageCodec();
        $original = new NetworkMessage(
            publisherId: 100,
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 42,
            timestamp: null,
            dataSetMessages: [
                variantDataSet(7, 1, [['temperature', BuiltinType::Double, 23.5]]),
            ],
        );

        $bytes = $codec->encode($original);
        $decoded = $codec->decode($bytes, readerConfigs(100, 1, [7]));

        expect($decoded->publisherId)->toBe(100);
        expect($decoded->writerGroupId)->toBe(1);
        expect($decoded->sequenceNumber)->toBe(42);
        expect($decoded->dataSetMessages)->toHaveCount(1);
        expect($decoded->dataSetMessages[0]->dataSetWriterId)->toBe(7);
        expect($decoded->dataSetMessages[0]->fieldEncoding)->toBe(FieldEncoding::Variant);
        expect($decoded->dataSetMessages[0]->fields[0]->value)->toBeInstanceOf(Variant::class);
        expect($decoded->dataSetMessages[0]->fields[0]->value->value)->toBe(23.5);
    });

    it('round-trips multiple fields of mixed builtin types', function () {
        $codec = new UadpNetworkMessageCodec();
        $original = new NetworkMessage(
            publisherId: 100,
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 1,
            timestamp: null,
            dataSetMessages: [
                variantDataSet(7, 0, [
                    ['a', BuiltinType::Int32, -123],
                    ['b', BuiltinType::Boolean, true],
                    ['c', BuiltinType::String, 'hello'],
                    ['d', BuiltinType::Double, 3.14],
                ]),
            ],
        );

        $bytes = $codec->encode($original);
        $decoded = $codec->decode($bytes, readerConfigs(100, 1, [7]));
        $dsm = $decoded->dataSetMessages[0];

        expect($dsm->fields[0]->value->value)->toBe(-123);
        expect($dsm->fields[1]->value->value)->toBeTrue();
        expect($dsm->fields[2]->value->value)->toBe('hello');
        expect($dsm->fields[3]->value->value)->toBe(3.14);
    });

    it('round-trips a NetworkMessage carrying multiple DataSetMessages', function () {
        $codec = new UadpNetworkMessageCodec();
        $original = new NetworkMessage(
            publisherId: 100,
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 5,
            timestamp: null,
            dataSetMessages: [
                variantDataSet(7, 0, [['x', BuiltinType::Int32, 1]]),
                variantDataSet(8, 0, [['y', BuiltinType::Int32, 2], ['z', BuiltinType::Int32, 3]]),
            ],
        );

        $bytes = $codec->encode($original);
        $decoded = $codec->decode($bytes, readerConfigs(100, 1, [7, 8]));

        expect($decoded->dataSetMessages)->toHaveCount(2);
        expect($decoded->dataSetMessages[0]->dataSetWriterId)->toBe(7);
        expect($decoded->dataSetMessages[0]->fields[0]->value->value)->toBe(1);
        expect($decoded->dataSetMessages[1]->dataSetWriterId)->toBe(8);
        expect($decoded->dataSetMessages[1]->fields[1]->value->value)->toBe(3);
    });

    it('round-trips a string PublisherId', function () {
        $codec = new UadpNetworkMessageCodec();
        $original = new NetworkMessage(
            publisherId: 'plc-alpha',
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 0,
            timestamp: null,
            dataSetMessages: [variantDataSet(7, 0, [['a', BuiltinType::Int32, 1]])],
        );

        $bytes = $codec->encode($original);
        $decoded = $codec->decode($bytes, readerConfigs('plc-alpha', 1, [7]));

        expect($decoded->publisherId)->toBe('plc-alpha');
    });

    it('round-trips a timestamp when present', function () {
        $codec = new UadpNetworkMessageCodec();
        $ts = new DateTimeImmutable('@1700000000');
        $original = new NetworkMessage(
            publisherId: 100,
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 0,
            timestamp: $ts,
            dataSetMessages: [variantDataSet(7, 0, [['a', BuiltinType::Int32, 1]])],
        );

        $bytes = $codec->encode($original);
        $decoded = $codec->decode($bytes, readerConfigs(100, 1, [7]));

        expect($decoded->timestamp)->not->toBeNull();
        expect($decoded->timestamp->getTimestamp())->toBe(1700000000);
    });

    it('applies metadata field names to decoded fields', function () {
        $codec = new UadpNetworkMessageCodec();
        $original = new NetworkMessage(
            publisherId: 100,
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 0,
            timestamp: null,
            dataSetMessages: [variantDataSet(7, 0, [
                ['temperature', BuiltinType::Double, 21.5],
                ['pressure', BuiltinType::Double, 1.013],
            ])],
        );

        $meta = new DataSetMetaData(
            name: 'Line',
            fields: [
                new FieldMetaData('temperature', BuiltinType::Double),
                new FieldMetaData('pressure', BuiltinType::Double),
            ],
        );
        $reader = new DataSetReaderConfig(100, 1, 7, $meta);
        $readersByKey = [$reader->demuxKey() => $reader];

        $bytes = $codec->encode($original);
        $decoded = $codec->decode($bytes, $readersByKey);

        expect($decoded->dataSetMessages[0]->fields[0]->name)->toBe('temperature');
        expect($decoded->dataSetMessages[0]->fields[1]->name)->toBe('pressure');
    });
});

describe('UadpNetworkMessageCodec — unsupported features', function () {

    it('rejects a secured payload when no PubSubSecurityOptions is configured', function () {
        $codec = new UadpNetworkMessageCodec();
        $publisherId = 100;
        $secureFrame = (new UadpNetworkMessageCodec(
            security: new \PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions(
                \PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode::Sign,
                new \PhpOpcua\Client\ExtTransportPubSub\Security\StaticGroupKeyProvider(
                    str_repeat("\x01", 32),
                    str_repeat("\x02", 32),
                    str_repeat("\x03", 4),
                ),
            ),
        ))->encode(new NetworkMessage(
            publisherId: $publisherId,
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 0,
            timestamp: null,
            dataSetMessages: [variantDataSet(7, 0, [['a', BuiltinType::Int32, 1]])],
        ));

        expect(fn () => $codec->decode($secureFrame, []))
            ->toThrow(\PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException::class, 'no PubSubSecurityOptions');
    });

    it('rejects a payload declaring a non-DataSet NetworkMessageType', function () {
        $codec = new UadpNetworkMessageCodec();
        $payload = chr(0x11 | 0x80) . chr(0x80) . chr(0x04);

        expect(fn () => $codec->decode($payload, []))
            ->toThrow(PubSubDecodeException::class, 'NetworkMessageType');
    });

    it('rejects a DataSetMessage with the valid bit cleared', function () {
        $dsCodec = new \PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpDataSetMessageCodec();
        $decoder = new \PhpOpcua\Client\Encoding\BinaryDecoder(chr(0x00));

        expect(fn () => $dsCodec->decode($decoder, 7, null))
            ->toThrow(PubSubDecodeException::class, 'valid bit');
    });
});

describe('UadpDataSetMessageCodec — RawData encoding', function () {

    it('round-trips RawData fields when metadata is provided', function () {
        $meta = new DataSetMetaData(
            name: 'raw',
            fields: [
                new FieldMetaData('a', BuiltinType::Int32),
                new FieldMetaData('b', BuiltinType::Double),
            ],
        );
        $reader = new DataSetReaderConfig(100, 1, 7, $meta);
        $readersByKey = [$reader->demuxKey() => $reader];

        $rawMessage = new DataSetMessage(
            dataSetWriterId: 7,
            fieldEncoding: FieldEncoding::RawData,
            fields: [
                new DataSetField('a', -42),
                new DataSetField('b', 3.25),
            ],
        );

        $dsCodec = new \PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpDataSetMessageCodec();
        $encoder = new \PhpOpcua\Client\Encoding\BinaryEncoder();
        $dsCodec->encode($encoder, $rawMessage, $meta);

        $decoder = new \PhpOpcua\Client\Encoding\BinaryDecoder($encoder->getBuffer());
        $decoded = $dsCodec->decode($decoder, 7, $meta);

        expect($decoded->fieldEncoding)->toBe(FieldEncoding::RawData);
        expect($decoded->fields[0]->value)->toBe(-42);
        expect($decoded->fields[1]->value)->toBe(3.25);
    });

    it('rejects RawData decoding when metadata is missing', function () {
        $codec = new UadpNetworkMessageCodec();
        $dsm = new DataSetMessage(
            dataSetWriterId: 7,
            fieldEncoding: FieldEncoding::RawData,
            fields: [new DataSetField('a', 1)],
        );
        $meta = new DataSetMetaData(
            name: 'Meta',
            fields: [new FieldMetaData('a', BuiltinType::Int32)],
        );
        $nm = new NetworkMessage(100, 1, 0, 0, null, [$dsm]);

        $dsCodec = new \PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpDataSetMessageCodec();
        $encoder = new \PhpOpcua\Client\Encoding\BinaryEncoder();
        $dsCodec->encode($encoder, $dsm, $meta);
        $decoder = new \PhpOpcua\Client\Encoding\BinaryDecoder($encoder->getBuffer());

        expect(fn () => $dsCodec->decode($decoder, 7, null))
            ->toThrow(PubSubDecodeException::class, 'RawData');
    });
});
