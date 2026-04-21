# OPC UA PubSub Subscriber for PHP — AI Skills Reference

> Task-oriented recipes for AI coding assistants. Feed this file to your AI (Claude, Cursor, Copilot, GPT, etc.) so it knows how to use `php-opcua/opcua-client-ext-transport-pubsub` correctly.

## How to use this file

Add this file to your AI assistant's context:
- **Claude Code**: copy to your project's `CLAUDE.md` or reference via `--add-file`
- **Cursor**: add to `.cursor/rules/` or `.cursorrules`
- **GitHub Copilot**: add to `.github/copilot-instructions.md`
- **Other tools**: paste into system prompt or project context

---

## Ecosystem Overview

| Package | Install | Purpose |
|---------|---------|---------|
| `php-opcua/opcua-client` | `composer require php-opcua/opcua-client` | Core OPC UA client — required |
| `php-opcua/opcua-client-ext-transport-pubsub` | `composer require php-opcua/opcua-client-ext-transport-pubsub` | PubSub Subscriber (this package) — optional |
| `php-opcua/opcua-session-manager` | `composer require php-opcua/opcua-session-manager` | Session persistence daemon for the classic client — optional |
| `php-opcua/laravel-opcua` | `composer require php-opcua/laravel-opcua` | Laravel integration — optional |
| `php-opcua/opcua-cli` | `composer require php-opcua/opcua-cli` | CLI tool — optional |
| `php-opcua/opcua-client-nodeset` | `composer require php-opcua/opcua-client-nodeset` | Pre-built OPC UA companion types — optional |

**Requirements**: PHP >= 8.2, `ext-sockets`, `ext-openssl` (the last via the core).

---

## Skill: Subscribe to a UDP multicast PubSub stream

### When to use
The user wants to consume process variables broadcast by a PLC, SCADA, or historian via OPC UA PubSub over UDP.

### Code

```php
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;

$metadata = DataSetMetaData::fromJsonFile('/etc/opcua/line1.json');

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage(function (DataSetMessage $msg) {
        foreach ($msg->fields as $field) {
            echo "{$field->name} = {$field->getScalar()}\n";
        }
    })
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        readers: [new DataSetReaderConfig(
            publisherId: 100,
            writerGroupId: 1,
            dataSetWriterId: 1,
            dataSetMetaData: $metadata,
        )],
    );

$subscriber->run();
```

### Important rules
- `run()` blocks until `stop()` is called — run the subscriber in a long-running worker, not in a web request
- `SubscriberBuilder::create()` is the only entry point — never instantiate `Subscriber` or `PubSubKernel` directly
- Endpoint format is always `opc.udp://host:port`
- Readers are matched by the tuple `(publisherId, writerGroupId, dataSetWriterId)` — set `writerGroupId: 0` to match any group

---

## Skill: Listen on unicast UDP

### When to use
The publisher sends directly to this host (not multicast). Firewalls often block multicast, making unicast the pragmatic choice.

### Code

```php
$subscriber = SubscriberBuilder::create()
    ->listenUdp(
        endpoint: 'opc.udp://192.168.1.50:4840',
        readers: [$reader],
    );
```

### Important rules
- Still requires `ext-sockets`
- No multicast group join happens when the endpoint is a unicast IP
- `SO_REUSEADDR` is on by default so multiple subscribers on the same box can bind the same port

---

## Skill: Tune the UDP socket

### When to use
High-rate publishers, large multicast domains, or constrained networks where the defaults drop packets.

### Code

```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;

$subscriber = SubscriberBuilder::create()
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        transport: new UdpOptions(
            interface: '0.0.0.0',       // IPv4 any; '::' for IPv6 any
            receiveBufferSize: 131072,  // SO_RCVBUF — larger buffers reduce drops under burst
            ttl: 8,                     // multicast TTL
            reuseAddress: true,
        ),
        readers: [$reader],
    );
```

### Important rules
- `receiveBufferSize` only takes effect if the OS lets the process request that size; on Linux check `net.core.rmem_max`
- `ttl` is ignored for unicast
- `interface: '0.0.0.0'` is rarely what you want in multi-NIC production; bind to the industrial NIC explicitly

---

## Skill: Decode JSON PubSub payloads

### When to use
The publisher emits JSON instead of UADP (common with cloud gateways or when traffic crosses a boundary that mangles binary).

### Code

```php
$subscriber = SubscriberBuilder::create()
    ->useJson()
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        readers: [$reader],
    );
```

### Important rules
- The codec handles the reversible form (each field carries `{"Type": int, "Body": value}`). Non-reversible JSON is not supported
- `useJson()` is a shortcut for `setCodec(new JsonNetworkMessageCodec())`

---

## Skill: Secure a stream with pre-shared keys

### When to use
The publisher uses `Sign` or `SignAndEncrypt` and keys are distributed out of band (factory floor, isolated network).

### Code

