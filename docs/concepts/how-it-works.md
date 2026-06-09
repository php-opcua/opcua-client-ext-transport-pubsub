---
eyebrow: 'Docs · Concepts'
lede:    'From a UDP datagram to a typed field: the transport, codec, security, kernel, and module pipeline — and the message hierarchy it produces.'

see_also:
  - { href: './encodings.md',                 meta: '4 min' }
  - { href: '../api/readers-and-metadata.md', meta: '5 min' }
  - { href: '../api/events.md',               meta: '3 min' }

prev: { label: 'Quick start',  href: '../getting-started/quick-start.md' }
next: { label: 'Encodings',    href: './encodings.md' }
---

# How it works

## The message hierarchy

OPC UA PubSub nests three levels. Decoding peels them off one at a time:

```
NetworkMessage                      ← one UDP datagram
  publisherId, writerGroupId, sequenceNumber, timestamp
  └── DataSetMessage[]              ← one per writer in the group
        dataSetWriterId, fieldEncoding, status, config version
        └── DataSetField[]          ← the actual values
              name, value  →  getScalar()
```

- A `NetworkMessage` carries header fields and a list of `DataSetMessage`s.
- A `DataSetMessage` is the payload from one `dataSetWriterId`, decoded with a
  `FieldEncoding` (`Variant`, `RawData`, or `DataValue`).
- A `DataSetField` pairs a `name` (resolved from the reader's metadata) with a
  `value`; `getScalar()` unwraps it to a plain PHP scalar.

## The pipeline

```
UdpTransport  ──►  NetworkMessageCodec  ──►  PubSubSecurityCodec  ──►  PubSubKernel
(ext-sockets)      (UADP or JSON)            (optional)               (demux + dispatch)
   │                    │                         │                        │
ReceivedPayload     NetworkMessage         verify / decrypt          DataSetReaderModule
(bytes + sourceUri)                        (Sign / SignAndEncrypt)   matches each DataSetMessage
                                                                     against your readers
                                                                          │
                                                          your onDataSetMessage callbacks
                                                          + PubSubModules + PSR-14 events
```

1. **`UdpTransport`** receives a datagram and wraps the raw bytes in a
   [`ReceivedPayload`](../api/transports.md) (`data`, `sourceUri`, `receivedAt`).
2. **The codec** (`UadpNetworkMessageCodec` by default, or
   `JsonNetworkMessageCodec` after `useJson()`) decodes the bytes into a
   `NetworkMessage`. When security is configured, the codec verifies the
   signature and decrypts the payload first.
3. **`PubSubKernel`** runs the event loop. It polls every transport, feeds
   bytes through the codec, and routes the result.
4. **Demux.** `DataSetReaderModule` holds your `DataSetReaderConfig`s as a
   registry keyed by **`(publisherId, writerGroupId, dataSetWriterId)`**. The
   codec uses that registry to decode each datagram — a `DataSetMessage` whose
   triple matches a reader is decoded with that reader's metadata; one that
   matches nothing is dropped. The kernel then fires your callbacks,
   `PubSubModule`s, and PSR-14 events for the matched messages.

## Demultiplexing

A reader is identified by three values that must equal the ones on the wire:

<!-- @params heading="Demux key" -->
<!-- @param name="publisherId" type="int|string" required="true" -->
Byte / UInt16 / UInt32 / UInt64 (as `int`) or String. Must match the
publisher's id type and value.
<!-- @endparam -->
<!-- @param name="writerGroupId" type="int" required="true" -->
The WriterGroup id.
<!-- @endparam -->
<!-- @param name="dataSetWriterId" type="int" required="true" -->
The DataSetWriter id within the group.
<!-- @endparam -->
<!-- @endparams -->

Datagrams whose triple matches no configured reader are silently ignored —
that is how one multicast group carrying several writers is filtered down to
the ones you care about.

## The runtime

`PubSubKernel` is single-threaded and has no external event loop. It exposes
two driving modes through the [`Subscriber`](../api/subscriber.md):

- **`run()`** — blocks, polling all transports in round-robin until `stop()`.
  Ideal for a dedicated worker process.
- **`poll(int $timeoutMs)`** — one bounded polling pass; returns and lets you
  integrate with your own loop.

<!-- @callout variant="info" title="Where to extend" -->
The kernel calls every registered [`PubSubModule`](../api/modules.md) for each
decoded `DataSetMessage`, and emits [PSR-14 events](../api/events.md) at each
stage. Custom transports plug in via
[`PubSubTransportInterface`](../api/transports.md). None of this requires
forking the package.
<!-- @endcallout -->
