# Testing

Because a transport is just `PubSubTransportInterface`, you can feed canned datagrams to the subscriber with no real socket and no publisher.

## Fake transport

Implement the 5-method contract; queue raw bytes and hand them out on `poll()`:

```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\ReceivedPayload;

final class FakeTransport implements PubSubTransportInterface
{
    /** @var list<string> */ private array $queue = [];
    private bool $open = false;

    public function __construct(public string $uri = 'fake://test') {}
    public function enqueue(string $bytes): void { $this->queue[] = $bytes; }

    public function open(): void  { $this->open = true; }
    public function close(): void { $this->open = false; }
    public function isOpen(): bool { return $this->open; }
    public function transportUri(): string { return $this->uri; }

    public function poll(int $timeoutMs): ?ReceivedPayload
    {
        $b = array_shift($this->queue);
        return $b === null ? null : new ReceivedPayload($b, $this->uri, 0.0);
    }
}
```

(The package's own suite ships exactly this as `FakeTransport`, plus a `CollectingDispatcher`, under its `...\Tests\Unit\Kernel` namespace.)

## Collecting dispatcher

```php
use Psr\EventDispatcher\EventDispatcherInterface;

final class CollectingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */ public array $events = [];
    public function dispatch(object $event): object { $this->events[] = $event; return $event; }
    /** @param class-string $c @return list<object> */
    public function of(string $c): array { return array_values(array_filter($this->events, fn ($e) => $e instanceof $c)); }
}
```

## Drive it with `poll()`

```php
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;

$transport  = new FakeTransport();
$dispatcher = new CollectingDispatcher();

$subscriber = SubscriberBuilder::create()
    ->setEventDispatcher($dispatcher)
    ->listenOn(transports: [$transport], readers: [$reader]);

$transport->enqueue($capturedDatagramBytes);     // a real UADP/JSON datagram

$messages = $subscriber->poll(timeoutMs: 0);     // list<DataSetMessage>

expect($messages)->toHaveCount(1);
expect($dispatcher->of(DataSetMessageReceived::class))->toHaveCount(1);
```

`poll()` returns the `DataSetMessage`s decoded that pass; the dispatcher captures every event — full visibility, no I/O. Reuse the same captured bytes across security modes by wrapping them with the matching `PubSubSecurityOptions`.

## Where to get datagram bytes

- Capture one off the wire from a real publisher (`tcpdump`/socket dump) and commit it as a fixture.
- Or build one with the codecs in a fixture script (the package's integration tests run against the `uanetstandard-test-suite` UDP+UADP publisher).

## Testing the client-backed paths

`DataSetMetaData::fetchFromServer()` and `SksGroupKeyProvider` both take an `OpcUaClientInterface` — pass the **core's `MockClient`** to exercise those paths without a server (queue the metadata read / `GetSecurityKeys` `CallResult`).

## Style

Match the ecosystem bar: strict types, full PHPDoc, no body comments, Pest, cross-platform. Cover the happy path plus every error branch (`MessageDecodeError` on a truncated frame, `SecurityValidationFailed` on a bad signature, `InvalidDataSetReaderException` on bad config).
