<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\InvalidDataSetReaderException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\Types\BuiltinType;

describe('DataSetMetaData::fromXmlString — compact shape', function () {

    it('decodes a minimal <DataSetMetaData> document', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<DataSetMetaData>
    <Name>LineOne</Name>
    <Description>line 1 telemetry</Description>
    <MajorVersion>2</MajorVersion>
    <MinorVersion>5</MinorVersion>
    <Fields>
        <Field>
            <Name>temperature</Name>
            <BuiltInType>11</BuiltInType>
            <ValueRank>-1</ValueRank>
            <Description>°C</Description>
        </Field>
        <Field>
            <Name>pressure</Name>
            <BuiltInType>10</BuiltInType>
        </Field>
    </Fields>
</DataSetMetaData>
XML;

        $meta = DataSetMetaData::fromXmlString($xml);

        expect($meta->name)->toBe('LineOne');
        expect($meta->majorVersion)->toBe(2);
        expect($meta->minorVersion)->toBe(5);
        expect($meta->description)->toBe('line 1 telemetry');
        expect($meta->fields)->toHaveCount(2);
        expect($meta->fields[0]->name)->toBe('temperature');
        expect($meta->fields[0]->builtInType)->toBe(BuiltinType::Double);
        expect($meta->fields[0]->description)->toBe('°C');
        expect($meta->fields[1]->name)->toBe('pressure');
        expect($meta->fields[1]->builtInType)->toBe(BuiltinType::Float);
    });

    it('decodes the OPC UA DataSetMetaDataType / FieldMetaData naming', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<DataSetMetaDataType>
    <Name>CompanionExported</Name>
    <ConfigurationVersion>
        <MajorVersion>1</MajorVersion>
        <MinorVersion>3</MinorVersion>
    </ConfigurationVersion>
    <Fields>
        <FieldMetaData>
            <Name>a</Name>
            <BuiltInType>6</BuiltInType>
            <ValueRank>-1</ValueRank>
        </FieldMetaData>
    </Fields>
</DataSetMetaDataType>
XML;

        $meta = DataSetMetaData::fromXmlString($xml);

        expect($meta->name)->toBe('CompanionExported');
        expect($meta->majorVersion)->toBe(1);
        expect($meta->minorVersion)->toBe(3);
        expect($meta->fields[0]->name)->toBe('a');
        expect($meta->fields[0]->builtInType)->toBe(BuiltinType::Int32);
    });

    it('decodes array dimensions', function () {
        $xml = <<<'XML'
<DataSetMetaData>
    <Name>ArrayDs</Name>
    <Fields>
        <Field>
            <Name>matrix</Name>
            <BuiltInType>6</BuiltInType>
            <ValueRank>2</ValueRank>
            <ArrayDimensions>
                <Dim>3</Dim>
                <Dim>4</Dim>
            </ArrayDimensions>
        </Field>
    </Fields>
</DataSetMetaData>
XML;

        $meta = DataSetMetaData::fromXmlString($xml);
        expect($meta->fields[0]->arrayDimensions)->toBe([3, 4]);
    });

    it('rejects malformed XML', function () {
        expect(fn () => DataSetMetaData::fromXmlString('<unclosed'))
            ->toThrow(InvalidDataSetReaderException::class, 'malformed XML');
    });

    it('rejects XML missing <Fields>', function () {
        $xml = '<DataSetMetaData><Name>X</Name></DataSetMetaData>';

        expect(fn () => DataSetMetaData::fromXmlString($xml))
            ->toThrow(InvalidDataSetReaderException::class, 'Fields');
    });

    it('rejects a field with an unknown BuiltInType', function () {
        $xml = '<DataSetMetaData><Name>X</Name><Fields><Field><Name>bad</Name><BuiltInType>9999</BuiltInType></Field></Fields></DataSetMetaData>';

        expect(fn () => DataSetMetaData::fromXmlString($xml))
            ->toThrow(InvalidDataSetReaderException::class, 'BuiltInType');
    });

    it('rejects XML missing <Name>', function () {
        $xml = '<DataSetMetaData><Fields><Field><Name>x</Name><BuiltInType>6</BuiltInType></Field></Fields></DataSetMetaData>';

        expect(fn () => DataSetMetaData::fromXmlString($xml))
            ->toThrow(InvalidDataSetReaderException::class, 'Name');
    });
});

describe('DataSetMetaData::fromXmlFile', function () {

    it('reads and parses an XML file from disk', function () {
        $path = tempnam(sys_get_temp_dir(), 'pubsub-meta-');
        file_put_contents($path, '<DataSetMetaData><Name>X</Name><Fields><Field><Name>f</Name><BuiltInType>6</BuiltInType></Field></Fields></DataSetMetaData>');

        try {
            $meta = DataSetMetaData::fromXmlFile($path);
            expect($meta->name)->toBe('X');
            expect($meta->fields[0]->name)->toBe('f');
        } finally {
            @unlink($path);
        }
    });

    it('throws on a missing file', function () {
        expect(fn () => DataSetMetaData::fromXmlFile('/nowhere/meta.xml'))
            ->toThrow(InvalidDataSetReaderException::class, 'cannot read');
    });
});
