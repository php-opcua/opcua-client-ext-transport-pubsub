<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Exception\InvalidDataSetReaderException;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\Types\NodeId;

describe('DataSetMetaData::fetchFromServer against a real OPC UA server', function () {

    it('throws a typed error when the target Variable holds a non-ExtensionObject value', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            expect(fn () => DataSetMetaData::fetchFromServer($client, NodeId::numeric(0, 2259)))
                ->toThrow(InvalidDataSetReaderException::class, 'ExtensionObject');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('propagates a read error when the target NodeId does not exist', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            expect(fn () => DataSetMetaData::fetchFromServer($client, 'ns=99;s=DoesNotExist'))
                ->toThrow(InvalidDataSetReaderException::class);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
