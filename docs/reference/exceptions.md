---
eyebrow: 'Docs · Reference'
lede:    'The four exception types the package raises, what triggers each, and how the kernel surfaces them.'

see_also:
  - { href: '../api/events.md',        meta: '3 min' }
  - { href: '../security/overview.md', meta: '5 min' }

prev: { label: 'Testing the kernel', href: '../testing/overview.md' }
next: { label: 'No next page',        href: '#' }
---

# Exceptions

All four live in `PhpOpcua\Client\ExtTransportPubSub\Exception`.

| Exception | Extends | Raised when |
| --- | --- | --- |
| `PubSubDecodeException` | `RuntimeException` | A NetworkMessage or DataSetMessage cannot be decoded |
| `PubSubSecurityException` | `RuntimeException` | Signature verification or decryption fails; SKS errors |
| `InvalidDataSetReaderException` | `InvalidArgumentException` | A `DataSetReaderConfig` or `DataSetMetaData` is built with invalid values |
| `UnsupportedTransportException` | `RuntimeException` | A transport cannot be opened or used |

## `PubSubDecodeException`

Thrown by the codecs when wire bytes don't form a valid NetworkMessage /
DataSetMessage (truncated frame, bad header, a `RawData` field that doesn't
match the metadata).

Inside the kernel loop this is **caught**, turned into a
[`MessageDecodeError`](../api/events.md) event, and the datagram is dropped —
one malformed packet never stops the subscriber. You only catch it yourself
when calling a codec directly.

## `PubSubSecurityException`

Thrown when a signed/encrypted datagram fails HMAC-SHA256 verification or
AES-CTR decryption, and by `SksGroupKeyProvider` when `GetSecurityKeys` fails
or a key accessor is used before `refresh()` succeeded.

In the kernel loop a verification/decryption failure is **caught** and emitted
as a [`SecurityValidationFailed`](../api/events.md) event (datagram dropped).
A `refresh()` failure, by contrast, propagates to **you** — handle it in your
rotation logic (see [SKS key rotation](../recipes/sks-key-rotation.md)).

## `InvalidDataSetReaderException`

A configuration-time error, thrown from constructors / factories:

- `DataSetReaderConfig` with a non-positive `writerGroupId` / `dataSetWriterId`
  or a negative integer `publisherId`.
- `DataSetMetaData::from*` when required keys are missing or malformed (no
  `name`, no `fields`, a field without a string `name` or an int `builtInType`,
  an unknown `builtInType` id, …).

Being an `InvalidArgumentException`, it signals a programming/config bug — fix
the input rather than catching it at runtime.

## `UnsupportedTransportException`

Thrown from `PubSubTransportInterface::open()` when a transport cannot be
opened (e.g. the socket can't bind or join the group). In the kernel this
surfaces as a [`TransportError`](../api/events.md) event during `open()`.

<!-- @callout variant="info" title="Two failure regimes" -->
**Per-datagram** problems (`PubSubDecodeException`, verification failures) are
caught by the kernel and turned into events so the loop keeps running.
**Configuration and transport-open** problems surface as exceptions/events you
act on. Match your error handling to the regime.
<!-- @endcallout -->
