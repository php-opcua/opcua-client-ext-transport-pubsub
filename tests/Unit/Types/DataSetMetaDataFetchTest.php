<?php

declare(strict_types=1);

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\ExtTransportPubSub\Exception\InvalidDataSetReaderException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

function buildDataSetMetaDataTypeBinary(string $name, array $fields, int $major, int $minor, ?string $description = null): string
{
    $encoder = new BinaryEncoder();

    $encoder->writeInt32(0);
    $encoder->writeInt32(0);
    $encoder->writeInt32(0);
    $encoder->writeInt32(0);

    $encoder->writeString($name);
    $encoder->writeLocalizedText(new LocalizedText(null, $description));

    $encoder->writeInt32(count($fields));
    foreach ($fields as [$fieldName, $builtInType]) {
        $encoder->writeString($fieldName);
        $encoder->writeLocalizedText(new LocalizedText(null, null));
        $encoder->writeUInt16(0);
        $encoder->writeByte($builtInType->value);
        $encoder->writeNodeId(NodeId::numeric(0, $builtInType->value));
        $encoder->writeInt32(-1);
        $encoder->writeInt32(0);
        $encoder->writeUInt32(0);
        $encoder->writeGuid('00000000-0000-0000-0000-000000000000');
        $encoder->writeInt32(0);
    }

    $encoder->writeGuid('00000000-0000-0000-0000-000000000000');
    $encoder->writeUInt32($major);
    $encoder->writeUInt32($minor);

    return $encoder->getBuffer();
}

describe('DataSetMetaData::fromBinary', function () {

    it('decodes a minimal DataSetMetaDataType binary payload', function () {
        $binary = buildDataSetMetaDataTypeBinary(
            name: 'Line1',
            fields: [['temp', BuiltinType::Double], ['valid', BuiltinType::Boolean]],
            major: 2,
            minor: 3,
            description: 'line 1 telemetry',
        );

        $meta = DataSetMetaData::fromBinary($binary);

        expect($meta->name)->toBe('Line1');
        expect($meta->description)->toBe('line 1 telemetry');
        expect($meta->majorVersion)->toBe(2);
        expect($meta->minorVersion)->toBe(3);
        expect($meta->fields)->toHaveCount(2);
        expect($meta->fields[0]->name)->toBe('temp');
        expect($meta->fields[0]->builtInType)->toBe(BuiltinType::Double);
        expect($meta->fields[1]->name)->toBe('valid');
        expect($meta->fields[1]->builtInType)->toBe(BuiltinType::Boolean);
    });

    it('rejects payloads that contain nested StructureDataTypes', function () {
        $encoder = new BinaryEncoder();
        $encoder->writeInt32(0);
        $encoder->writeInt32(1);

        expect(fn () => DataSetMetaData::fromBinary($encoder->getBuffer()))
            ->toThrow(InvalidDataSetReaderException::class, 'StructureDataTypes');
    });
});

describe('DataSetMetaData::fetchFromServer', function () {

    it('reads a binary-encoded DataSetMetaDataType ExtensionObject from the server', function () {
        $binary = buildDataSetMetaDataTypeBinary(
            name: 'FromServer',
            fields: [['a', BuiltinType::Int32]],
            major: 1,
            minor: 0,
        );

        $metaNodeId = 'ns=2;s=PDS/MetaData';
        $client = MockClient::create();
        $client->onRead($metaNodeId, fn () => new DataValue(
            new Variant(
                BuiltinType::ExtensionObject,
                new ExtensionObject(
                    typeId: NodeId::numeric(0, 14523),
                    encoding: 0x01,
                    body: $binary,
                ),
            ),
        ));

        $meta = DataSetMetaData::fetchFromServer($client, $metaNodeId);

        expect($meta->name)->toBe('FromServer');
        expect($meta->fields[0]->name)->toBe('a');
        expect($meta->fields[0]->builtInType)->toBe(BuiltinType::Int32);
    });

    it('accepts a server response that already contains a decoded DataSetMetaData', function () {
        $canned = DataSetMetaData::fromArray([
            'name' => 'Pre',
            'fields' => [['name' => 'f', 'builtInType' => BuiltinType::Int32->value]],
        ]);

        $metaNodeId = 'ns=2;s=Pre';
        $client = MockClient::create();
        $client->onRead($metaNodeId, fn () => new DataValue(
            new Variant(
                BuiltinType::ExtensionObject,
                new ExtensionObject(
                    typeId: NodeId::numeric(0, 14523),
                    encoding: 0x01,
                    value: $canned,
                ),
            ),
        ));

        $meta = DataSetMetaData::fetchFromServer($client, $metaNodeId);

        expect($meta)->toBe($canned);
    });

    it('throws when the server returns an empty DataValue', function () {
        $metaNodeId = 'ns=2;s=Empty';
        $client = MockClient::create();
        $client->onRead($metaNodeId, fn () => new DataValue(null));

        expect(fn () => DataSetMetaData::fetchFromServer($client, $metaNodeId))
            ->toThrow(InvalidDataSetReaderException::class, 'empty DataValue');
    });

    it('throws when the server returns a non-ExtensionObject', function () {
        $metaNodeId = 'ns=2;s=Wrong';
        $client = MockClient::create();
        $client->onRead($metaNodeId, fn () => new DataValue(
            new Variant(BuiltinType::Int32, 42),
        ));

        expect(fn () => DataSetMetaData::fetchFromServer($client, $metaNodeId))
            ->toThrow(InvalidDataSetReaderException::class, 'ExtensionObject');
    });

    it('throws when ExtensionObject encoding is not binary', function () {
        $metaNodeId = 'ns=2;s=Xml';
        $client = MockClient::create();
        $client->onRead($metaNodeId, fn () => new DataValue(
            new Variant(
                BuiltinType::ExtensionObject,
                new ExtensionObject(
                    typeId: NodeId::numeric(0, 14523),
                    encoding: 0x02,
                    body: '<xml/>',
                ),
            ),
        ));

        expect(fn () => DataSetMetaData::fetchFromServer($client, $metaNodeId))
            ->toThrow(InvalidDataSetReaderException::class, 'binary-encoded');
    });
});
