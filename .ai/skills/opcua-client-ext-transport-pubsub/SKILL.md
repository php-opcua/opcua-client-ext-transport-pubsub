---
name: opcua-client-ext-transport-pubsub
description: Receive OPC UA PubSub (Part 14) telemetry in PHP using php-opcua/opcua-client-ext-transport-pubsub v4.4.0 — the Subscriber role. Listens for UADP or JSON NetworkMessages over UDP unicast/multicast, demuxes them against configured readers on (publisherId, writerGroupId, dataSetWriterId), and delivers decoded DataSetMessages with named fields. Use this skill whenever a task involves OPC UA PubSub, UADP, opc.udp://, multicast telemetry, DataSetReader/DataSetMetaData, PubSub group-key security (SKS), or subscribing to a publisher without a client/server session.
license: MIT
compatibility: Requires PHP >= 8.2, ext-sockets (UDP/multicast), and php-opcua/opcua-client ^4.4. ext-openssl is used (via the core) for Sign / SignAndEncrypt. Pure PHP — no C extensions beyond ext-sockets.
metadata:
  package: php-opcua/opcua-client-ext-transport-pubsub
  version: v4.4.0
  ecosystem: php-opcua
  extends: php-opcua/opcua-client
---

# php-opcua/opcua-client-ext-transport-pubsub — v4.4.0 skill

The **Subscriber** role of OPC UA PubSub (Part 14), in pure PHP, as an optional extension of [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client). It receives `NetworkMessage` frames — UADP binary (Part 14 §6.2) or JSON (Part 14 §7.2) — over UDP unicast and IPv4 multicast, matches each `DataSetMessage` against the readers you configure, and hands the decoded fields to your code as typed DTOs. Everything lives under `PhpOpcua\Client\ExtTransportPubSub\*`; the core client is untouched.

## When to use this skill

Activate when any of these apply:

- The task mentions **OPC UA PubSub**, **UADP**, **`opc.udp://`**, multicast telemetry, or "subscribe to a publisher"
- A `DataSetReaderConfig`, `DataSetMetaData`, `NetworkMessage`, `DataSetMessage`, `SubscriberBuilder`, or `UdpTransport` appears in code
- Pre-shared **PubSub group keys** / a **Security Key Service (SKS)** are involved
- High-rate, fan-out, connectionless industrial telemetry (controller-to-controller, plant broadcast)

