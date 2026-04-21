# Architecture

How a UDP datagram becomes a typed field, and where each part lives.

## The message hierarchy

OPC UA PubSub nests three levels; decoding peels them off:

```
NetworkMessage                      ← one UDP datagram
  publisherId (int|string), writerGroupId, networkMessageNumber,
  sequenceNumber, timestamp, dataSetClassId, uadpVersion
  └── DataSetMessage[]              ← one per DataSetWriter in the group
        dataSetWriterId, fieldEncoding (FieldEncoding), status,
        sequenceNumber, timestamp, configVersionMajor/Minor
        └── DataSetField[]          ← the values
              name (string), value (mixed)  →  getScalar(): mixed
```

`NetworkMessage` and `DataSetMessage` are immutable DTOs. `DataSetField::getScalar()` unwraps the raw `value` (which may be a core `DataValue`/`Variant` or a plain scalar depending on the field encoding) to a plain PHP value.

## The pipeline

```
UdpTransport ──► NetworkMessageCodec ──► (PubSubSecurityCodec) ──► PubSubKernel
(ext-sockets)    UADP | JSON              verify/decrypt           demux + dispatch
   │                  │                        │                        │
ReceivedPayload   NetworkMessage          HMAC-SHA256 /            DataSetReaderModule
{data,sourceUri,                          AES-CTR                  holds the reader registry;
 receivedAt}                                                       codec uses it to decode/demux
                                                                        │
                                          onDataSetMessage callbacks + PubSubModules + PSR-14 events
```

1. **`UdpTransport`** reads a datagram (`socket_select` with the poll timeout) and returns a `ReceivedPayload` — or `null` on timeout.
2. **The codec** (`UadpNetworkMessageCodec` default, `JsonNetworkMessageCodec` after `useJson()`) decodes the bytes into a `NetworkMessage`. When `PubSubSecurityOptions` is configured, the security codec verifies the signature and decrypts the payload first.
3. **`PubSubKernel`** is the single-threaded event loop. It opens transports, polls each, runs bytes through the codec, and dispatches.
4. **Demux.** `DataSetReaderModule` (auto-added by the builder from your `DataSetReaderConfig[]`) holds the readers keyed by `(publisherId, writerGroupId, dataSetWriterId)`. The codec receives that registry and uses it to decode each datagram and keep only matching `DataSetMessage`s.

## The demux key

A reader matches on three values that must equal the wire values **by type and value**:

- `publisherId` — `int` (Byte/UInt16/UInt32/UInt64) or `string` (String id). `100` ≠ `"100"`.
- `writerGroupId` — `int`.
- `dataSetWriterId` — `int`.

No match ⇒ the message is dropped silently. This is how one multicast group carrying many writers is filtered to the ones you configured.

## Dispatch order (verified)

Per datagram, the kernel:

1. runs security verify/decrypt; on failure dispatches `SecurityValidationFailed` and stops (datagram dropped).
2. decodes; on failure dispatches `MessageDecodeError` and stops (dropped).
3. on success dispatches `NetworkMessageReceived`.
4. for each matched `DataSetMessage`: dispatches `DataSetMessageReceived`, then per field `DataSetFieldReceived`, then runs every `onDataSetMessage` callback, then every `PubSubModule::onDataSetMessage`.

Transport open/close/poll emit `TransportOpened` / `TransportClosed` / `TransportError`.

## Runtime

`PubSubKernel` has no built-in event loop and is single-threaded. The `Subscriber` exposes:

- **`run(): void`** — block, polling all transports round-robin until `stop()`.
- **`poll(int $timeoutMs): list<DataSetMessage>`** — one bounded pass; returns the messages decoded in it (callbacks/modules/events still fire). Integrate with your own loop (ReactPHP, Swoole, cron).
- **`stop(): void`** / **`isRunning(): bool`**.

`run()` is literally `poll()` in a loop — if you already have a loop, call `poll()` and skip `run()`.

## Dependency boundary with the core

- Requires `php-opcua/opcua-client ^4.4`.
- Reused core types: `Types\BuiltinType` (field types in metadata), `Encoding\BinaryDecoder`/`BinaryEncoder` (UADP/metadata binary), `OpcUaClientInterface` + `Types\NodeId` (for `DataSetMetaData::fetchFromServer()` and `SksGroupKeyProvider`).
- The package adds **no** Publisher role and changes nothing in the core — strictly additive under `PhpOpcua\Client\ExtTransportPubSub\*`.

See [`ENCODINGS.md`](ENCODINGS.md) for codecs/metadata and [`SECURITY.md`](SECURITY.md) for group keys.
