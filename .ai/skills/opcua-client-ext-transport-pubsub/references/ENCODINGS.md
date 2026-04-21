# Encodings & metadata

A `NetworkMessageCodec` turns wire bytes into a `NetworkMessage`. Two ship; the field layout comes from `DataSetMetaData`.

## UADP vs JSON

| Codec | Spec | Selected by |
| --- | --- | --- |
| `UadpNetworkMessageCodec` | Part 14 §6.2 (binary) | default |
| `JsonNetworkMessageCodec` | Part 14 §7.2 (reversible JSON) | `SubscriberBuilder::useJson()` |

- **UADP** is the compact binary encoding most UDP publishers use. Constructed as `new UadpNetworkMessageCodec(security: $securityOptions)` (or `null`); the builder does this for you, passing the `?PubSubSecurityOptions` you give `listenUdp()`/`listenOn()`.
- **JSON**: call `->useJson()` on the builder. `setCodec($codec)` overrides with your own; `useJson()` and `setCodec()` both set the codec override — use one.

PublisherId width: UADP encodes it as Byte/UInt16/UInt32/UInt64 (surfaced as PHP `int`) or String (PHP `string`). The reader's `publisherId` must match the **type and value** — `100` (int) ≠ `"100"` (string).

## Field encodings — `FieldEncoding` (enum int)

Each `DataSetMessage` declares how its fields are encoded:

| Case | Value | Meaning |
| --- | --- | --- |
| `Variant` | `0` | Each field is a full OPC UA Variant — type travels with the value |
| `RawData` | `1` | Bare values in DataSet order — **layout comes entirely from the reader's `DataSetMetaData`** |
| `DataValue` | `2` | Variant plus status code and timestamps |

Available on each message as `$dataSetMessage->fieldEncoding`. **`RawData` is the one that depends on correct metadata** — there is no type info on the wire, so a metadata mismatch produces wrong values or a `PubSubDecodeException` (surfaced as `MessageDecodeError`).

## `DataSetMetaData`

Describes a DataSet: `name`, ordered `FieldMetaData[]`, config version.

```php
new DataSetMetaData(
    name: 'Line1',
    fields: [ /* FieldMetaData[] */ ],
    majorVersion: 1, minorVersion: 0, description: null,
);
```

`FieldMetaData(string $name, BuiltinType $builtInType, int $valueRank = -1, array $arrayDimensions = [], ?string $description = null)`.

### Six factories

| Factory | Input |
| --- | --- |
| `DataSetMetaData::fromArray(array $data)` | decoded array (see shape below) |
| `DataSetMetaData::fromJsonFile(string $path)` | JSON file → `fromArray` |
| `DataSetMetaData::fromXmlString(string $xml)` | XML — compact **or** canonical `DataSetMetaDataType` shape |
| `DataSetMetaData::fromXmlFile(string $path)` | XML file |
| `DataSetMetaData::fromBinary(string $binary)` | binary `DataSetMetaDataType` (Part 14 §6.2.2) |
| `DataSetMetaData::fetchFromServer(OpcUaClientInterface $client, NodeId\|string $node)` | read the metadata Variable from a live server with the core client |

### `fromArray` / JSON shape

```json
{
  "name": "Line1",
  "majorVersion": 1,
  "fields": [
    { "name": "Temperature", "builtInType": 11 },
    { "name": "Pressure",    "builtInType": 11 },
    { "name": "Running",     "builtInType": 1  }
  ]
}
```

- `name` (string) and `fields` (array) are **required**.
- Each field: `name` (string, required), `builtInType` (**int**, required — the numeric OPC UA `BuiltinType` id), optional `valueRank` (int), `arrayDimensions` (int[]), `description` (string).
- Optional top-level `majorVersion`, `minorVersion`, `description`.
- Missing/invalid keys raise `InvalidDataSetReaderException`.

`builtInType` values are the core `PhpOpcua\Client\Types\BuiltinType` numeric ids — e.g. `1` Boolean, `6` Int32, `11` Double, `12` String. (Use the enum to be sure: `BuiltinType::Double->value`.)

## What you receive

`DataSetMessage`: `dataSetWriterId` (int), `fieldEncoding` (`FieldEncoding`), `fields` (`DataSetField[]`), `sequenceNumber`, `timestamp` (`?DateTimeImmutable`), `status` (int), `configVersionMajor/Minor` (int).

`DataSetField`: `name` (string), raw `value` (mixed), `getScalar(): mixed` to unwrap.
