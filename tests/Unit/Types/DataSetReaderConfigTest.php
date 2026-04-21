<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\InvalidDataSetReaderException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldMetaData;
use PhpOpcua\Client\Types\BuiltinType;

function sampleMeta(): DataSetMetaData
{
    return new DataSetMetaData(
        name: 'Sample',
        fields: [new FieldMetaData('a', BuiltinType::Int32)],
    );
}

describe('DataSetReaderConfig', function () {

    it('exposes readonly configuration values', function () {
        $cfg = new DataSetReaderConfig(
            publisherId: 100,
            writerGroupId: 1,
            dataSetWriterId: 7,
            dataSetMetaData: sampleMeta(),
            name: 'reader-a',
        );

        expect($cfg->publisherId)->toBe(100);
        expect($cfg->writerGroupId)->toBe(1);
        expect($cfg->dataSetWriterId)->toBe(7);
        expect($cfg->name)->toBe('reader-a');
    });

    it('produces a deterministic demuxKey', function () {
        $cfg = new DataSetReaderConfig(100, 1, 7, sampleMeta());

        expect($cfg->demuxKey())->toBe('100|1|7');
    });

    it('uses the publisherId string form in the demuxKey', function () {
        $cfg = new DataSetReaderConfig('plc-001', 1, 7, sampleMeta());

        expect($cfg->demuxKey())->toBe('plc-001|1|7');
    });

    it('rejects negative integer publisherId', function () {
        expect(fn () => new DataSetReaderConfig(-1, 1, 7, sampleMeta()))
            ->toThrow(InvalidDataSetReaderException::class, 'publisherId');
    });

    it('rejects negative writerGroupId', function () {
        expect(fn () => new DataSetReaderConfig(1, -2, 7, sampleMeta()))
            ->toThrow(InvalidDataSetReaderException::class, 'writerGroupId');
    });

    it('rejects zero or negative dataSetWriterId', function () {
        expect(fn () => new DataSetReaderConfig(1, 1, 0, sampleMeta()))
            ->toThrow(InvalidDataSetReaderException::class, 'dataSetWriterId');

        expect(fn () => new DataSetReaderConfig(1, 1, -1, sampleMeta()))
            ->toThrow(InvalidDataSetReaderException::class, 'dataSetWriterId');
    });
});
