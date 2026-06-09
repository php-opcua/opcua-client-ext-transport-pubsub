---
eyebrow: 'Docs · Overview'
lede:    'The Subscriber side of OPC UA PubSub (Part 14) in pure PHP. Consumes UADP or JSON NetworkMessages over UDP unicast/multicast, demuxes them against configured readers, and hands you decoded DataSetMessages with named fields.'

see_also:
  - { href: './getting-started/installation.md', meta: '2 min' }
  - { href: './concepts/how-it-works.md',        meta: '6 min' }
  - { href: 'https://reference.opcfoundation.org/Core/Part14/v105/docs/', meta: 'external', label: 'OPC UA Part 14 — PubSub' }

prev: { label: 'No previous page', href: '#' }
next: { label: 'Installation',     href: './getting-started/installation.md' }
---

# Overview

`php-opcua/opcua-client-ext-transport-pubsub` implements the **Subscriber**
role of OPC UA PubSub (Part 14), written entirely in PHP. It receives
`NetworkMessage` frames — UADP binary (Part 14 §6.2) or JSON (Part 14 §7.2) —
over UDP unicast and IPv4 multicast, matches each `DataSetMessage` against
the readers you configured, and delivers the decoded fields to your
application as typed DTOs.

It is an **extension** of
[`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client). The
core client is untouched when you install this package; only the
`PhpOpcua\Client\ExtTransportPubSub\*` namespace is added.

## When to use it

PubSub is the right model when a publisher fans telemetry out to many
consumers without a request/response round trip: high-rate sensor streams,
controller-to-controller messaging, plant-wide broadcast over multicast.
Unlike the classic client/server subscription model (`opc.tcp://` with
`CreateSubscription` / monitored items), PubSub is connectionless — the
subscriber just listens.

<!-- @callout variant="info" title="Subscriber only" -->
This package implements the **Subscriber** role. It does not publish
PubSub streams, and it does not change the core `opcua-client`. MQTT / AMQP
/ WebSocket transports each live in their own package implementing
[`PubSubTransportInterface`](./api/transports.md).
<!-- @endcallout -->

## What ships in v4.4.0

| Component | Status |
| --- | --- |
| `SubscriberBuilder` / `Subscriber` (`OpcUaSubscriberInterface`) | Yes — `run()`, `poll()`, `stop()`, `isRunning()` |
| `UdpTransport` + `UdpOptions` (`PubSubTransportInterface`) | Yes — unicast + IPv4 multicast, `ext-sockets` |
| `UadpNetworkMessageCodec` (Part 14 §6.2) | Yes — `Variant` / `RawData` / `DataValue` field encodings |
| `JsonNetworkMessageCodec` (Part 14 §7.2) | Yes — reversible JSON |
| `DataSetReaderConfig` + `DataSetMetaData` | Yes — `fromArray` / `fromJsonFile` / `fromXmlFile` / `fromXmlString` / `fromBinary` / `fetchFromServer` |
| Group-key security (`None` / `Sign` / `SignAndEncrypt`) | Yes — `StaticGroupKeyProvider`, `SksGroupKeyProvider` |
| 8 PSR-14 event classes | Yes — lifecycle, transport, decode, security |
| 4 exception classes | Yes |
| `PubSubModule` extension point | Yes — custom kernel hooks without forking |

## The shape of a subscriber

<!-- @code-block language="php" label="the canonical shape" -->
```php
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage(function (DataSetMessage $msg): void {
        foreach ($msg->fields as $field) {
            echo "{$field->name} = {$field->getScalar()}\n";
        }
    })
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        readers: [new DataSetReaderConfig(
            publisherId: 100,
            writerGroupId: 1,
            dataSetWriterId: 1,
            dataSetMetaData: DataSetMetaData::fromJsonFile('/etc/opcua/line1.json'),
        )],
    );

$subscriber->run();   // blocking event loop
```
<!-- @endcode-block -->

Everything past the builder is covered in [How it works](./concepts/how-it-works.md).
