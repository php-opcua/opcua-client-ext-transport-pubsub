---
eyebrow: 'Docs · API'
lede:    'Eight PSR-14 events cover the whole pipeline — transport lifecycle, decode, security, and every received message and field. Opt in with a dispatcher; zero cost without one.'

see_also:
  - { href: './subscriber.md',  meta: '5 min' }
  - { href: './modules.md',     meta: '3 min' }

prev: { label: 'Modules',                href: './modules.md' }
next: { label: 'Group-key security',     href: '../security/overview.md' }
---

# Events

Pass a PSR-14 `EventDispatcherInterface` to
`SubscriberBuilder::setEventDispatcher()` and the kernel emits these eight
events. Without a dispatcher the kernel uses `NullPubSubEventDispatcher` and
no event object is constructed — observability is genuinely free when off.

All classes live in `PhpOpcua\Client\ExtTransportPubSub\Event`.

## Transport lifecycle

| Event | Fired when | Payload |
| --- | --- | --- |
| `TransportOpened` | a transport opens successfully | `string $transportUri` |
| `TransportClosed` | a transport closes | `string $transportUri` |
| `TransportError` | a transport fails to open **or** errors while polling | `Throwable $error`, `string $transportUri` |

## Message flow

For each datagram, in order:

| Event | Fired when | Payload |
| --- | --- | --- |
| `SecurityValidationFailed` | signature/decryption fails (datagram dropped) | `Throwable $error`, `string $transportUri`, `string $reason` |
| `MessageDecodeError` | decoding fails (datagram dropped) | `Throwable $error`, `string $transportUri`, `string $payloadPreview` |
| `NetworkMessageReceived` | a NetworkMessage decodes successfully | `NetworkMessage $message`, `string $transportUri` |
| `DataSetMessageReceived` | per matched DataSetMessage in that NetworkMessage | `DataSetMessage $message`, `string $transportUri`, `int\|string $publisherId`, `int $writerGroupId` |
| `DataSetFieldReceived` | per field in a matched DataSetMessage | `DataSetField $field`, `int $dataSetWriterId`, `int\|string $publisherId`, `int $writerGroupId` |

A datagram that triggers `SecurityValidationFailed` or `MessageDecodeError`
produces none of the success events.

## Example

@code-block language="php" label="metrics + audit via PSR-14"
```php
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\MessageDecodeError;
use PhpOpcua\Client\ExtTransportPubSub\Event\SecurityValidationFailed;
use PhpOpcua\Client\ExtTransportPubSub\Event\TransportError;

$dispatcher->listen(DataSetMessageReceived::class, fn ($e) =>
    $metrics->increment('pubsub.message', ['writer' => $e->message->dataSetWriterId]));

$dispatcher->listen(MessageDecodeError::class, fn ($e) =>
    $log->warning('decode failed', ['uri' => $e->transportUri, 'preview' => $e->payloadPreview]));

$dispatcher->listen(SecurityValidationFailed::class, fn ($e) =>
    $log->error('security check failed', ['uri' => $e->transportUri, 'reason' => $e->reason]));

$dispatcher->listen(TransportError::class, fn ($e) =>
    $log->error('transport error', ['uri' => $e->transportUri, 'err' => $e->error->getMessage()]));

SubscriberBuilder::create()
    ->setEventDispatcher($dispatcher)
    ->setLogger($psr3Logger)
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader]);
```
@endcode-block

@callout variant="tip" title="Events vs callbacks"
`onDataSetMessage()` is the place for business logic on matched messages.
Events are the place for cross-cutting concerns — metrics, audit logging,
alerting on `SecurityValidationFailed` / `MessageDecodeError` — and for the
`NetworkMessage`/`DataSetField` granularity the callback does not give you.
@endcallout
