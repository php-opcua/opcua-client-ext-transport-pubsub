---
eyebrow: 'Docs · Recipes'
lede:    'Fetch group keys from a Security Key Service, subscribe with encryption, and rotate the keys without restarting.'

see_also:
  - { href: '../security/overview.md', meta: '5 min' }
  - { href: '../api/subscriber.md',    meta: '5 min' }

prev: { label: 'Multicast subscription', href: './multicast-subscription.md' }
next: { label: 'Testing the kernel',     href: '../testing/overview.md' }
---

# Rotating keys with an SKS

`SksGroupKeyProvider` pulls the current group keys from an OPC UA Security Key
Service via `GetSecurityKeys`. You drive the rotation by calling `refresh()`.

<!-- @callout variant="warning" title="refresh() first" -->
The key accessors throw until `refresh()` has succeeded at least once. Call
`refresh()` **before** you start listening, then again on your rotation
schedule.
<!-- @endcallout -->

## Set up

<!-- @code-block language="php" label="connect SKS + build provider" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Security\SksGroupKeyProvider;

$sksClient = ClientBuilder::create()
    ->connect('opc.tcp://sks.plant.local:4840');

$keys = new SksGroupKeyProvider(
    client: $sksClient,
    securityGroupId: 'group-1',
    securityPolicyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
    requestedKeyCount: 1,
);

$keys->refresh();   // mandatory: fetch the first key set

$security = new PubSubSecurityOptions(
    mode: PubSubSecurityMode::SignAndEncrypt,
    keyProvider: $keys,
);
```
<!-- @endcode-block -->

## Subscribe and rotate

Run your own `poll()` loop so you can re-`refresh()` on a schedule:

<!-- @code-block language="php" label="poll loop with rotation" -->
```php
$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenUdp(
        endpoint: 'opc.udp://239.0.0.1:4840',
        readers: [$reader],
        security: $security,
    );

$nextRotation = time() + 3600;   // rotate hourly (match your SKS policy)

while ($running) {
    $subscriber->poll(timeoutMs: 200);

    if (time() >= $nextRotation) {
        $keys->refresh();        // pull the next key set from the SKS
        $nextRotation = time() + 3600;
    }
}
```
<!-- @endcode-block -->

The codec reads the provider's `signingKey()` / `encryptingKey()` /
`keyNonce()` / `tokenId()` per datagram, so a successful `refresh()` takes
effect on the very next message — no restart, no gap.

<!-- @callout variant="danger" title="Failed refresh" -->
If `refresh()` can't reach the SKS it throws `PubSubSecurityException`. Decide
your policy explicitly: keep using the last known-good keys for a grace period,
or stop. Don't let an unhandled throw kill the loop silently.
<!-- @endcallout -->

## Defaults

`objectNodeId` and `methodNodeId` default to the standard SKS nodes
(`i=14443` / `i=15215`). Override them only if your server exposes
`GetSecurityKeys` elsewhere. Use `POLICY_AES128_CTR` instead of
`POLICY_AES256_CTR` for a 128-bit group.