```php
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Security\StaticGroupKeyProvider;

$subscriber = SubscriberBuilder::create()
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        security: new PubSubSecurityOptions(
            mode: PubSubSecurityMode::SignAndEncrypt,
            keyProvider: new StaticGroupKeyProvider(
                signingKey: hex2bin($_ENV['PUBSUB_SIGN']),
                encryptingKey: hex2bin($_ENV['PUBSUB_ENC']),
                keyNonce: hex2bin($_ENV['PUBSUB_NONCE']),
            ),
        ),
        readers: [$reader],
    );
```

### Important rules
- Never hard-code keys in source — use environment variables or a secret manager
- Signing key is 32 bytes (HMAC-SHA256). AES-256-CBC encryption key is 32 bytes. Nonce is 16 bytes
- Payloads that fail signature verification never reach `onDataSetMessage()` — they surface as `SecurityValidationFailed` events instead

---

## Skill: Rotate keys via a Security Key Service

### When to use
Enterprise deployment with periodic key rotation. A classic `Client` connects to the SKS and calls `GetSecurityKeys`.

### Code

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Security\SksGroupKeyProvider;

$client = ClientBuilder::create()->connect('opc.tcp://sks.example.com:4840');

$provider = new SksGroupKeyProvider(
    client: $client,
    securityGroupId: 'line1-ops',
    // Defaults — override only if the server uses custom NodeIds:
    // objectNodeId: 'i=14443',   // Server_PublishSubscribe
    // methodNodeId: 'i=15215',   // GetSecurityKeys
    // securityPolicyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
);

$provider->refresh();

$subscriber = SubscriberBuilder::create()
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        security: new PubSubSecurityOptions(PubSubSecurityMode::SignAndEncrypt, $provider),
        readers: [$reader],
    );
```

### Important rules
- Call `refresh()` at least once before `run()` — the key accessors throw if called before the first refresh
- Call `refresh()` again periodically; `$provider->lifetimeSecondsRemaining()` reports how much of the current token is left
- Supported policies: `PubSub-Aes256-CTR` (32+32+4 key layout) and `PubSub-Aes128-CTR` (32+16+4). Unknown policies throw at refresh time

---

## Skill: Load DataSet metadata

### When to use
The subscriber needs a `DataSetMetaData` for every reader. Five loaders cover the common sources.

### Code

```php
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;

// 1. Inline PHP
$meta = DataSetMetaData::fromArray([
    'name' => 'LineOne',
    'fields' => [
        ['name' => 'temperature', 'builtInType' => 11],
        ['name' => 'pressure',    'builtInType' => 10],
    ],
]);

// 2. JSON file
$meta = DataSetMetaData::fromJsonFile('/etc/opcua/line1.json');

// 3. XML file (compact or OPC UA canonical schema)
$meta = DataSetMetaData::fromXmlFile('/etc/opcua/line1.xml');

// 4. Binary DataSetMetaDataType body (e.g. from a Wireshark capture)
$meta = DataSetMetaData::fromBinary($extensionObjectBody);

// 5. Live, from a connected classic client
$meta = DataSetMetaData::fetchFromServer($client, 'ns=2;s=PDS/Line1/Metadata');
```

### Important rules
- Field order must match the publisher — the subscriber uses metadata order to name fields on decode
- `builtInType` values are OPC UA `BuiltInType` ints (11 = Double, 10 = Float, 6 = Int32, 1 = Boolean, 12 = String)
- `fetchFromServer()` requires a connected `OpcUaClientInterface` from the core package

---

## Skill: Poll instead of blocking on run()

### When to use
The application already has its own event loop (ReactPHP, Amp, custom) and should own the tick cadence.

### Code

```php
while (! $shouldStop) {
    foreach ($subscriber->poll(timeoutMs: 250) as $msg) {
        handle($msg);
    }
    doOtherWork();
}
```

### Important rules
- `poll()` blocks at most `$timeoutMs` and returns the batch of `DataSetMessage` received during this tick
- An empty array means no payload arrived within the timeout — this is normal, not an error
- Transports are opened lazily on the first `poll()`/`run()` call

---

## Skill: Handle graceful shutdown

### When to use
Running the subscriber in a worker supervised by systemd, Kubernetes, or a process manager that sends SIGTERM on shutdown.

### Code

```php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => $subscriber->stop());
pcntl_signal(SIGINT, fn () => $subscriber->stop());

$subscriber->run();
```

### Important rules
- `stop()` is safe to call from a signal handler — it just sets a flag
- `run()` finishes the current tick before returning. Sockets are closed and `TransportClosed` events fire in the `finally` block
- On Windows `pcntl` is not available; use a different termination mechanism (e.g. file flag polled inside a custom `poll()` loop)

---

## Skill: React to lifecycle events

### When to use
You need to track connectivity, decode failures, or security rejections from the outside (metrics, dashboards, alerting).

### Code

```php
use PhpOpcua\Client\ExtTransportPubSub\Event\MessageDecodeError;
use PhpOpcua\Client\ExtTransportPubSub\Event\SecurityValidationFailed;
use PhpOpcua\Client\ExtTransportPubSub\Event\TransportError;

