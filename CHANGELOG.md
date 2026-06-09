# Changelog


## [v4.4.0] - 2026-06-09

Aligns the package with the **v4.4.0** ecosystem release and adopts the
transport-extension naming convention.

### Changed — Package rename (breaking)

- Renamed `php-opcua/opcua-client-ext-pubsub` → **`php-opcua/opcua-client-ext-transport-pubsub`**, matching the sibling transport extension [`opcua-client-ext-transport-https`](https://github.com/php-opcua/opcua-client-ext-transport-https).
- PHP namespace `PhpOpcua\Client\ExtPubSub\*` → **`PhpOpcua\Client\ExtTransportPubSub\*`**. Update your `use` statements accordingly.

### Changed — Core v4.4.0

- Requires **`php-opcua/opcua-client` ^4.4** (was `^4.3`).
- `DataSetMetaData::fetchFromServer()` now uses the new v4.4.0 `DataValue::getType()` / `getValue()` accessors instead of the now-`@deprecated` `DataValue::getVariant()`. Behaviour is unchanged for valid DataSetMetaData reads.

## [v4.3.0] - 2026-04-x

First public release. Ships the OPC UA PubSub Subscriber as an optional
extension of [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client).
The core `opcua-client` is not modified in any way by installing this
package — it only adds the `PhpOpcua\Client\ExtTransportPubSub\*` namespace.

### Added

- `SubscriberBuilder::create()` — fluent entry point. Configuration
  methods: `setLogger()`, `setEventDispatcher()`, `onDataSetMessage()`,
  `addModule()`, `setCodec()`, `useJson()`. Terminals: `listenUdp()` and
  `listenOn()`.
- `Subscriber` — thin proxy implementing `OpcUaSubscriberInterface` with
  `run()`, `poll(int $timeoutMs)`, `stop()`, `isRunning()`.
- `PubSubKernel` — event loop over N transports, demux on
  `(publisherId, writerGroupId, dataSetWriterId)`, PSR-14 dispatch,
  module lifecycle. Sibling of `ClientKernel` from the core, no shared
  base.
- `PubSubModule` base class plus `DataSetReaderModule` concrete module.
- Readonly DTOs: `NetworkMessage`, `DataSetMessage`, `DataSetField`,
  `FieldMetaData`, `DataSetMetaData`, `PublishedDataSet`,
  `DataSetReaderConfig`, `ReaderGroupConfig`, `PubSubConnectionConfig`,
  `UdpOptions`, `ReceivedPayload`, plus the `FieldEncoding` enum.

### Transports

- `PubSubTransportInterface` — stable contract for plugging in custom
  transports. `open()`, `close()`, `poll(int $timeoutMs)`, `isOpen()`,
  `transportUri()`.
- `UdpTransport` — full UDP implementation using `ext-sockets`. Unicast
  plus IPv4 multicast via `MCAST_JOIN_GROUP` with legacy
  `IP_ADD_MEMBERSHIP` fallback, `SO_REUSEADDR`, configurable receive
  buffer and TTL, non-blocking sockets, `socket_select` timeout.

### Encoding

- `UadpNetworkMessageCodec` and `UadpDataSetMessageCodec` — Part 14 §6.2
  binary PubSub encoding. PublisherId (Byte, UInt16, UInt32, UInt64,
  String), GroupHeader, PayloadHeader with one or more DataSetMessages,
  optional network-message timestamp. `Variant`, `RawData`, and
  `DataValue` field encodings supported. Chunking, promoted fields, and
  non-DataSet NetworkMessageTypes are rejected with a clear error.
- `JsonNetworkMessageCodec` and `JsonDataSetMessageCodec` — reversible
  JSON encoding from Part 14 §7.2 (encode + decode).

### Security

- `PubSubSecurityMode` enum (`None`, `Sign`, `SignAndEncrypt`).
- `GroupKeyProviderInterface` with two implementations:
  - `StaticGroupKeyProvider` for pre-shared group keys.
  - `SksGroupKeyProvider` for live rotation via a classic
    `OpcUaClientInterface`. Calls `GetSecurityKeys` (Part 14 §8.4.2),
    splits the returned key material per policy layout
    (`PubSub-Aes256-CTR` 32+32+4, `PubSub-Aes128-CTR` 32+16+4),
    exposes `currentTokenId()` and `lifetimeSecondsRemaining()`.
- `PubSubSecurityOptions::unwrap()` — HMAC-SHA256 verification for Sign
  mode, HMAC plus AES-256-CBC decryption for SignAndEncrypt. Payloads
  that fail validation are dropped and surface as
  `SecurityValidationFailed` events.

### Metadata

- `DataSetMetaData::fromArray()`, `fromJsonFile()`, `fromXmlString()`,
  `fromXmlFile()`, `fromBinary()`, `fetchFromServer()`. The XML loader
  accepts both the compact `<DataSetMetaData>` schema and the OPC UA
  `<DataSetMetaDataType>` / `<FieldMetaData>` naming emitted by tooling.
  `fetchFromServer()` reads a Variable value from a connected core
  `OpcUaClientInterface`, honours already-decoded codec results, and
  falls back to the binary decoder for raw ExtensionObject bodies.

### Events (PSR-14)

- `NetworkMessageReceived`, `DataSetMessageReceived`,
  `DataSetFieldReceived`, `MessageDecodeError`,
  `SecurityValidationFailed`, `TransportOpened`, `TransportClosed`,
  `TransportError`.
- `Kernel\NullPubSubEventDispatcher` — zero-overhead fallback when no
  dispatcher is configured.

### Exceptions

- `UnsupportedTransportException`, `PubSubDecodeException`,
  `InvalidDataSetReaderException`, `PubSubSecurityException`.

### Testing

- Unit coverage for codec round-trips (UADP + JSON), UDP loopback
  send/receive, kernel demux, `SubscriberBuilder` wiring, security
  unwrap with valid and tampered payloads, metadata loaders (array,
  JSON, XML, binary, live server via `MockClient`), and
  `SksGroupKeyProvider::refresh()` against `MockClient`.
- `FakeTransport` and `CollectingDispatcher` helpers under
  `tests/Unit/Kernel/` for driving the kernel in isolation.

### Requirements

- PHP >= 8.2 (tested on 8.2, 8.3, 8.4, 8.5)
- `ext-sockets`
- `php-opcua/opcua-client` ^4.4
- `psr/log` ^3.0 and `psr/event-dispatcher` ^1.0 (interface-only)
