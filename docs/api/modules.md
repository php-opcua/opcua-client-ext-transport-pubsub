---
eyebrow: 'Docs · API'
lede:    'PubSubModule is the in-process extension point: hook custom behaviour into the kernel for every decoded DataSetMessage, no fork required.'

see_also:
  - { href: './events.md',      meta: '3 min' }
  - { href: './subscriber.md',  meta: '5 min' }

prev: { label: 'Readers & metadata', href: './readers-and-metadata.md' }
next: { label: 'Events',             href: './events.md' }
---

# Modules

The kernel runs a list of `PubSubModule`s. The built-in `DataSetReaderModule`
does the demux-and-decode; your own modules run alongside it for every
decoded `DataSetMessage`.

## `PubSubModule`

Abstract base. Override what you need — both methods have empty defaults.

<!-- @method name="onDataSetMessage(DataSetMessage \$message, int|string \$publisherId, int \$writerGroupId, string \$transportUri): void" returns="void" visibility="public" -->
Called for every `DataSetMessage` the kernel decodes, with the demux key and
the originating transport URI. Default: no-op.

<!-- @method name="reset(): void" returns="void" visibility="public" -->
Called to clear any per-run state. Default: no-op.

@code-block language="php" label="a metrics module"
```php
use PhpOpcua\Client\ExtTransportPubSub\Module\PubSubModule;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;

final class CountByWriter extends PubSubModule
{
    /** @var array<int,int> */
    public array $counts = [];

    public function onDataSetMessage(
        DataSetMessage $message,
        int|string $publisherId,
        int $writerGroupId,
        string $transportUri,
    ): void {
        $this->counts[$message->dataSetWriterId] ??= 0;
        $this->counts[$message->dataSetWriterId]++;
    }

    public function reset(): void
    {
        $this->counts = [];
    }
}
```
@endcode-block

Register it on the builder:

```php
$module = new CountByWriter();

SubscriberBuilder::create()
    ->addModule($module)
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader]);
```

## Modules vs callbacks

`onDataSetMessage()` on the **builder** registers a plain callback; a
**module** is a stateful object with a lifecycle (`reset()`). Use a callback
for a quick handler, a module when you need to hold state, expose results, or
package reusable behaviour.

@callout variant="info" title="Built-in module"
`DataSetReaderModule` (constructed from your `DataSetReaderConfig[]`) is added
automatically by the builder. It holds the reader registry the codec uses to
decode and demux each datagram, so your modules — and your callbacks — only
see `DataSetMessage`s that matched a configured reader.
@endcallout