class PubSubTelemetry
{
    public function onDecodeError(MessageDecodeError $e): void
    {
        metrics()->increment('pubsub.decode_error', ['uri' => $e->transportUri]);
    }

    public function onSecurityFail(SecurityValidationFailed $e): void
    {
        metrics()->increment('pubsub.security_fail', ['uri' => $e->transportUri]);
    }

    public function onTransportError(TransportError $e): void
    {
        logger()->error('transport error', ['uri' => $e->transportUri, 'error' => $e->error->getMessage()]);
    }
}

$subscriber = SubscriberBuilder::create()
    ->setEventDispatcher($yourPsr14Dispatcher)
    ->listenUdp(/* ... */);
```

### Important rules
- Any PSR-14 dispatcher works — the package does not bundle one
- Without a dispatcher the kernel uses `NullPubSubEventDispatcher` (zero overhead)
- 8 event classes are dispatched: `NetworkMessageReceived`, `DataSetMessageReceived`, `DataSetFieldReceived`, `MessageDecodeError`, `SecurityValidationFailed`, `TransportOpened`, `TransportClosed`, `TransportError`

---

## Skill: Add structured logging

### When to use
You want to diagnose connection issues, decode failures, or security rejections.

### Code

```php
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$logger = new Logger('pubsub');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$subscriber = SubscriberBuilder::create()
    ->setLogger($logger)
    ->listenUdp(/* ... */);
```

### Important rules
- Any PSR-3 logger works (Monolog, Laravel's logger, etc.)
- Without one the subscriber uses `NullLogger` — no output, no overhead

---

## Skill: Test without a real publisher

### When to use
Unit tests for business logic that reacts to `DataSetMessage` — no network, no Docker, no `ext-sockets` timing.

### Code

```php
use PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Tests\Unit\Kernel\FakeTransport;

$transport = new FakeTransport();
$transport->enqueue($rawUadpBytes);

$subscriber = SubscriberBuilder::create()
    ->listenOn(transports: [$transport], readers: [$reader]);

$messages = $subscriber->poll(timeoutMs: 5);
```

### Important rules
- `FakeTransport` and `CollectingDispatcher` live under `tests/Unit/Kernel/` and are autoloaded by `composer install --dev`
- To test full end-to-end behaviour (including codecs), encode a `NetworkMessage` with `UadpNetworkMessageCodec::encode()` and enqueue the resulting bytes

---

## Skill: Plug in a custom transport (MQTT, AMQP, ...)

### When to use
The publisher emits PubSub frames over a transport this package doesn't ship (MQTT, AMQP, Kafka, WebSocket).

### Code

```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\ReceivedPayload;

class MyMqttTransport implements PubSubTransportInterface
{
    public function open(): void { /* subscribe to MQTT topic */ }
    public function close(): void { /* disconnect */ }
    public function poll(int $timeoutMs): ?ReceivedPayload {
        // wait up to $timeoutMs ms for the next message, return null on timeout
    }
    public function isOpen(): bool { /* ... */ }
    public function transportUri(): string { return 'mqtt://broker/topic'; }
}

$subscriber = SubscriberBuilder::create()
    ->listenOn(transports: [new MyMqttTransport()], readers: [$reader]);
```

### Important rules
- The contract is **synchronous on purpose** — `poll()` must return within `$timeoutMs` with a payload or null. Fiber-based transports suspend a Fiber internally to realise this shape
- `listenOn()` accepts any number of transports; the kernel polls them round-robin
- Implementations of `PubSubTransportInterface` should live in their own package and be published as sibling extensions

---

## Do NOT do

- Do not instantiate `PubSubKernel` or `Subscriber` directly — always go through `SubscriberBuilder`
- Do not add inline comments inside function bodies in PRs — the `CONTRIBUTING.md` rule is the same as in the core
- Do not modify any public name in `php-opcua/opcua-client` to make this package work — it is strictly additive
- Do not run the subscriber inside a synchronous HTTP request — use a long-running worker
- Do not use `Sign` or `SignAndEncrypt` without a key provider — construction throws
- Do not share keys across security groups — rotate per group through separate `GroupKeyProviderInterface` instances

---

## Common mistakes AI models make

- **Calling `run()` inside a request controller.** PubSub needs a long-running process. Use `poll()` with a short timeout if the subscriber must coexist with request handling, but prefer a dedicated worker
- **Mismatched reader tuple.** `publisherId`, `writerGroupId`, and `dataSetWriterId` must match the publisher exactly. Use `writerGroupId: 0` only if the publisher really sends frames with that group id
- **Metadata field order ≠ publisher field order.** The codec names fields by metadata index; scrambled order silently produces wrong names on decode
- **Forgetting `refresh()` on `SksGroupKeyProvider`.** Construction does not fetch keys; the accessors throw until `refresh()` has run at least once
- **Using non-reversible JSON.** This package only speaks the reversible form. Publishers that emit non-reversible JSON need preprocessing
