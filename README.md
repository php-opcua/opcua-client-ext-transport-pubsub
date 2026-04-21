<h1 align="center"><strong>OPC UA PubSub Subscriber for PHP</strong></h1>

<p align="center">
  <a href="https://github.com/php-opcua/opcua-client-ext-transport-pubsub/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/php-opcua/opcua-client-ext-transport-pubsub/tests.yml?branch=master&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://codecov.io/gh/php-opcua/opcua-client-ext-transport-pubsub"><img src="https://img.shields.io/codecov/c/github/php-opcua/opcua-client-ext-transport-pubsub?style=flat-square&logo=codecov" alt="Coverage"></a>
  <a href="https://packagist.org/packages/php-opcua/opcua-client-ext-transport-pubsub"><img src="https://img.shields.io/packagist/v/php-opcua/opcua-client-ext-transport-pubsub?style=flat-square&label=packagist" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/php-opcua/opcua-client-ext-transport-pubsub"><img src="https://img.shields.io/packagist/php-v/php-opcua/opcua-client-ext-transport-pubsub?style=flat-square" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/php-opcua/opcua-client-ext-transport-pubsub?style=flat-square" alt="License"></a>
</p>

<p align="center">
  <img src="https://custom-icon-badges.demolab.com/badge/Linux-✓-2ea44f?style=flat-square&logo=linux&logoColor=white" alt="Linux">
  <img src="https://custom-icon-badges.demolab.com/badge/macOS-✓-2ea44f?style=flat-square&logo=apple&logoColor=white" alt="macOS">
  <img src="https://custom-icon-badges.demolab.com/badge/Windows-✓-2ea44f?style=flat-square&logo=windows11&logoColor=white" alt="Windows">
</p>

---

Subscribe to OPC UA PubSub streams directly from PHP — UDP unicast or multicast, UADP and JSON payloads, group-key security — without any C/C++ extensions, HTTP gateways, or middleware in between.

This package extends [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client) with the OPC UA [Part 14 — PubSub](https://reference.opcfoundation.org/Core/Part14/v105/docs/) Subscriber role. The core client stays unchanged; installing this extension only adds the `PhpOpcua\Client\ExtTransportPubSub\*` namespace. Same zero-dependency philosophy, same PSR-3 / PSR-14 integration, same cross-platform support.

**What you can do with it:**

- **Listen** to PubSub NetworkMessages over UDP unicast or multicast
- **Decode** UADP binary and JSON payloads into typed DataSetMessages with named fields
- **Verify and decrypt** signed and encrypted PubSub streams using pre-shared group keys or a Security Key Service
- **Configure readers** by `(publisherId, writerGroupId, dataSetWriterId)` and let the kernel demux incoming traffic
- **React in real time** via PSR-14 events or plain callbacks on every decoded field

All this with `ext-sockets` as the only extension beyond the core's `ext-openssl`, and PHP 8.2 through 8.5 supported on Linux, macOS, and Windows.

> **Note:** PubSub is a fire-and-forget broadcast paradigm, not a session-oriented protocol. A long-running worker process (ReactPHP, Symfony Messenger, Laravel queue worker, Artisan command, systemd unit) is the natural place to run a `Subscriber`. Short-lived PHP requests cannot sustain a meaningful subscription window.

<table>
<tr>
<td>

### Ships as an extension, not as a replacement

The core `php-opcua/opcua-client` is a tight, zero-dependency library focused on client/server OPC UA. PubSub has a different runtime model (event loop vs request/response), a different wire protocol (UADP), and different security (pre-shared group keys vs per-session asymmetric handshake). Bundling it into the core would inflate the base install for the 95% of users who only need read/write/browse/subscribe.

This package is purely additive. Nothing in the core changes when you `composer require` it.

</td>
</tr>
</table>

----

## Quick Start

```bash
composer require php-opcua/opcua-client-ext-transport-pubsub
```

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
        readers: [
            new DataSetReaderConfig(
                publisherId: 100,
                writerGroupId: 1,
                dataSetWriterId: 1,
                dataSetMetaData: $metadata,
            ),
        ],
    );

