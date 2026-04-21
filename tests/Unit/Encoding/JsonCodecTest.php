<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Encoding\JsonNetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetField;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldEncoding;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

describe('JsonNetworkMessageCodec — round-trip', function () {

    it('round-trips a single DataSetMessage with mixed types', function () {
        $codec = new JsonNetworkMessageCodec();
        $original = new NetworkMessage(
            publisherId: 100,
            writerGroupId: 1,
            networkMessageNumber: 0,
            sequenceNumber: 0,
            timestamp: null,
            dataSetMessages: [
                new DataSetMessage(
                    dataSetWriterId: 7,
                    fieldEncoding: FieldEncoding::Variant,
                    fields: [
                        new DataSetField('temperature', new Variant(BuiltinType::Double, 23.5)),
                        new DataSetField('online', new Variant(BuiltinType::Boolean, true)),
                        new DataSetField('label', new Variant(BuiltinType::String, 'line-1')),
                    ],
                    sequenceNumber: 42,
                ),
            ],
        );

        $json = $codec->encode($original);
        $decoded = $codec->decode($json, []);

        expect($decoded->publisherId)->toBe(100);
        expect($decoded->writerGroupId)->toBe(1);
        expect($decoded->dataSetMessages)->toHaveCount(1);

        $dsm = $decoded->dataSetMessages[0];
        expect($dsm->dataSetWriterId)->toBe(7);
        expect($dsm->sequenceNumber)->toBe(42);

        $byName = [];
        foreach ($dsm->fields as $f) {
            $byName[$f->name] = $f->value;
        }
        expect($byName['temperature']->value)->toBe(23.5);
        expect($byName['online']->value)->toBeTrue();
        expect($byName['label']->value)->toBe('line-1');
    });

    it('emits MessageType "ua-data"', function () {
        $codec = new JsonNetworkMessageCodec();
        $nm = new NetworkMessage(
            publisherId: 1,
            writerGroupId: 0,
            networkMessageNumber: 0,
            sequenceNumber: 0,
            timestamp: null,
            dataSetMessages: [new DataSetMessage(7, FieldEncoding::Variant, [])],
        );

        $json = $codec->encode($nm);
        $decoded = json_decode($json, true);

        expect($decoded['MessageType'])->toBe('ua-data');
        expect($decoded['PublisherId'])->toBe('1');
    });

    it('rejects JSON with wrong MessageType', function () {
        $codec = new JsonNetworkMessageCodec();

        expect(fn () => $codec->decode(json_encode(['MessageType' => 'ua-metadata']), []))
            ->toThrow(PubSubDecodeException::class, 'MessageType');
    });

    it('rejects payload with invalid JSON', function () {
        $codec = new JsonNetworkMessageCodec();

        expect(fn () => $codec->decode('{not json', []))
            ->toThrow(PubSubDecodeException::class, 'invalid JSON');
    });

    it('rejects message with non-object Messages entry', function () {
        $codec = new JsonNetworkMessageCodec();

        $json = json_encode([
            'MessageType' => 'ua-data',
            'PublisherId' => '1',
            'Messages' => ['not-an-object'],
        ]);

        expect(fn () => $codec->decode($json, []))
            ->toThrow(PubSubDecodeException::class, 'Messages[0]');
    });
});
