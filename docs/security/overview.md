---
eyebrow: 'Docs · Security'
lede:    'PubSub secures messages with pre-shared group keys, not per-session handshakes. Choose a mode, supply the keys statically or from a Security Key Service.'

see_also:
  - { href: '../api/subscriber.md',          meta: '5 min' }
  - { href: '../recipes/sks-key-rotation.md', meta: '4 min' }
  - { href: 'https://reference.opcfoundation.org/Core/Part14/v105/docs/', meta: 'external', label: 'OPC UA Part 14' }

prev: { label: 'Events',                 href: '../api/events.md' }
next: { label: 'Multicast subscription', href: '../recipes/multicast-subscription.md' }
---

# Group-key security

PubSub has no session handshake — publishers and subscribers in a security
group share keys out of band. The subscriber verifies (and decrypts) each
NetworkMessage with the current group key.

## Modes

`PubSubSecurityMode` (int-backed enum):

| Mode | Value | What happens |
| --- | --- | --- |
| `None` | `1` | No signature, no encryption |
| `Sign` | `2` | HMAC-SHA256 signature over the message; verified on receipt |
| `SignAndEncrypt` | `3` | HMAC-SHA256 signature **and** AES-CTR payload encryption |

Signing uses **HMAC-SHA256**; encryption uses **AES-CTR** (128- or 256-bit,
selected by the encrypting-key length) with a counter block derived from the
key nonce. A datagram that fails verification or decryption is dropped and a
[`SecurityValidationFailed`](../api/events.md) event fires.

## Wiring it up

Pass a `PubSubSecurityOptions` to `listenUdp()` / `listenOn()`:

<!-- @params heading="PubSubSecurityOptions" -->
<!-- @param name="mode" type="PubSubSecurityMode" required="true" -->
The security mode above.
<!-- @endparam -->
<!-- @param name="keyProvider" type="?GroupKeyProviderInterface" -->
Source of the group keys. Required for `Sign` / `SignAndEncrypt`; `null` for
`None`.
<!-- @endparam -->
<!-- @endparams -->

```php
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;

$security = new PubSubSecurityOptions(
    mode: PubSubSecurityMode::SignAndEncrypt,
    keyProvider: $keyProvider,
);

SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader], security: $security);
```

## Key providers

A `GroupKeyProviderInterface` supplies the four pieces the codec needs:
`signingKey()`, `encryptingKey()`, `keyNonce()`, `tokenId()`, plus `refresh()`
to rotate them.

### `StaticGroupKeyProvider` — pre-shared keys

For fixed keys you distribute yourself.

<!-- @params heading="Constructor" -->
<!-- @param name="signingKey" type="string" required="true" -->
Raw HMAC-SHA256 signing key.
<!-- @endparam -->
<!-- @param name="encryptingKey" type="string" required="true" -->
Raw AES key (16 bytes for AES-128, 32 for AES-256).
<!-- @endparam -->
<!-- @param name="keyNonce" type="string" required="true" -->
Nonce used to build the AES-CTR counter block.
<!-- @endparam -->
<!-- @param name="tokenId" type="int" -->
Security token id. Default `1`.
<!-- @endparam -->
<!-- @endparams -->

`refresh()` is a no-op — static keys never change.

<!-- @callout variant="warning" title="Key hygiene" -->
Keys are raw secret bytes. Load them from a secret store or environment, never
from source control, and match the encrypting-key length to your group's
policy (`Aes128` vs `Aes256`).
<!-- @endcallout -->

### `SksGroupKeyProvider` — live rotation from a Security Key Service

Pulls the current keys from an OPC UA Security Key Service by calling
`GetSecurityKeys` through the **classic `opcua-client`**. `refresh()` re-fetches,
so keys rotate without a restart.

<!-- @params heading="Constructor" -->
<!-- @param name="client" type="OpcUaClientInterface" required="true" -->
A connected core client used to call the SKS.
<!-- @endparam -->
<!-- @param name="securityGroupId" type="string" required="true" -->
The security group to fetch keys for.
<!-- @endparam -->
<!-- @param name="objectNodeId" type="NodeId|string" -->
SKS object node. Default `i=14443` (the standard `PublishSubscribe` object).
<!-- @endparam -->
<!-- @param name="methodNodeId" type="NodeId|string" -->
`GetSecurityKeys` method node. Default `i=15215`.
<!-- @endparam -->
<!-- @param name="securityPolicyUri" type="string" -->
Default `SksGroupKeyProvider::POLICY_AES256_CTR`
(`…/SecurityPolicy#PubSub-Aes256-CTR`); `POLICY_AES128_CTR` is also provided.
<!-- @endparam -->
<!-- @param name="requestedKeyCount" type="int" -->
How many future keys to request. Default `1`.
<!-- @endparam -->
<!-- @endparams -->

<!-- @code-block language="php" label="SKS-backed keys" -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Security\SksGroupKeyProvider;

$sksClient = ClientBuilder::create()->connect('opc.tcp://sks.plant.local:4840');

$keyProvider = new SksGroupKeyProvider(
    client: $sksClient,
    securityGroupId: 'group-1',
    securityPolicyUri: SksGroupKeyProvider::POLICY_AES256_CTR,
);
```
<!-- @endcode-block -->

See [Rotating keys with an SKS](../recipes/sks-key-rotation.md) for the
refresh loop.
