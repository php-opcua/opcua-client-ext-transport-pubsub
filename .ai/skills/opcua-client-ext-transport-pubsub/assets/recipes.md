# Recipes — complete working examples

Copy-pasteable snippets for OPC UA PubSub subscribing. Every recipe is end-to-end runnable. Field access is `$field->name` / `$field->getScalar()`.

## R1 — Multicast worker with clean shutdown

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
    ->onDataSetMessage(function (DataSetMessage $message, int|string $publisherId, int $writerGroupId): void {
        $out = "[w{$message->dataSetWriterId}] ";
        foreach ($message->fields as $f) {
            $out .= "{$f->name}={$f->getScalar()} ";
        }
        echo $out . PHP_EOL;
    })
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        transport: new UdpOptions(interface: '10.0.0.5', receiveBufferSize: 262144),
        readers: [
            new DataSetReaderConfig(100, 1, 1, $meta),
            new DataSetReaderConfig(100, 1, 2, $meta),
        ],
    );

pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => $subscriber->stop());
pcntl_signal(SIGINT,  fn () => $subscriber->stop());

$subscriber->run();
echo "stopped\n";
```

## R2 — Unicast

```php
->listenUdp(endpoint: 'opc.udp://0.0.0.0:4840', readers: [$reader]);
```

## R3 — JSON publisher

```php
SubscriberBuilder::create()
    ->useJson()                                  // JsonNetworkMessageCodec (Part 14 §7.2)
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader]);
```

## R4 — Metadata inline (no file)

```php
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;

$meta = DataSetMetaData::fromArray([
    'name' => 'Line1',
    'majorVersion' => 1,
    'fields' => [
        ['name' => 'Temperature', 'builtInType' => 11],  // 11 = Double
        ['name' => 'Pressure',    'builtInType' => 11],
        ['name' => 'Running',     'builtInType' => 1],    // 1 = Boolean
    ],
]);
```

## R5 — Several groups at once

```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpTransport;
use PhpOpcua\Client\ExtTransportPubSub\Transport\UdpOptions;

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenOn(
        transports: [
            new UdpTransport('opc.udp://239.0.0.1:4840', new UdpOptions(interface: '10.0.0.5')),
            new UdpTransport('opc.udp://239.0.0.2:4840'),
        ],
        readers: [$readerA, $readerB],
    );
```

## R6 — SignAndEncrypt with static keys

```php
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Security\StaticGroupKeyProvider;

$security = new PubSubSecurityOptions(
    mode: PubSubSecurityMode::SignAndEncrypt,
    keyProvider: new StaticGroupKeyProvider(
        signingKey:    hex2bin(getenv('PUBSUB_SIGN_KEY')),
        encryptingKey: hex2bin(getenv('PUBSUB_ENC_KEY')),   // 32 bytes for AES-256
        keyNonce:      hex2bin(getenv('PUBSUB_NONCE')),
    ),
);

SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader], security: $security);
```

## R7 — SKS-rotated keys (poll loop)

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Security\SksGroupKeyProvider;

$keys = new SksGroupKeyProvider(
    client: ClientBuilder::create()->connect('opc.tcp://sks.plant.local:4840'),
    securityGroupId: 'group-1',
    securityPolicyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
);
$keys->refresh();   // MANDATORY before use

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        readers: [$reader],
        security: new PubSubSecurityOptions(PubSubSecurityMode::SignAndEncrypt, $keys),
    );

$next = time() + 3600;
while ($running) {
    $subscriber->poll(timeoutMs: 200);
    if (time() >= $next) {
        try { $keys->refresh(); } catch (\Throwable $e) { /* keep last-good or stop */ }
        $next = time() + 3600;
    }
}
```

## R8 — Observability via PSR-14

```php
use PhpOpcua\Client\ExtTransportPubSub\Event\DataSetMessageReceived;
use PhpOpcua\Client\ExtTransportPubSub\Event\MessageDecodeError;
use PhpOpcua\Client\ExtTransportPubSub\Event\SecurityValidationFailed;

$dispatcher->listen(DataSetMessageReceived::class, fn ($e) =>
    $metrics->increment('pubsub.msg', ['writer' => $e->message->dataSetWriterId]));
$dispatcher->listen(MessageDecodeError::class, fn ($e) =>
    $log->warning('decode failed', ['uri' => $e->transportUri, 'preview' => $e->payloadPreview]));
$dispatcher->listen(SecurityValidationFailed::class, fn ($e) =>
    $log->error('security failed', ['uri' => $e->transportUri, 'reason' => $e->reason]));

SubscriberBuilder::create()
    ->setEventDispatcher($dispatcher)
    ->setLogger($psr3Logger)
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader]);
```

## R9 — Custom module (stateful, reusable)

```php
use PhpOpcua\Client\ExtTransportPubSub\Module\PubSubModule;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;

final class LastValueCache extends PubSubModule
{
    /** @var array<string,mixed> */ public array $latest = [];

    public function onDataSetMessage(DataSetMessage $m, int|string $pub, int $wg, string $uri): void
    {
        foreach ($m->fields as $f) {
            $this->latest[$f->name] = $f->getScalar();
        }
    }

    public function reset(): void { $this->latest = []; }
}

$cache = new LastValueCache();
SubscriberBuilder::create()->addModule($cache)->listenUdp(/* … */);
```

## R10 — Custom transport (e.g. replay from a file)

```php
use PhpOpcua\Client\ExtTransportPubSub\Transport\PubSubTransportInterface;
use PhpOpcua\Client\ExtTransportPubSub\Transport\ReceivedPayload;

final class FileReplayTransport implements PubSubTransportInterface
{
    /** @var list<string> */ private array $frames;
    public function __construct(string $path, private string $uri = 'file://replay')
    { $this->frames = array_map('base64_decode', file($path, FILE_IGNORE_NEW_LINES)); }

    public function open(): void {}
    public function close(): void {}
    public function isOpen(): bool { return true; }
    public function transportUri(): string { return $this->uri; }
    public function poll(int $timeoutMs): ?ReceivedPayload
    {
        $b = array_shift($this->frames);
        return $b === null ? null : new ReceivedPayload($b, $this->uri, 0.0);
    }
}

SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenOn(transports: [new FileReplayTransport('/tmp/capture.b64')], readers: [$reader]);
```
