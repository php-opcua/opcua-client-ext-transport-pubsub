---
eyebrow: 'Docs · Recipes'
lede:    'A complete worker that joins a multicast group, decodes two writers, and prints their fields — with clean shutdown.'

see_also:
  - { href: '../api/subscriber.md',   meta: '5 min' }
  - { href: '../api/transports.md',   meta: '4 min' }

prev: { label: 'Group-key security',  href: '../security/overview.md' }
next: { label: 'Rotating keys with an SKS', href: './sks-key-rotation.md' }
---

# Subscribe to a multicast group

A runnable worker: join `239.0.0.1:4840`, accept two DataSetWriters from one
publisher, and print each field. `SIGTERM` stops the loop cleanly.

<!-- @code-block language="php" label="worker.php" -->
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;

$meta = DataSetMetaData::fromJsonFile('/etc/opcua/line1.json');

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage(function (
        DataSetMessage $message,
        int|string $publisherId,
        int $writerGroupId,
    ): void {
        $line = "[w{$message->dataSetWriterId}] ";
        foreach ($message->fields as $field) {
            $line .= "{$field->name}={$field->getScalar()} ";
        }
        echo $line . PHP_EOL;
    })
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        transport: new UdpOptions(
            interface: '10.0.0.5',      // scope membership to one NIC
            receiveBufferSize: 262144,  // headroom for bursts
        ),
        readers: [
            new DataSetReaderConfig(100, 1, 1, $meta),   // writer 1
            new DataSetReaderConfig(100, 1, 2, $meta),   // writer 2
        ],
    );

// Clean shutdown between polls
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => $subscriber->stop());
pcntl_signal(SIGINT,  fn () => $subscriber->stop());

$subscriber->run();
echo "stopped\n";
```
<!-- @endcode-block -->

## Notes

- **Scope multicast to a NIC.** On a multi-homed host, set `UdpOptions::$interface`
  to the address that should receive the group, otherwise membership is joined
  on `0.0.0.0`.
- **One group, many writers.** Both readers share the same metadata here; give
  each its own `DataSetMetaData` if the writers carry different DataSets.
- **Drop nothing silently in dev.** While bringing a publisher up, attach a
  PSR-14 dispatcher and watch [`MessageDecodeError`](../api/events.md) — a
  reader-key or metadata mismatch shows up there.

<!-- @callout variant="tip" title="Custom loop instead of run()" -->
Swap `run()` for a `poll()` loop if you already have a scheduler:

```php
while ($keepGoing) {
    $subscriber->poll(timeoutMs: 200);   // returns the DataSetMessages decoded
    // …your periodic work…
}
```
<!-- @endcallout -->
