# Group-key security

PubSub has no session handshake. Members of a security group share keys out of band; the subscriber verifies (and decrypts) each NetworkMessage with the current group key.

## Modes — `PubSubSecurityMode` (enum int)

| Mode | Value | Effect |
| --- | --- | --- |
| `None` | `1` | no signature, no encryption |
| `Sign` | `2` | HMAC-SHA256 signature, verified on receipt |
| `SignAndEncrypt` | `3` | HMAC-SHA256 signature **and** AES-CTR payload encryption |

Signing uses **HMAC-SHA256**; encryption uses **AES-CTR** (128- or 256-bit, selected by the encrypting-key length) with a counter block derived from the key nonce. A datagram that fails verification/decryption is dropped and a `SecurityValidationFailed` event fires — the loop continues.

## Wiring

```php
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;

$security = new PubSubSecurityOptions(
    mode: PubSubSecurityMode::SignAndEncrypt,
    keyProvider: $keyProvider,                 // required for Sign / SignAndEncrypt
);

$subscriber = SubscriberBuilder::create()
    ->onDataSetMessage($callback)
    ->listenUdp(endpoint: 'opc.udp://239.0.0.1:4840', readers: [$reader], security: $security);
```

`PubSubSecurityOptions(PubSubSecurityMode $mode, ?GroupKeyProviderInterface $keyProvider = null)`. `null` security (the default) means `None`.

## Key providers — `GroupKeyProviderInterface`

Supplies `signingKey(): string`, `encryptingKey(): string`, `keyNonce(): string`, `tokenId(): int`, and `refresh(): void`.

### `StaticGroupKeyProvider` — pre-shared keys

```php
new StaticGroupKeyProvider(
    signingKey:    $hmacKey,     // raw HMAC-SHA256 key
    encryptingKey: $aesKey,      // raw AES key: 16 bytes (AES-128) or 32 (AES-256)
    keyNonce:      $nonce,       // builds the AES-CTR counter block
    tokenId:       1,            // default 1
);
```

`refresh()` is a no-op — static keys never change. Load the raw bytes from a secret store, never from source control. Match the encrypting-key length to the group policy (`Aes128` vs `Aes256`).

### `SksGroupKeyProvider` — live rotation from a Security Key Service

Fetches current keys via `GetSecurityKeys` through the **classic `opcua-client`**.

```php
new SksGroupKeyProvider(
    client:            $coreClient,                       // connected OpcUaClientInterface
    securityGroupId:   'group-1',
    objectNodeId:      'i=14443',                         // default: standard PublishSubscribe object
    methodNodeId:      'i=15215',                         // default: GetSecurityKeys
    securityPolicyUri: SksGroupKeyProvider::POLICY_AES256_CTR,  // or POLICY_AES128_CTR
    requestedKeyCount: 1,
);
```

@critical: **`refresh()` must succeed before the key accessors work** — they throw `PubSubSecurityException` ("requested before refresh() succeeded") otherwise. Call `refresh()` once before listening, then again on your rotation schedule. The kernel/codec never calls it for you.

```php
$keys = new SksGroupKeyProvider(client: $coreClient, securityGroupId: 'group-1');
$keys->refresh();                                  // mandatory first fetch
$security = new PubSubSecurityOptions(PubSubSecurityMode::SignAndEncrypt, $keys);
// …subscribe… then periodically:
$keys->refresh();                                  // pull the next key set (re-throws on SKS failure)
```

A failed `refresh()` throws `PubSubSecurityException` to **you** — decide explicitly whether to keep last-known-good keys for a grace period or stop. Don't let it kill the loop silently.

## Threat model notes

- Keys are raw secret bytes shared by the whole group — anyone with them can read (and, for `Sign`, forge) traffic. Scope group membership tightly.
- The codec reads the provider's keys **per datagram**, so a successful `refresh()` takes effect on the very next message — rotation has no gap and needs no restart.
- `SignAndEncrypt` protects confidentiality + integrity; `Sign` only integrity (payload is plaintext on the wire).
