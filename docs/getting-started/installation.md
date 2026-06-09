---
eyebrow: 'Docs · Getting started'
lede:    'Install the package and its one PHP extension, then confirm the core dependency.'

see_also:
  - { href: './quick-start.md',        meta: '4 min' }
  - { href: '../api/transports.md',    meta: '4 min' }

prev: { label: 'Overview',    href: '../overview.md' }
next: { label: 'Quick start', href: './quick-start.md' }
---

# Installation

<!-- @code-block language="bash" label="composer" -->
```bash
composer require php-opcua/opcua-client-ext-transport-pubsub
```
<!-- @endcode-block -->

## Requirements

| Requirement | Notes |
| --- | --- |
| PHP >= 8.2 | |
| `ext-sockets` | Used by `UdpTransport` for UDP unicast/multicast |
| `ext-openssl` | Transitive — required by the core for `Sign` / `SignAndEncrypt` |
| `php-opcua/opcua-client` ^4.4 | The core client; pulled in automatically |

<!-- @callout variant="note" title="ext-sockets" -->
The UDP transport is built on `ext-sockets` (not stream sockets), so it can
join IPv4 multicast groups via `MCAST_JOIN_GROUP` (with a legacy
`IP_ADD_MEMBERSHIP` fallback) and set `SO_REUSEADDR`, the receive buffer,
and TTL. Make sure `ext-sockets` is enabled — `php -m | grep sockets`.
<!-- @endcallout -->

## Verify

<!-- @code-block language="bash" label="sanity check" -->
```bash
php -r "require 'vendor/autoload.php';
  echo class_exists(PhpOpcua\Client\ExtTransportPubSub\SubscriberBuilder::class) ? 'ok' : 'missing';"
```
<!-- @endcode-block -->

Next: build a working subscriber in [Quick start](./quick-start.md).