$subscriber->run();
```

That's it. Build, configure one reader, block on `run()`. Press Ctrl-C (or call `stop()` from a signal handler) to exit cleanly.

> **Tip:** Prefer `poll(timeoutMs: 500)` over `run()` when you have your own event loop (ReactPHP, Amp, custom). It returns the batch of decoded `DataSetMessage` objects from this tick without blocking past the timeout.

## See It in Action

### Listen on unicast UDP

```php
$subscriber = SubscriberBuilder::create()
    ->listenUdp(
        endpoint: 'opc.udp://192.168.1.50:4840',
        readers: [$reader],
    );
```

### Tune the UDP socket

```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;

$subscriber = SubscriberBuilder::create()
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        transport: new UdpOptions(
            interface: '0.0.0.0',
            receiveBufferSize: 131072,
            ttl: 8,
            reuseAddress: true,
        ),
        readers: [$reader],
    );
```

### Decode JSON payloads

```php
$subscriber = SubscriberBuilder::create()
    ->useJson()
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        readers: [$reader],
    );
```

### Load metadata from JSON, XML, or a live server

```php
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;

// From a JSON config file
$metadata = DataSetMetaData::fromJsonFile('/etc/opcua/line1.json');

// From an OPC UA DataSetMetaDataType XML file
$metadata = DataSetMetaData::fromXmlFile('/etc/opcua/line1.xml');

// From a running server via the classic opcua-client
$metadata = DataSetMetaData::fetchFromServer($client, 'ns=2;s=PDS/Line1/Metadata');
```

### Secure the stream with pre-shared keys

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

### Rotate keys from a Security Key Service

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Security\SksGroupKeyProvider;

$client = ClientBuilder::create()->connect('opc.tcp://sks.example.com:4840');

$provider = new SksGroupKeyProvider($client, securityGroupId: 'line1-ops');
$provider->refresh();

$subscriber = SubscriberBuilder::create()
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        security: new PubSubSecurityOptions(PubSubSecurityMode::SignAndEncrypt, $provider),
        readers: [$reader],
    );
```

### Poll instead of run

```php
while (! $shouldStop) {
    foreach ($subscriber->poll(timeoutMs: 250) as $msg) {
        handle($msg);
    }
    doOtherWork();
}
```

### Graceful shutdown from a signal handler

```php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => $subscriber->stop());
pcntl_signal(SIGINT, fn () => $subscriber->stop());

$subscriber->run();
```

### Receive PSR-14 events for every step

```php
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetFieldReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\MessageDecodeError;
use PhpOpcua\Client\ExtTransportPubSub\Event\SecurityValidationFailed;

$subscriber = SubscriberBuilder::create()
    ->setEventDispatcher($yourDispatcher)
    ->listenUdp(/* ... */);
```

Listeners fire for `NetworkMessageReceived`, `DataSetMessageReceived`, `DataSetFieldReceived`, `MessageDecodeError`, `SecurityValidationFailed`, `TransportOpened`, `TransportClosed`, and `TransportError`. Zero overhead when no dispatcher is configured.

### Add structured logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('pubsub');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$subscriber = SubscriberBuilder::create()
    ->setLogger($logger)
    ->listenUdp(/* ... */);
```

Any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger works. Without one, logging is silently disabled.

### Plug in your own transport

```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;

class MyKafkaTransport implements PubSubTransportInterface { /* ... */ }

$subscriber = SubscriberBuilder::create()
    ->listenOn(
        transports: [new MyKafkaTransport(/* ... */)],
        readers: [$reader],
    );
