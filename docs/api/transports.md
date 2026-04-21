---
eyebrow: 'Docs · API'
lede:    'The UDP transport, its socket options, and the PubSubTransportInterface contract you implement to add MQTT, AMQP, or anything else.'

see_also:
  - { href: './subscriber.md',       meta: '5 min' }
  - { href: '../concepts/how-it-works.md', meta: '6 min' }

prev: { label: 'Subscriber & builder', href: './subscriber.md' }
next: { label: 'Readers & metadata',   href: './readers-and-metadata.md' }
---

# Transports

A transport receives raw bytes and hands them to the kernel as a
`ReceivedPayload`. The package ships `UdpTransport`; any other medium plugs
in through `PubSubTransportInterface`.

## `UdpTransport`

Built on `ext-sockets`. Supports UDP unicast and IPv4 multicast.

<!-- @method name="__construct(string \$endpoint, ?UdpOptions \$options = null)" returns="void" visibility="public" -->
<!-- @params -->
<!-- @param name="endpoint" type="string" required -->
`opc.udp://host:port`. A multicast host (e.g. `239.0.0.1`) makes the socket
join that group; any other host binds for unicast.
<!-- @endparam -->
<!-- @param name="options" type="?UdpOptions" -->
Socket tuning. Defaults to `new UdpOptions()`.
<!-- @endparam -->
<!-- @endparams -->

On `open()` the transport binds the socket, sets `SO_REUSEADDR` (when
enabled), applies the receive buffer and TTL, and — for a multicast endpoint —
joins the group via `MCAST_JOIN_GROUP`, falling back to `IP_ADD_MEMBERSHIP`
on older stacks. `poll()` reads with a `socket_select` timeout and returns a
`ReceivedPayload` (or `null` when nothing arrived in the window).

## `UdpOptions`

Readonly tuning struct, all parameters optional:

@params heading="Constructor"
@param name="interface" type="string"
Local interface address to bind / join on. Default `'0.0.0.0'` (all
interfaces). Set to a specific NIC IP to scope multicast membership.
@endparam
@param name="receiveBufferSize" type="int"
`SO_RCVBUF` in bytes. Default `65536`. Raise it for high-rate publishers to
avoid datagram drops.
@endparam
@param name="ttl" type="int"
Multicast TTL / hop limit. Default `32`.
@endparam
@param name="reuseAddress" type="bool"
Set `SO_REUSEADDR` so several subscribers can bind the same group/port.
Default `true`.
@endparam
@endparams

@code-block language="php" label="scoped multicast"
```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;

$transport = new UdpTransport(
    'opc.udp://239.0.0.1:4840',
    new UdpOptions(interface: '10.0.0.5', receiveBufferSize: 262144),
);
```
@endcode-block

## `ReceivedPayload`

What a transport returns from `poll()`. Readonly:

| Property | Type | Meaning |
| --- | --- | --- |
| `data` | `string` | The raw datagram bytes |
| `sourceUri` | `string` | Identifier of where it came from (the transport URI) |
| `receivedAt` | `float` | Receive timestamp (`microtime(true)`) |
| `metadata` | `array` | Optional transport-specific extras (default `[]`) |

## Writing a custom transport

Implement `PubSubTransportInterface` to receive PubSub over a different
medium (MQTT, AMQP, WebSocket, a file replay…):

<!-- @method name="open(): void" returns="void" visibility="public" -->
Open the medium. Throw `UnsupportedTransportException` if it cannot be opened.

<!-- @method name="close(): void" returns="void" visibility="public" -->
Release resources. Should be idempotent.

<!-- @method name="poll(int \$timeoutMs): ?ReceivedPayload" returns="?ReceivedPayload" visibility="public" -->
Wait up to `$timeoutMs` for one message; return a `ReceivedPayload` or `null`
on timeout. Must not block longer than the timeout.

<!-- @method name="isOpen(): bool" returns="bool" visibility="public" -->
Whether the transport is currently open.

<!-- @method name="transportUri(): string" returns="string" visibility="public" -->
A stable identifier used as `sourceUri` / `transportUri` in payloads and events.

Hand your transport to `SubscriberBuilder::listenOn([$myTransport], $readers)`.

@callout variant="info" title="Decoding is the codec's job"
A transport only moves bytes. Decoding (UADP/JSON) and security are handled
downstream by the codec and the kernel — your transport never parses a
NetworkMessage.
@endcallout
