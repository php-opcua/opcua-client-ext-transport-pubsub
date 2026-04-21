# Pitfalls

Failure signatures and fixes, ordered by how often they bite.

## 1. Nothing is delivered — publisherId type mismatch

**Symptom:** the subscriber runs, datagrams clearly arrive, but no callback fires.

**Cause:** the demux compares `publisherId` by **type and value**. A reader with `publisherId: 100` (int) does not match a publisher announcing the String id `"100"` — or a different writer/group id.

**Fix:** match the wire exactly. UADP encodes the id as Byte/UInt16/UInt32/UInt64 (→ PHP `int`) or String (→ PHP `string`). Attach a PSR-14 dispatcher and check `NetworkMessageReceived` — if it fires but `DataSetMessageReceived` doesn't, it's a demux-key mismatch.

## 2. Garbage values or `MessageDecodeError` with RawData

**Symptom:** fields decode to nonsense, or `PubSubDecodeException` surfaces as `MessageDecodeError`.

**Cause:** `FieldEncoding::RawData` carries no type info on the wire — the decoder relies entirely on the order and `BuiltinType`s in your `DataSetMetaData`. Wrong/stale metadata = wrong decode.

**Fix:** ensure the reader's metadata matches the publisher's DataSet (field order, count, types). `builtInType` in JSON is the **numeric** `BuiltinType` id. Use `fetchFromServer()` if the publisher also exposes the metadata Variable.

## 3. `PubSubSecurityException` before any message

**Symptom:** throws as soon as a secured datagram (or the provider) is used.

**Cause:** `SksGroupKeyProvider` key accessors throw until `refresh()` has succeeded once. The kernel never calls `refresh()` for you.

**Fix:** call `$keyProvider->refresh()` **before** listening, then on your rotation schedule. Handle a failing `refresh()` (it re-throws on SKS errors) explicitly.

## 4. Expecting opc.tcp:// / sessions / subscriptions

**Symptom:** looking for `CreateSubscription`, monitored items, or a connect/handshake.

**Cause:** PubSub is connectionless — no session, no secure channel, no `opc.tcp://`. This package is subscriber-only over UDP.

**Fix:** for the client/server subscription model use the core `opcua-client`. Use this package only for PubSub publishers.

## 5. `run()` never exits

**Symptom:** the worker can't be stopped gracefully.

**Cause:** nothing calls `stop()`. `run()` blocks until it does.

**Fix:** `pcntl_async_signals(true); pcntl_signal(SIGTERM, fn () => $subscriber->stop());`. Or use a `poll()` loop you control.

## 6. Multicast received on the wrong interface (or not at all)

**Symptom:** on a multi-homed host, no datagrams, or they arrive on the wrong NIC.

**Cause:** membership joined on `0.0.0.0` (all interfaces) when you needed a specific one, or a firewall blocks the group.

**Fix:** set `UdpOptions::$interface` to the receiving NIC's address. The transport joins via `MCAST_JOIN_GROUP` (legacy `IP_ADD_MEMBERSHIP` fallback). Keep `reuseAddress: true` so several subscribers can share the group/port.

## 7. Dropped datagrams under load

**Symptom:** gaps in `sequenceNumber`; some messages missing under a fast publisher.

**Cause:** the OS receive buffer overflows between polls.

**Fix:** raise `UdpOptions::$receiveBufferSize` (default 65536), and poll more often / with a shorter timeout. UDP is lossy by design — handle gaps in application logic, don't assume delivery.

## 8. Treating one bad datagram as fatal

**Symptom:** wrapping `poll()` in a try/catch that stops the worker on the first decode error.

**Cause:** misunderstanding the error model. Per-datagram problems (`PubSubDecodeException`, signature/decrypt failures) are **caught by the kernel** and turned into `MessageDecodeError` / `SecurityValidationFailed` events — the loop keeps running.

**Fix:** observe those events for diagnostics; don't catch around `poll()` for them. Only configuration (`InvalidDataSetReaderException`) and transport-open (`UnsupportedTransportException` → `TransportError`) problems are yours to handle.

## 9. `useJson()` and `setCodec()` together

**Symptom:** confusion over which codec is active.

**Cause:** both set the same single codec override; the last one wins.

**Fix:** use exactly one. `useJson()` for JSON publishers; `setCodec()` only for a genuinely custom `NetworkMessageCodec`. Default (neither) is UADP.
