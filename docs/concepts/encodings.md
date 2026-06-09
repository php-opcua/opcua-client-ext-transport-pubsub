---
eyebrow: 'Docs · Concepts'
lede:    'UADP binary (Part 14 §6.2) and reversible JSON (Part 14 §7.2), plus the three field encodings every DataSetMessage can use.'

see_also:
  - { href: '../api/subscriber.md',  meta: '5 min' }
  - { href: 'https://reference.opcfoundation.org/Core/Part14/v105/docs/6.2', meta: 'external', label: 'Part 14 §6.2 — UADP' }
  - { href: 'https://reference.opcfoundation.org/Core/Part14/v105/docs/7.2', meta: 'external', label: 'Part 14 §7.2 — JSON' }

prev: { label: 'How it works',          href: './how-it-works.md' }
next: { label: 'Subscriber & builder',  href: '../api/subscriber.md' }
---

# Encodings

A `NetworkMessageCodec` turns wire bytes into a `NetworkMessage`. Two ship:

| Codec | Spec | Select with |
| --- | --- | --- |
| `UadpNetworkMessageCodec` | Part 14 §6.2 (binary) | default |
| `JsonNetworkMessageCodec` | Part 14 §7.2 (reversible JSON) | `SubscriberBuilder::useJson()` |

## UADP (default)

UADP is the compact binary encoding most publishers use over UDP. The codec
decodes the NetworkMessage header (publisher id of any width, group header,
payload header with multiple DataSetMessages) and each DataSetMessage body.
It is constructed with optional security:

```php
use PhpOpcua\Client\ExtTransportPubSub\Encoding\UadpNetworkMessageCodec;

$codec = new UadpNetworkMessageCodec(security: $securityOptions);  // or null
```

`SubscriberBuilder` builds this for you and passes the `?PubSubSecurityOptions`
you hand to `listenUdp()` / `listenOn()`.

## JSON

For publishers that emit JSON NetworkMessages, switch the codec:

```php
SubscriberBuilder::create()->useJson()->listenUdp(/* … */);
```

`useJson()` swaps in `JsonNetworkMessageCodec`. (Pass your own codec with
`setCodec()` if you have a custom one — `setCodec()` and `useJson()` both set
the codec override, so use one.)

## PublisherId width

UADP encodes the publisher id as one of several widths. The decoder
recognises Byte, UInt16, UInt32, and UInt64 (surfaced as PHP `int`) and
String (surfaced as PHP `string`). Your `DataSetReaderConfig::$publisherId`
must match both the **type** and the **value** on the wire — a reader with
`publisherId: 100` (int) will not match a publisher that announces `"100"`
(string).

## Field encodings

Each `DataSetMessage` declares how its fields are encoded, exposed as the
`FieldEncoding` enum:

| Case | Value | Meaning |
| --- | --- | --- |
| `FieldEncoding::Variant` | `0` | Each field is a full OPC UA Variant (type travels with the value) |
| `FieldEncoding::RawData` | `1` | Bare values in DataSet order — the layout comes from the reader's `DataSetMetaData` |
| `FieldEncoding::DataValue` | `2` | Variant plus status code and timestamps |

<!-- @callout variant="warning" title="RawData needs metadata" -->
With `RawData` there is no type information on the wire — the decoder relies
entirely on the field order and types in your `DataSetMetaData`. If the
metadata does not match the publisher's DataSet, decoding will be wrong or
fail. See [Readers & metadata](../api/readers-and-metadata.md).
<!-- @endcallout -->

The decoded `FieldEncoding` is available on every message as
`$dataSetMessage->fieldEncoding`.