Do NOT activate for: the classic client/server subscription model (`opc.tcp://` + `CreateSubscription` + monitored items — that's the core `opcua-client`), publishing PubSub streams (this is subscriber-only), or non-OPC-UA messaging.

## The 60-second mental model

```
opc.udp:// datagram
   │
UdpTransport (ext-sockets)        → ReceivedPayload { data, sourceUri, receivedAt }
   │
NetworkMessageCodec               → NetworkMessage           (UADP default, or JSON via useJson())
   │  (+ PubSubSecurityCodec: verify HMAC-SHA256 / decrypt AES-CTR, if configured)
   ▼
PubSubKernel  ── demux on (publisherId, writerGroupId, dataSetWriterId) using your readers
   │
   ├── your onDataSetMessage() callbacks
   ├── your PubSubModules
   └── PSR-14 events
```

The message nests three levels: a **`NetworkMessage`** (one datagram) carries a list of **`DataSetMessage`** (one per writer), each carrying **`DataSetField`** (name + value). `getScalar()` unwraps a field to a plain PHP value.

Four things to know:

1. **Connectionless.** There is no session, no handshake, no `opc.tcp://`. The subscriber just listens; publishers and subscribers in a security group share keys out of band.
2. **Readers are filters + decoders.** A `DataSetReaderConfig` matches the `(publisherId, writerGroupId, dataSetWriterId)` triple on the wire and supplies the `DataSetMetaData` used to decode (essential for `RawData`, which carries no type info). Datagrams matching no reader are dropped.
3. **You drive the loop.** `run()` blocks and polls until `stop()`; `poll(int $timeoutMs)` does one pass and returns the decoded `DataSetMessage`s for your own event loop.
4. **It's subscriber-only and additive.** No Publisher role, no change to the core. Other transports (MQTT, AMQP) are separate packages implementing `PubSubTransportInterface`.

## Quick start

```php
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage(function (
        DataSetMessage $message,
        int|string $publisherId,
        int $writerGroupId,
        string $transportUri,
    ): void {
        foreach ($message->fields as $field) {
            echo "{$field->name} = {$field->getScalar()}\n";
        }
    })
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',                 // multicast group:port
        readers: [new DataSetReaderConfig(
            publisherId: 100,                                  // int or string — must match the wire
            writerGroupId: 1,
            dataSetWriterId: 1,
            dataSetMetaData: DataSetMetaData::fromJsonFile('/etc/opcua/line1.json'),
        )],
    );

$subscriber->run();   // blocking; wire stop() to a signal handler
```

## When to load deeper references

| If the task involves... | Read |
| --- | --- |
| The pipeline, the NetworkMessage/DataSetMessage/field hierarchy, and the demux key | [`references/ARCHITECTURE.md`](references/ARCHITECTURE.md) |
| UADP vs JSON, the three `FieldEncoding`s, loading `DataSetMetaData` | [`references/ENCODINGS.md`](references/ENCODINGS.md) |
| Group-key security: modes, static keys, SKS rotation, the crypto | [`references/SECURITY.md`](references/SECURITY.md) |
| Driving the kernel in tests with a fake transport + collecting dispatcher | [`references/TESTING.md`](references/TESTING.md) |
| Debugging dropped datagrams, demux mismatches, RawData, multicast | [`references/PITFALLS.md`](references/PITFALLS.md) |
| Complete worked examples (multicast worker, JSON, SKS rotation, custom transport/module) | [`assets/recipes.md`](assets/recipes.md) |

## Public API surface (must-know)

All classes are in `PhpOpcua\Client\ExtTransportPubSub`.

| Class / interface | Role |
| --- | --- |
| `SubscriberBuilder` | Fluent entry. `create()`, `setLogger()`, `setEventDispatcher()`, `onDataSetMessage(callable)`, `addModule(PubSubModule)`, `setCodec(NetworkMessageCodec)`, `useJson()`, then terminal `listenUdp(...)` / `listenOn(...)` → `Subscriber`. |
| `Subscriber` (`OpcUaSubscriberInterface`) | `run(): void`, `poll(int $timeoutMs): list<DataSetMessage>`, `stop(): void`, `isRunning(): bool`. |
| `Transport\PubSubTransportInterface` | `open()`, `close()`, `poll(int $timeoutMs): ?ReceivedPayload`, `isOpen()`, `transportUri()`. |
| `Transport\UdpTransport` | `(string $endpoint, ?UdpOptions $options = null)`. Unicast + IPv4 multicast via `ext-sockets`. |
| `Transport\UdpOptions` | `(string $interface = '0.0.0.0', int $receiveBufferSize = 65536, int $ttl = 32, bool $reuseAddress = true)`. |
| `Transport\ReceivedPayload` | readonly `(string $data, string $sourceUri, float $receivedAt, array $metadata = [])`. |
| `Encoding\NetworkMessageCodec` | Interface. Implementations: `UadpNetworkMessageCodec(security: ?PubSubSecurityOptions)`, `JsonNetworkMessageCodec`. |
| `Types\DataSetReaderConfig` | `(int\|string $publisherId, int $writerGroupId, int $dataSetWriterId, DataSetMetaData $dataSetMetaData, ?string $name = null)`. |
| `Types\DataSetMetaData` | `(string $name, FieldMetaData[] $fields, int $majorVersion = 1, int $minorVersion = 0, ?string $description = null)`. Factories: `fromArray`, `fromJsonFile`, `fromXmlFile`, `fromXmlString`, `fromBinary`, `fetchFromServer(OpcUaClientInterface, NodeId\|string)`. |
| `Types\FieldMetaData` | `(string $name, BuiltinType $builtInType, int $valueRank = -1, array $arrayDimensions = [], ?string $description = null)`. |
| `Types\DataSetMessage` | `dataSetWriterId`, `fieldEncoding` (`FieldEncoding`), `fields` (`DataSetField[]`), `sequenceNumber`, `timestamp`, `status`, `configVersionMajor/Minor`. |
| `Types\DataSetField` | `(string $name, mixed $value)` + `getScalar(): mixed`. |
| `Types\FieldEncoding` (enum int) | `Variant = 0`, `RawData = 1`, `DataValue = 2`. |
| `Module\PubSubModule` (abstract) | `onDataSetMessage(DataSetMessage, int\|string $publisherId, int $writerGroupId, string $transportUri): void`, `reset(): void`. |

### Security (`...\Security`)

| Symbol | Shape |
| --- | --- |
| `PubSubSecurityMode` (enum int) | `None = 1`, `Sign = 2`, `SignAndEncrypt = 3` |
| `PubSubSecurityOptions` | `(PubSubSecurityMode $mode, ?GroupKeyProviderInterface $keyProvider = null)` |
| `GroupKeyProviderInterface` | `signingKey()`, `encryptingKey()`, `keyNonce()`, `tokenId()`, `refresh()` |
| `StaticGroupKeyProvider` | `(string $signingKey, string $encryptingKey, string $keyNonce, int $tokenId = 1)` |
| `SksGroupKeyProvider` | `(OpcUaClientInterface $client, string $securityGroupId, NodeId\|string $objectNodeId = 'i=14443', NodeId\|string $methodNodeId = 'i=15215', string $securityPolicyUri = POLICY_AES256_CTR, int $requestedKeyCount = 1)`; consts `POLICY_AES256_CTR`, `POLICY_AES128_CTR` |

Signing is **HMAC-SHA256**; encryption is **AES-CTR** (128/256 by key length).

### Events (PSR-14, `...\Event`) — only when a dispatcher is supplied

| Event | Payload |
| --- | --- |
| `TransportOpened` / `TransportClosed` | `string $transportUri` |
| `TransportError` | `Throwable $error`, `string $transportUri` |
| `SecurityValidationFailed` | `Throwable $error`, `string $transportUri`, `string $reason` |
| `MessageDecodeError` | `Throwable $error`, `string $transportUri`, `string $payloadPreview` |
| `NetworkMessageReceived` | `NetworkMessage $message`, `string $transportUri` |
| `DataSetMessageReceived` | `DataSetMessage $message`, `string $transportUri`, `int\|string $publisherId`, `int $writerGroupId` |
| `DataSetFieldReceived` | `DataSetField $field`, `int $dataSetWriterId`, `int\|string $publisherId`, `int $writerGroupId` |

### Exceptions (`...\Exception`)

`PubSubDecodeException` (RuntimeException), `PubSubSecurityException` (RuntimeException), `InvalidDataSetReaderException` (InvalidArgumentException), `UnsupportedTransportException` (RuntimeException).

## Idiomatic patterns AI agents should follow

1. **Match the publisherId type exactly.** `publisherId: 100` (int) will not match a publisher announcing `"100"` (string). The demux compares both type and value.
2. **Always supply correct `DataSetMetaData`.** With `RawData` field encoding there is no type info on the wire — wrong metadata = wrong/failed decode. Load it from JSON/XML/binary or `fetchFromServer()`.
3. **Use `run()` for a worker, `poll()` for your own loop.** `run()` is `poll()` looped; don't reimplement it. Wrap `run()` shutdown via a signal handler calling `stop()`.
4. **Bind multicast to a specific NIC on multi-homed hosts** via `UdpOptions::$interface`; raise `receiveBufferSize` for bursty publishers.
5. **Call `SksGroupKeyProvider::refresh()` before listening** — its key accessors throw until the first successful refresh — and again to rotate.
6. **Observability via PSR-3 (`setLogger`) and PSR-14 (`setEventDispatcher`).** Watch `MessageDecodeError` / `SecurityValidationFailed` while bringing a publisher up; events aren't even constructed without a dispatcher.
7. **Custom transports implement `PubSubTransportInterface`; custom logic extends `PubSubModule`.** Don't fork.

## Common pitfalls (read before generating code)

- Mismatched `publisherId` type (int vs string) — silent non-match, nothing delivered.
- `RawData` with metadata that doesn't match the publisher's DataSet — garbage or `MessageDecodeError`.
- Using `SksGroupKeyProvider` keys before calling `refresh()` — throws `PubSubSecurityException`.
- Expecting `opc.tcp://` / sessions — PubSub is connectionless; this is the wrong package for client/server subscriptions.
- Forgetting `stop()` in a signal handler, so `run()` never exits.
- Treating one bad datagram as fatal — the kernel drops it and emits an event; the loop continues.

Full catalog in [`references/PITFALLS.md`](references/PITFALLS.md).

## Related packages in the php-opcua ecosystem

- **`opcua-client`** — the core. Used here for `BuiltinType`, the `fetchFromServer()` read, and the SKS `GetSecurityKeys` call. Load its skill for client/server OPC UA.
- **`opcua-client-ext-reverse-connect`** / **`opcua-client-ext-transport-https`** — sibling transport extensions (server-dials-client `opc.tcp://`, and `opc.https://`).
- **`uanetstandard-test-suite`** — ships a UDP+UADP publisher and a Security Key Service used by this package's integration tests.
