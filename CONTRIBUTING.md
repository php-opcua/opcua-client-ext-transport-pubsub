# Contributing to OPC UA PubSub Subscriber for PHP

## Welcome!

Thank you for considering contributing to this project! Every contribution matters, whether it's a bug report, a feature suggestion, a documentation fix, or a code change. This project is open to everyone, you're welcome here.

If you have any questions or need help getting started, don't hesitate to open an issue. We're happy to help.

## Development Setup

### Requirements

- PHP >= 8.2
- `ext-sockets`
- `ext-openssl` (transitive, required by the core `opcua-client`)
- Composer
- A sibling checkout of `php-opcua/opcua-client` at `../opcua-client` until v4.4 is published on Packagist (`composer.json` declares a path repository pointing at it)

### Installation

```bash
git clone https://github.com/php-opcua/opcua-client-ext-transport-pubsub.git
cd opcua-client-ext-transport-pubsub
composer install
```

If `../opcua-client` is not present, clone it as a sibling first:

```bash
cd ..
git clone https://github.com/php-opcua/opcua-client.git
cd opcua-client-ext-transport-pubsub
composer install
```

## Running Tests

```bash
# All tests
./vendor/bin/pest

# Unit tests only
./vendor/bin/pest tests/Unit/

# Integration tests only
./vendor/bin/pest tests/Integration/ --group=integration

# A specific test file
./vendor/bin/pest tests/Unit/Encoding/UadpCodecTest.php

# With coverage report
php -d pcov.enabled=1 ./vendor/bin/pest --coverage
```

All tests must pass before submitting a pull request.

## Project Structure

```
src/
├── Subscriber.php                  Thin proxy — typed one-liners delegating to the kernel
├── SubscriberBuilder.php           Builder / entry point
├── OpcUaSubscriberInterface.php    Public API interface
├── Kernel/                         Shared infrastructure
│   ├── PubSubKernel.php            Event loop, demux, PSR-14 dispatch
│   ├── PubSubKernelInterface.php   Surface seen by modules
│   └── NullPubSubEventDispatcher.php
├── Module/                         Module system
│   ├── PubSubModule.php            Abstract base
│   └── DataSetReaderModule.php     Default module for reader configs
├── Transport/                      Transport layer
│   ├── PubSubTransportInterface.php
│   ├── ReceivedPayload.php
│   ├── UdpOptions.php
│   └── UdpTransport.php
├── Encoding/                       Wire codecs
│   ├── NetworkMessageCodec.php
│   ├── UadpNetworkMessageCodec.php
│   ├── UadpDataSetMessageCodec.php
│   ├── JsonNetworkMessageCodec.php
│   └── JsonDataSetMessageCodec.php
├── Security/                       PubSub security
│   ├── PubSubSecurityMode.php
│   ├── PubSubSecurityOptions.php
│   ├── GroupKeyProviderInterface.php
│   ├── StaticGroupKeyProvider.php
│   └── SksGroupKeyProvider.php
├── Event/                          PSR-14 events
├── Types/                          Readonly DTOs and enums
└── Exception/                      Exception hierarchy

tests/
├── Unit/                           Unit tests (no server required)
└── Integration/                    Integration tests (require a PubSub publisher)
```

## Design Principles

### Zero Runtime Dependencies Beyond the Core

This library depends on `php-opcua/opcua-client` and the PSR interface packages (`psr/log`, `psr/event-dispatcher`). PSR packages contain interfaces only — no runtime code, no transitive dependencies.

**Do not add Composer dependencies that ship runtime code.** If a feature requires an external library (MQTT broker client, Redis driver, AMQP client, event loop framework), it belongs in a separate package that implements `PubSubTransportInterface` or accepts a PSR interface the consumer provides. Keeps the base install small.

### Cross-Platform Compatibility

The library must work on Linux, macOS, and Windows. Do not use platform-specific APIs beyond `ext-sockets`. No `pcntl_*` in production code, no `/proc`, no Unix-only socket options without a fallback.

### Public Readonly DTOs

All wire types and configuration objects use `public readonly` properties. Access is `$msg->fields`, `$field->name`, not `$msg->getFields()` or `$field->getName()`.

### Does Not Modify the Core

Names in `php-opcua/opcua-client` (`ClientBuilder`, `Client`, `ClientKernel`, `ClientKernelInterface`, `OpcUaClientInterface`) are **invariant**. This package is strictly additive. If you find yourself wanting to change the core to make this package work, stop — open an issue on the core repository first.

### Sibling, Not Subclass

`SubscriberBuilder` is a sibling of `ClientBuilder`, `PubSubKernel` a sibling of `ClientKernel`. There is no shared abstract base. The few lines that duplicate are kept duplicated on purpose.

## Guidelines

### Code Style

The project enforces the same Laravel-style coding standard as the core (PSR-12 + opinionated rules) via [php-cs-fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer). Configuration lives in `.php-cs-fixer.php`.

```bash
# Format all files
composer format

# Check without modifying (CI mode)
composer format:check
```

**You must run `composer format` before committing.** Pull requests with unformatted code will fail the CI check.

**Key rules:**

- `declare(strict_types=1)` required
- Single quotes for strings
- Trailing commas in multiline arrays, arguments, and parameters
- `not_operator_with_successor_space` (space after `!`)
- Ordered imports (alphabetical)
- No unused imports
- No blank lines after class opening brace
- Type declarations for parameters, return types, and properties
- `public readonly` properties for DTOs — no getters

### Documentation & Comments

- Every class, trait, interface, and enum must have a PHPDoc description
- Every public method must have a PHPDoc block with `@param`, `@return`, `@throws`, and `@see` where applicable
- `@return` and `@param` must be on their own line, not inline with the description
- **Do not add comments inside function bodies.** No `//`, no `/* */`, no section headers. If the code needs a comment to be understood, the method is too complex — split it into smaller, well-named methods instead.
- Update relevant files in `doc/` for new features
- Update `CHANGELOG.md` with your changes
- Update `README.md` features list if adding a major feature
- Update `llms.txt` and `llms-full.txt` if the change affects the public API or architecture

### Public API Changes

- Any new public method on the subscriber surface must be added to `OpcUaSubscriberInterface` and implemented as a typed one-liner on `Subscriber`
- New encodings or transports should be implemented as new classes rather than extended inline in existing ones
- Configuration methods on `SubscriberBuilder` should return `self` for fluent chaining

### Testing

- Write unit tests for all new functionality
- Use Pest PHP syntax (not PHPUnit)
- Use `FakeTransport` and `CollectingDispatcher` from `tests/Unit/Kernel/` for kernel-level tests
- Use `MockClient` from the core for tests that exercise `fetchFromServer` or `SksGroupKeyProvider`
- Group integration tests with `->group('integration')`

### Commits

- Use descriptive commit messages
- Prefix with `[ADD]`, `[UPD]`, `[PATCH]`, `[REF]`, `[DOC]`, `[TEST]` as appropriate

## Pull Request Process

1. Fork the repository and create a feature branch
2. Write your code and tests
3. Run `composer format` to format your code
4. Ensure all tests pass
5. Update documentation, changelog, and llms files
6. Submit a pull request
7. Wait for review — a maintainer will review your PR, may request changes or ask questions
8. Once approved, your PR will be merged

## Reporting Issues

Use the issue templates to report bugs, request features, or ask questions.
