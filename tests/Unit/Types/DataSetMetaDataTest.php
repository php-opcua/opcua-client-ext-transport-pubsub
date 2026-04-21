<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\InvalidDataSetReaderException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldMetaData;
use PhpOpcua\Client\Types\BuiltinType;

describe('DataSetMetaData::fromArray', function () {

    it('builds metadata with typed fields', function () {
        $meta = DataSetMetaData::fromArray([
            'name' => 'LineOne',
            'majorVersion' => 2,
            'minorVersion' => 5,
            'description' => 'telemetry',
            'fields' => [
                ['name' => 'temperature', 'builtInType' => BuiltinType::Double->value],
                ['name' => 'pressure', 'builtInType' => BuiltinType::Float->value, 'valueRank' => -1],
            ],
        ]);

        expect($meta->name)->toBe('LineOne');
        expect($meta->majorVersion)->toBe(2);
        expect($meta->minorVersion)->toBe(5);
        expect($meta->description)->toBe('telemetry');
        expect($meta->fields)->toHaveCount(2);
        expect($meta->fields[0])->toBeInstanceOf(FieldMetaData::class);
        expect($meta->fields[0]->name)->toBe('temperature');
        expect($meta->fields[0]->builtInType)->toBe(BuiltinType::Double);
    });

    it('defaults majorVersion, minorVersion, description when absent', function () {
        $meta = DataSetMetaData::fromArray([
            'name' => 'X',
            'fields' => [['name' => 'a', 'builtInType' => BuiltinType::Int32->value]],
        ]);

        expect($meta->majorVersion)->toBe(1);
        expect($meta->minorVersion)->toBe(0);
        expect($meta->description)->toBeNull();
    });

    it('rejects payload without name', function () {
        expect(fn () => DataSetMetaData::fromArray(['fields' => []]))
            ->toThrow(InvalidDataSetReaderException::class, 'name');
    });

    it('rejects payload without fields', function () {
        expect(fn () => DataSetMetaData::fromArray(['name' => 'X']))
            ->toThrow(InvalidDataSetReaderException::class, 'fields');
    });

    it('rejects field without name', function () {
        expect(fn () => DataSetMetaData::fromArray([
            'name' => 'X',
            'fields' => [['builtInType' => BuiltinType::Int32->value]],
        ]))->toThrow(InvalidDataSetReaderException::class, 'name');
    });

    it('rejects field with unknown builtInType', function () {
        expect(fn () => DataSetMetaData::fromArray([
            'name' => 'X',
            'fields' => [['name' => 'f', 'builtInType' => 9999]],
        ]))->toThrow(InvalidDataSetReaderException::class, 'builtInType');
    });
});

describe('DataSetMetaData::fromJsonFile', function () {

    it('decodes a valid JSON metadata file', function () {
        $path = tempnam(sys_get_temp_dir(), 'pubsub-meta-');
        file_put_contents($path, json_encode([
            'name' => 'FromFile',
            'fields' => [['name' => 'x', 'builtInType' => BuiltinType::Int32->value]],
        ]));

        try {
            $meta = DataSetMetaData::fromJsonFile($path);
            expect($meta->name)->toBe('FromFile');
            expect($meta->fields[0]->name)->toBe('x');
        } finally {
            @unlink($path);
        }
    });

    it('throws on missing file', function () {
        expect(fn () => DataSetMetaData::fromJsonFile('/path/that/does/not/exist/metadata.json'))
            ->toThrow(InvalidDataSetReaderException::class, 'cannot read');
    });

    it('throws on invalid JSON', function () {
        $path = tempnam(sys_get_temp_dir(), 'pubsub-meta-');
        file_put_contents($path, '{not valid json');
        try {
            expect(fn () => DataSetMetaData::fromJsonFile($path))
                ->toThrow(InvalidDataSetReaderException::class, 'invalid JSON');
        } finally {
            @unlink($path);
        }
    });
});

describe('DataSetMetaData::fieldsByName', function () {

    it('returns an associative array keyed by field name', function () {
        $meta = new DataSetMetaData(
            name: 'X',
            fields: [
                new FieldMetaData('a', BuiltinType::Int32),
                new FieldMetaData('b', BuiltinType::Double),
            ],
        );

        $byName = $meta->fieldsByName();
        expect(array_keys($byName))->toBe(['a', 'b']);
        expect($byName['a']->builtInType)->toBe(BuiltinType::Int32);
    });
});
