---
eyebrow: 'Docs · Testing'
lede:    'Drive the subscriber with a fake transport and a collecting dispatcher — no real sockets, no real publisher, fully deterministic.'

see_also:
  - { href: '../api/transports.md', meta: '4 min' }
  - { href: '../api/events.md',     meta: '3 min' }

prev: { label: 'Rotating keys with an SKS', href: '../recipes/sks-key-rotation.md' }
next: { label: 'Exceptions',                href: '../reference/exceptions.md' }
---

# Testing the kernel in isolation

Because a transport is just `PubSubTransportInterface`, you can feed canned
bytes to the subscriber without opening a UDP socket. The package's own suite
does exactly this with a `FakeTransport` (queues raw datagrams) and a
`CollectingDispatcher` (records every PSR-14 event); both are trivial to
reproduce.

## A fake transport

@code-block language="php" label="FakeTransport"
```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\ReceivedPayload;

final class FakeTransport implements PubSubTransportInterface
{
    /** @var list<string> */
    private array $queue = [];
    private bool $open = false;

    public function __construct(public string $uri = 'fake://test') {}

    public function enqueue(string $bytes): void { $this->queue[] = $bytes; }

    public function open(): void  { $this->open = true; }
    public function close(): void { $this->open = false; }
    public function isOpen(): bool { return $this->open; }
    public function transportUri(): string { return $this->uri; }

    public function poll(int $timeoutMs): ?ReceivedPayload
    {
        $bytes = array_shift($this->queue);
        return $bytes === null
            ? null
            : new ReceivedPayload($bytes, $this->uri, 0.0);
    }
}
```
@endcode-block

## A collecting dispatcher

@code-block language="php" label="CollectingDispatcher"
```php
use Psr\EventDispatcher\EventDispatcherInterface;

final class CollectingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        return $event;
    }

    /** @param class-string $class @return list<object> */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, fn ($e) => $e instanceof $class));
    }
}
```
@endcode-block

## Drive it with `poll()`

Enqueue a real UADP/JSON datagram (capture one from your publisher, or build
it with the codecs), then assert on what came out:

@code-block language="php" label="a test"
```php
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;

$transport  = new FakeTransport();
$dispatcher = new CollectingDispatcher();

$subscriber = SubscriberBuilder::create()
    ->setEventDispatcher($dispatcher)
    ->listenOn(
        transports: [$transport],
        readers: [$reader],
    );

$transport->enqueue($capturedDatagramBytes);

$messages = $subscriber->poll(timeoutMs: 0);   // returns list<DataSetMessage>

expect($messages)->toHaveCount(1);
expect($dispatcher->of(DataSetMessageReceived::class))->toHaveCount(1);
```
@endcode-block

`poll()` returns the `DataSetMessage`s decoded in that pass, and the
dispatcher captures every event — together they give you full visibility with
no I/O. Reuse the same fixtures across security modes by wrapping the bytes
with the matching `PubSubSecurityOptions`.

@callout variant="tip" title="Fetching metadata in tests"
`DataSetMetaData::fetchFromServer()` and `SksGroupKeyProvider` both take an
`OpcUaClientInterface` — pass the core's `MockClient` to test those paths
without a server.
@endcallout
