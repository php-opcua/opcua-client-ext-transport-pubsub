---
eyebrow: 'Docs · Getting started'
lede:    'Describe your readers, hand the builder a callback, and listen. Three steps to a running PubSub subscriber.'

see_also:
  - { href: '../api/subscriber.md',           meta: '5 min' }
  - { href: '../api/readers-and-metadata.md', meta: '5 min' }
  - { href: '../concepts/how-it-works.md',    meta: '6 min' }

prev: { label: 'Installation',  href: './installation.md' }
next: { label: 'How it works',  href: '../concepts/how-it-works.md' }
---

# Quick start

<!-- @steps -->
- **Describe the DataSet you expect**

  A `DataSetReaderConfig` tells the subscriber which publisher/group/writer
  triple to accept and how to decode its fields. The field layout comes from
  a `DataSetMetaData` — load it from JSON, XML, binary, or fetch it from a
  server.

  <!-- @code-block language="php" label="1 — reader + metadata" -->
  ```php
  use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
  use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;

  $reader = new DataSetReaderConfig(
      publisherId: 100,        // int or string — must match the wire
      writerGroupId: 1,
      dataSetWriterId: 1,
      dataSetMetaData: DataSetMetaData::fromJsonFile('/etc/opcua/line1.json'),
  );
  ```
  <!-- @endcode-block -->

- **Build the subscriber and attach a callback**

  <!-- @code-block language="php" label="2 — build + listen" -->
  ```php
  use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
  use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;

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
          endpoint: 'opc.udp://239.0.0.1:4840',   // multicast group:port
          readers: [$reader],
      );
  ```
  <!-- @endcode-block -->

  The callback signature is
  `function (DataSetMessage $message, int|string $publisherId, int $writerGroupId, string $transportUri): void`.
  PHP lets you declare fewer parameters if you only need the message.

- **Run the loop**

  <!-- @code-block language="php" label="3 — run" -->
  ```php
  $subscriber->run();   // blocks, polling every transport until stop()
  ```
  <!-- @endcode-block -->

  Use `run()` for a dedicated worker process. For a custom event loop, call
  `poll(int $timeoutMs)` instead — see [Subscriber & builder](../api/subscriber.md).
<!-- @endsteps -->

## Unicast instead of multicast

Point the endpoint at a plain address/port to receive unicast UDP:

```php
->listenUdp(endpoint: 'opc.udp://0.0.0.0:4840', readers: [$reader]);
```

## JSON publishers

If the publisher emits JSON NetworkMessages (Part 14 §7.2) instead of UADP,
flip the codec before listening:

```php
SubscriberBuilder::create()
    ->useJson()
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader]);
```

<!-- @callout variant="tip" title="Graceful shutdown" -->
`run()` blocks. Wire `stop()` to a signal handler (`pcntl_signal(SIGTERM, fn () => $subscriber->stop())`)
so the loop exits cleanly between polls.
<!-- @endcallout -->