```

The `PubSubTransportInterface` contract is the extension point that keeps the subscriber agnostic to the underlying transport. External packages like `php-opcua/opcua-client-ext-mqtt` plug in by implementing this interface.

## Why This Package?

- **Zero runtime dependencies beyond the core** — `ext-sockets` plus the core's `ext-openssl`. Optional PSR-3 logging and PSR-14 events via any compatible implementation.
- **PHP 8.2+** — runs on any modern PHP.
- **Native UADP binary** — speaks the OPC UA binary PubSub encoding directly over UDP. No translation layer.
- **UDP unicast and multicast** — IGMP group join with `MCAST_JOIN_GROUP` plus legacy `IP_ADD_MEMBERSHIP` fallback. TTL, buffer size, and `SO_REUSEADDR` exposed as first-class options.
- **JSON codec included** — the reversible form from Part 14 §7.2, round-trippable without external metadata.
- **Group-key security** — HMAC-SHA256 signing and AES-256-CBC encryption handled in pure PHP via `ext-openssl`. Pre-shared keys or live rotation from a Security Key Service.
- **Metadata any way you like** — PHP arrays, JSON files, `DataSetMetaDataType` XML exports, or a live read from the publishing server.
- **Typed everywhere** — every DTO uses `public readonly` properties. No arrays, no magic.
- **Cross-platform** — tested on Linux, macOS, and Windows across PHP 8.2–8.5. No FFI, no COM, no platform-specific APIs beyond `ext-sockets`.
- **Does not modify the core** — `ClientBuilder`, `Client`, `ClientKernel`, `OpcUaClientInterface` are untouched.

## Features

| Feature | What it does |
|---|---|
| **UDP Transport** | Unicast and IPv4 multicast with `MCAST_JOIN_GROUP` (legacy fallback), `SO_REUSEADDR`, non-blocking sockets, `socket_select` timeout |
| **UADP Codec** | Binary PubSub encoding — PublisherId (Byte/UInt16/UInt32/UInt64/String), GroupHeader, PayloadHeader with multiple DataSetMessages, Variant / RawData / DataValue field encodings |
| **JSON Codec** | Reversible JSON encoding from Part 14 §7.2 (encode + decode, no external metadata required) |
| **Subscriber Runtime** | Event loop over N transports, demux by `(publisherId, writerGroupId, dataSetWriterId)`, blocking `run()` and non-blocking `poll()` |
| **Security** | Sign (HMAC-SHA256) and SignAndEncrypt (AES-256-CBC) with pre-shared keys via `StaticGroupKeyProvider` or SKS-backed rotation via `SksGroupKeyProvider` |
| **Metadata Loaders** | `DataSetMetaData::fromArray()`, `fromJsonFile()`, `fromXmlFile()`, `fromXmlString()`, `fromBinary()`, `fetchFromServer()` |
| **PSR-14 Events** | `NetworkMessageReceived`, `DataSetMessageReceived`, `DataSetFieldReceived`, `MessageDecodeError`, `SecurityValidationFailed`, `TransportOpened`, `TransportClosed`, `TransportError` |
| **PSR-3 Logging** | Any PSR-3 logger plugs in. `NullLogger` by default. |
| **Pluggable Transports** | `PubSubTransportInterface` is the stable hook point for third-party transports (e.g. MQTT) |
| **Module System** | `PubSubModule` base class lets you extend the kernel with custom behaviour (telemetry, alarm forwarding, ...) |
| **Typed DTOs** | `public readonly` properties everywhere — `NetworkMessage`, `DataSetMessage`, `DataSetField`, `FieldMetaData`, `DataSetMetaData`, `DataSetReaderConfig`, `UdpOptions`, `ReceivedPayload`, `PubSubSecurityOptions` |

## Documentation

Full docs live under [`docs/`](docs/index.md) (published at <https://www.php-opcua.com/documentation/opcua-client-ext-transport-pubsub>).

| Document | Covers |
|----------|--------|
| [Overview](docs/overview.md) | What it is, when to use it, what ships |
| [Quick start](docs/getting-started/quick-start.md) | Reader + callback + listen, in three steps |
| [How it works](docs/concepts/how-it-works.md) | The transport → codec → security → kernel pipeline |
| [Encodings](docs/concepts/encodings.md) | UADP and JSON, field encodings |
| [Subscriber & builder](docs/api/subscriber.md) | Builder, `run()` / `poll()` / `stop()`, lifecycle |
| [Transports](docs/api/transports.md) | UDP configuration, multicast, custom transports |
| [Readers & metadata](docs/api/readers-and-metadata.md) | `DataSetReaderConfig`, loading `DataSetMetaData` |
| [Modules](docs/api/modules.md) / [Events](docs/api/events.md) | Custom kernel hooks; the 8 PSR-14 events |
| [Group-key security](docs/security/overview.md) | Sign, SignAndEncrypt, static keys, SKS rotation |
| [Testing](docs/testing/overview.md) · [Exceptions](docs/reference/exceptions.md) | Fake transport + dispatcher; error reference |

## Testing

Run `./vendor/bin/pest` after `composer install`. Unit tests cover codec round-trips, UDP loopback send/receive, kernel demux, security unwrap, metadata loaders, and SKS key fetching. CI runs on PHP 8.2, 8.3, 8.4, and 8.5 across Linux, macOS, and Windows via GitHub Actions.

```bash
./vendor/bin/pest                                          # everything
./vendor/bin/pest tests/Unit/                              # unit only
./vendor/bin/pest tests/Integration/ --group=integration   # integration only
```

## Ecosystem

| Package | Description |
|---------|-------------|
| [opcua-client](https://github.com/php-opcua/opcua-client) | Pure PHP OPC UA client (required) |
| [opcua-client-ext-transport-pubsub](https://github.com/php-opcua/opcua-client-ext-transport-pubsub) | OPC UA PubSub Subscriber (this package) |
| [opcua-client-nodeset](https://github.com/php-opcua/opcua-client-nodeset) | Pre-generated PHP types from OPC Foundation companion specifications |
| [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager) | Daemon-based session persistence for the classic client |
| [laravel-opcua](https://github.com/php-opcua/laravel-opcua) | Laravel integration for the classic client |
| [opcua-cli](https://github.com/php-opcua/opcua-cli) | CLI tool — browse, read, write, watch, manage certificates |

## Community

Have questions, ideas, or want to share what you've built? Join the [GitHub Discussions](https://github.com/php-opcua/opcua-client-ext-transport-pubsub/discussions).

**Connected a PubSub-capable device?** We're collecting a community-driven list of tested publishers. Share your experience — even a one-liner like "open62541 1.4, UDP multicast, works fine" helps other users know what to expect.

## AI-Ready

This package ships with machine-readable documentation designed for AI coding assistants (Claude, Cursor, Copilot, ChatGPT, and others). Feed these files to your AI so it knows how to use the library correctly:

| File | Purpose |
|------|---------|
| [`llms.txt`](llms.txt) | Compact project summary — architecture, key classes, API signatures |
| [`llms-full.txt`](llms-full.txt) | Comprehensive technical reference — every class, DTO, encoding detail |
| [`llms-skills.md`](llms-skills.md) | Task-oriented recipes — step-by-step instructions for common tasks |

**How to use:** copy the files you need into your project's AI configuration directory. The files are located in `vendor/php-opcua/opcua-client-ext-transport-pubsub/` after `composer install`.

- **Claude Code**: reference per-session with `--add-file vendor/php-opcua/opcua-client-ext-transport-pubsub/llms-skills.md`
- **Cursor**: copy into your project's rules directory — `cp vendor/php-opcua/opcua-client-ext-transport-pubsub/llms-skills.md .cursor/rules/opcua-pubsub.md`
- **GitHub Copilot**: copy or append into `.github/copilot-instructions.md`
- **Other tools**: paste the content into your system prompt or project knowledge base

## Roadmap

See [ROADMAP.md](ROADMAP.md) for what's coming next.

## Contributing

Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)
