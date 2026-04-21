<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpNetworkMessageCodec;
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetFieldReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\MessageDecodeError;
use PhpOpcua\Client\ExtTransportPubSub\Event\NetworkMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\TransportClosed;
use PhpOpcua\Client\ExtTransportPubSub\Event\TransportOpened;
use PhpOpcua\Client\ExtTransportPubSub\Kernel\PubSubKernel;
use PhpOpcua\Client\ExtTransportPubSub\Module\DataSetReaderModule;
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Unit\Kernel\CollectingDispatcher;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Unit\Kernel\FakeTransport;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetField;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldEncoding;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;

function buildKernel(FakeTransport $transport, array $readers, CollectingDispatcher $dispatcher): PubSubKernel
{
    return new PubSubKernel(
        transports: [$transport],
        codec: new UadpNetworkMessageCodec(),
        modules: [new DataSetReaderModule($readers)],
        eventDispatcher: $dispatcher,
    );
}

function oneFieldUadpPayload(): string
{
    $codec = new UadpNetworkMessageCodec();

    return $codec->encode(new NetworkMessage(
        publisherId: 100,
        writerGroupId: 1,
        networkMessageNumber: 0,
        sequenceNumber: 1,
        timestamp: null,
        dataSetMessages: [
            new DataSetMessage(
                dataSetWriterId: 7,
                fieldEncoding: FieldEncoding::Variant,
                fields: [new DataSetField('temperature', new Variant(BuiltinType::Double, 23.5))],
            ),
        ],
    ));
}

function sampleReader(): DataSetReaderConfig
{
    return new DataSetReaderConfig(
        publisherId: 100,
        writerGroupId: 1,
        dataSetWriterId: 7,
        dataSetMetaData: new DataSetMetaData(
            name: 'Line1',
            fields: [new FieldMetaData('temperature', BuiltinType::Double)],
        ),
    );
}

describe('PubSubKernel — construction', function () {

    it('rejects construction without a DataSetReaderModule', function () {
        expect(fn () => new PubSubKernel(
            transports: [new FakeTransport()],
            codec: new UadpNetworkMessageCodec(),
            modules: [],
        ))->toThrow(InvalidArgumentException::class, 'DataSetReaderModule');
    });
});

describe('PubSubKernel::poll', function () {

    it('decodes one DataSetMessage and dispatches the full event chain', function () {
        $transport = new FakeTransport();
        $transport->enqueue(oneFieldUadpPayload());

        $dispatcher = new CollectingDispatcher();
        $kernel = buildKernel($transport, [sampleReader()], $dispatcher);

        $messages = $kernel->poll(timeoutMs: 10);

        expect($messages)->toHaveCount(1);
        expect($messages[0])->toBeInstanceOf(DataSetMessage::class);
        expect($messages[0]->fields[0]->name)->toBe('temperature');
        expect($messages[0]->fields[0]->value->value)->toBe(23.5);

        expect($dispatcher->of(TransportOpened::class))->toHaveCount(1);
        expect($dispatcher->of(NetworkMessageReceived::class))->toHaveCount(1);
        expect($dispatcher->of(DataSetMessageReceived::class))->toHaveCount(1);
        expect($dispatcher->of(DataSetFieldReceived::class))->toHaveCount(1);
    });

    it('returns an empty array when no payload is queued', function () {
        $transport = new FakeTransport();
        $kernel = buildKernel($transport, [sampleReader()], new CollectingDispatcher());

        expect($kernel->poll(timeoutMs: 1))->toBe([]);
    });

    it('dispatches MessageDecodeError for malformed payloads', function () {
        $transport = new FakeTransport();
        $transport->enqueue("\x00\x00\x00");

        $dispatcher = new CollectingDispatcher();
        $kernel = buildKernel($transport, [sampleReader()], $dispatcher);

        $messages = $kernel->poll(timeoutMs: 5);

        expect($messages)->toBe([]);
        expect($dispatcher->of(MessageDecodeError::class))->toHaveCount(1);
    });
});

describe('PubSubKernel::run and stop', function () {

    it('exits run() when stop() is called inside a user callback', function () {
        $transport = new FakeTransport();
        $transport->enqueue(oneFieldUadpPayload());

        $dispatcher = new CollectingDispatcher();
        $kernel = buildKernel($transport, [sampleReader()], $dispatcher);

        $seen = 0;
        $kernel->onDataSetMessage(function () use ($kernel, &$seen) {
            $seen++;
            $kernel->stop();
        });

        $kernel->run();

        expect($seen)->toBe(1);
        expect($dispatcher->of(TransportClosed::class))->toHaveCount(1);
    });
});

describe('SubscriberBuilder', function () {

    it('builds a Subscriber that polls through a user-supplied transport', function () {
        $transport = new FakeTransport();
        $transport->enqueue(oneFieldUadpPayload());

        $dispatcher = new CollectingDispatcher();
        $received = null;

        $subscriber = SubscriberBuilder::create()
            ->setEventDispatcher($dispatcher)
            ->onDataSetMessage(function (DataSetMessage $dsm) use (&$received) {
                $received = $dsm;
            })
            ->listenOn(
                transports: [$transport],
                readers: [sampleReader()],
            );

        $messages = $subscriber->poll(timeoutMs: 5);

        expect($messages)->toHaveCount(1);
        expect($received)->not->toBeNull();
        expect($received->fields[0]->value->value)->toBe(23.5);
    });

    it('exposes isRunning toggled by run/stop', function () {
        $transport = new FakeTransport();
        $transport->enqueue(oneFieldUadpPayload());

        $subscriber = SubscriberBuilder::create()
            ->listenOn([$transport], [sampleReader()]);

        expect($subscriber->isRunning())->toBeFalse();

        $subscriber->kernel()->onDataSetMessage(fn () => $subscriber->stop());
        $subscriber->run();

        expect($subscriber->isRunning())->toBeFalse();
    });
});
