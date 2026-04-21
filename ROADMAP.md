# Roadmap

## Next minor releases

### Publisher role

The current package implements the **Subscriber** side only. A subscriber is what a PHP
application needs in 95% of cases — consume process variables broadcast by a PLC or SCADA
system. The **Publisher** side (a PHP process actively broadcasting `DataSetWriter` messages)
is not shipped here because it only makes sense in a long-running daemon, not in a
short-lived request.

A separate package (tentative name `opcua-pubsub-daemon`) is the right home for Publisher.
It would depend on this one for the shared wire format (UADP encode, JSON encode,
`PubSubSecurityOptions::wrap()`) but add its own scheduling and state-keeping. Contributions
that extract the encode paths here into cleaner reusable primitives are welcome.

### Integration test rig against open62541

Unit tests cover every codec path, the UDP loopback, the kernel demux, security unwrap,
and the SKS key fetch via `MockClient`. A full integration rig still needs to stand up:

- An `open62541` publisher in a Docker service (UA .NET Standard does not ship a UDP
  publisher out of the box, so the existing `uanetstandard-test-suite` cannot host it).
- Fixture captures committed under `tests/Integration/Fixtures/` covering every common
  UADP flag combination.
- Secured-stream integration against a publisher configured with `PubSub-Aes256-CTR`.

### IDE helper stub generator

- [ ] A `composer generate-ide-helper` command that auto-generates
      `_ide_helper_opcua_pubsub.php` from the registered modules via reflection. The stub
      file contains PHPDoc `@method` annotations for the `Subscriber` class covering both
      built-in and custom modules. The file is not loaded at runtime — only consumed by
      the IDE for autocomplete and static analysis.

### PHPStan level 5

- [ ] Static analysis with `phpstan/phpstan` as a dev dependency, CI integration,
      `composer analyse` script. Target level 5 first; raise in subsequent releases.

---

## Future work

### MQTT transport

The `PubSubTransportInterface` contract is the stable extension point. MQTT belongs in its
own package (`php-opcua/opcua-client-ext-mqtt`) that depends on this one and provides a
concrete `MqttTransport` implementation. No change to this package is required once that
lands.

### AMQP and WebSocket transports

Same pattern as MQTT — a separate package implementing `PubSubTransportInterface`.
Not planned in-house; contributions welcome once the MQTT package demonstrates the
contract handles Fiber-based transports cleanly.

### Additional NetworkMessage types

This release supports `DataKeyFrame` DataSetMessages — the common case. `DeltaFrame`,
`Event`, and `KeepAlive` types, plus chunked NetworkMessages and PromotedFields, are
rejected at decode time with a clear error. Real-world traffic captures would drive the
prioritisation here.

### NodeSet2.xml import for PublishedDataSet discovery

Today `DataSetMetaData::fromXmlFile()` reads a standalone `DataSetMetaDataType` export.
A future helper could browse a connected server's PublishSubscribe object, enumerate
every `PublishedDataSet`, and materialise the matching `DataSetReaderConfig` set without
any manual wiring.

---

## Won't do (by design)

### Bundle MQTT or Kafka support in-tree

This package stays focused on UDP plus UADP/JSON — the common on-prem deployment. Anything
that requires a runtime dependency (MQTT broker client, Kafka client, Amp loop, ReactPHP
event loop) lives in a downstream package that provides its own `PubSubTransportInterface`
implementation. Keeps the base install small and dependency-free.

### Modify the core `opcua-client` public names

`ClientBuilder`, `Client`, `ClientKernel`, `ClientKernelInterface`, and
`OpcUaClientInterface` stay exactly as they are. This package is strictly additive — if a
change feels necessary on the core side, open an issue on the core repository first and
discuss it there.

### Session persistence for the subscriber

PubSub is stateless by design; there is nothing to "persist" across PHP requests the way
`opcua-session-manager` does for the classic client. Run the subscriber in a long-running
worker and it handles its own state naturally.
