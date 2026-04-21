# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 4.x     | Yes       |

## Reporting a Vulnerability

If you discover a security vulnerability in this library, please report it responsibly.

**Do not open a public issue.** Instead, send an email to [gianfri@php-opcua.com](mailto:gianfri@php-opcua.com) with:

- A description of the vulnerability
- Steps to reproduce
- The affected version(s)
- Any potential impact assessment

You should receive an acknowledgment within 48 hours. From there, we'll work together to understand the scope and develop a fix before any public disclosure.

## Scope

This policy covers the `php-opcua/opcua-client-ext-transport-pubsub` library itself. For vulnerabilities in the core or related packages, please report them to the respective maintainers:

- [opcua-client](https://github.com/php-opcua/opcua-client)
- [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager)
- [laravel-opcua](https://github.com/php-opcua/laravel-opcua)

## Security Considerations

OPC UA PubSub is often deployed on industrial networks where messages are visible to anyone on the broadcast domain. When deploying in production:

- Use `PubSubSecurityMode::SignAndEncrypt` on any publisher whose data is not considered public.
- Distribute group keys out of band — never hard-code them in application source.
- Rotate group keys regularly via a Security Key Service (`SksGroupKeyProvider`) rather than long-lived pre-shared keys.
- Restrict subnet and multicast routing so stray subscribers cannot silently join the stream.
- Keep PHP, OpenSSL, and the network stack up to date.
- Prefer UDP unicast over multicast when feasible — it reduces the blast radius of a rogue subscriber.
