---
eyebrow: 'Docs · API'
lede:    'SubscriberBuilder configures the pipeline; the Subscriber it returns runs it. The full builder and runtime surface.'

see_also:
  - { href: './transports.md',           meta: '4 min' }
  - { href: './readers-and-metadata.md', meta: '5 min' }
  - { href: '../security/overview.md',   meta: '5 min' }

prev: { label: 'Encodings',   href: '../concepts/encodings.md' }
next: { label: 'Transports',  href: './transports.md' }
---

# Subscriber & builder

`SubscriberBuilder` is the fluent entry point. Its terminal methods
(`listenUdp` / `listenOn`) assemble the kernel and return a `Subscriber`
implementing `OpcUaSubscriberInterface`.

## `SubscriberBuilder`

<!-- @method name="SubscriberBuilder::create(): self" returns="self" visibility="public" -->
Static entry point. Starts with a `NullLogger`, no dispatcher, the UADP codec,
and no extra modules.

<!-- @method name="setLogger(LoggerInterface \$logger): self" returns="self" visibility="public" -->
Attach any PSR-3 logger. Defaults to `NullLogger`.

<!-- @method name="setEventDispatcher(EventDispatcherInterface \$dispatcher): self" returns="self" visibility="public" -->
Attach a PSR-14 dispatcher to receive the [events](./events.md). Without one,
a `NullPubSubEventDispatcher` is used — events are not constructed at all.

<!-- @method name="onDataSetMessage(callable \$callback): self" returns="self" visibility="public" -->
Register a callback fired for every matched `DataSetMessage`. Repeatable —
all registered callbacks run. Signature:
`function (DataSetMessage \$message, int|string \$publisherId, int \$writerGroupId, string \$transportUri): void`.

<!-- @method name="addModule(PubSubModule \$module): self" returns="self" visibility="public" -->
Register a custom [`PubSubModule`](./modules.md). Modules run alongside the
built-in `DataSetReaderModule`.

<!-- @method name="setCodec(NetworkMessageCodec \$codec): self" returns="self" visibility="public" -->
Override the NetworkMessage codec with your own implementation.

<!-- @method name="useJson(): self" returns="self" visibility="public" -->
Use `JsonNetworkMessageCodec` (Part 14 §7.2) instead of the default UADP
codec. Shorthand for `setCodec(new JsonNetworkMessageCodec())`.

<!-- @method name="listenUdp(string \$endpoint, UdpOptions \$transport = new UdpOptions(), array \$readers = [], ?PubSubSecurityOptions \$security = null): Subscriber" returns="Subscriber" visibility="public" -->
Build a subscriber over a single `UdpTransport`.

<!-- @params -->
<!-- @param name="endpoint" type="string" required -->
`opc.udp://host:port`. A multicast address joins that group; a unicast/`0.0.0.0`
address binds and receives unicast.
<!-- @endparam -->
<!-- @param name="transport" type="UdpOptions" -->
Socket tuning — interface, receive buffer, TTL, `SO_REUSEADDR`. See
[Transports](./transports.md).
<!-- @endparam -->
<!-- @param name="readers" type="list<DataSetReaderConfig>" -->
The readers to demux against. Datagrams matching no reader are dropped.
<!-- @endparam -->
<!-- @param name="security" type="?PubSubSecurityOptions" -->
Group-key security. `null` (default) means `None`. See
[Security](../security/overview.md).
<!-- @endparam -->
<!-- @endparams -->

<!-- @method name="listenOn(array \$transports, array \$readers, ?PubSubSecurityOptions \$security = null): Subscriber" returns="Subscriber" visibility="public" -->
Build a subscriber over **several** transports at once (multiple UDP groups,
or custom `PubSubTransportInterface` implementations). The kernel polls them
round-robin.

<!-- @code-block language="php" label="multiple transports" -->
```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenOn(
        transports: [
            new UdpTransport('opc.udp://239.0.0.1:4840', new UdpOptions(interface: '127.0.0.1')),
            new UdpTransport('opc.udp://239.0.0.2:4840'),
        ],
        readers: [$readerA, $readerB],
    );
```
<!-- @endcode-block -->

## `Subscriber` (`OpcUaSubscriberInterface`)

The object returned by the terminals. Four methods:

<!-- @method name="run(): void" returns="void" visibility="public" -->
Block and poll every transport in round-robin until `stop()` is called.
The loop for a dedicated worker process.

<!-- @method name="poll(int \$timeoutMs): list<DataSetMessage>" returns="list<DataSetMessage>" visibility="public" -->
Run one bounded polling pass and return the `DataSetMessage`s decoded during
it (registered callbacks and modules still fire). Use this to integrate with
your own event loop.

<!-- @method name="stop(): void" returns="void" visibility="public" -->
Request the `run()` loop to exit. Safe to call from a signal handler.

<!-- @method name="isRunning(): bool" returns="bool" visibility="public" -->
Whether the `run()` loop is currently active.

<!-- @callout variant="tip" title="run() vs poll()" -->
`run()` is `poll()` in a loop. If you already have an event loop (ReactPHP,
Swoole, a cron tick), call `poll($timeoutMs)` yourself and skip `run()`.
<!-- @endcallout -->
