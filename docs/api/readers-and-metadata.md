---
eyebrow: 'Docs · API'
lede:    'A reader says which DataSet to accept; its metadata says how to decode the fields. Configure both, and load metadata from JSON, XML, binary, or a live server.'

see_also:
  - { href: './subscriber.md',         meta: '5 min' }
  - { href: '../concepts/encodings.md', meta: '4 min' }

prev: { label: 'Transports', href: './transports.md' }
next: { label: 'Modules',    href: './modules.md' }
---

# Readers & metadata

## `DataSetReaderConfig`

One reader per DataSet you want to receive. The first three fields are the
[demux key](../concepts/how-it-works.md#demultiplexing); the metadata drives
field decoding.

@params heading="Constructor"
@param name="publisherId" type="int|string" required="true"
Must match the publisher's id (type and value). `int` covers Byte/UInt16/
UInt32/UInt64; `string` covers the String id type.
@endparam
@param name="writerGroupId" type="int" required="true"
WriterGroup id. Must be a positive integer.
@endparam
@param name="dataSetWriterId" type="int" required="true"
DataSetWriter id within the group. Must be a positive integer.
@endparam
@param name="dataSetMetaData" type="DataSetMetaData" required="true"
The field layout used to decode and name fields.
@endparam
@param name="name" type="?string"
Optional human label for logs. Default `null`.
@endparam
@endparams

@callout variant="warning" title="Validation"
Invalid ids (negative `writerGroupId` / `dataSetWriterId`, a negative integer
`publisherId`) raise `InvalidDataSetReaderException` at construction.
@endcallout

## `DataSetMetaData`

Describes a DataSet: a `name`, an ordered list of `FieldMetaData`, and a
config version. The field **order and types** are what `RawData` decoding
relies on, and the field **names** are what end up on each `DataSetField`.

```php
new DataSetMetaData(
    name: 'Line1',
    fields: [ /* FieldMetaData[] */ ],
    majorVersion: 1,
    minorVersion: 0,
    description: null,
);
```

### `FieldMetaData`

| Property | Type | Default |
| --- | --- | --- |
| `name` | `string` | required |
| `builtInType` | `BuiltinType` | required |
| `valueRank` | `int` | `-1` (scalar) |
| `arrayDimensions` | `array` | `[]` |
| `description` | `?string` | `null` |

## Loading metadata

Six factories build a `DataSetMetaData`:

<!-- @method name="DataSetMetaData::fromArray(array \$data): self" returns="self" visibility="public" -->
From a decoded array. Required keys: `name` (string), `fields` (array). Each
field needs `name` (string) and `builtInType` (**int** — the numeric OPC UA
BuiltinType id, e.g. `11` for `Double`); optional `valueRank`,
`arrayDimensions`, `description`. Optional top-level `majorVersion`,
`minorVersion`, `description`.

<!-- @method name="DataSetMetaData::fromJsonFile(string \$path): self" returns="self" visibility="public" -->
Read and `json_decode` a file, then `fromArray`.

<!-- @method name="DataSetMetaData::fromXmlString(string \$xml): self" returns="self" visibility="public" -->
Parse XML — both a compact shape and the OPC UA canonical
`DataSetMetaDataType` XML shape are accepted.

<!-- @method name="DataSetMetaData::fromXmlFile(string \$path): self" returns="self" visibility="public" -->
`fromXmlString` on a file's contents.

<!-- @method name="DataSetMetaData::fromBinary(string \$binary): self" returns="self" visibility="public" -->
Decode a binary-encoded `DataSetMetaDataType` (Part 14 §6.2.2).

<!-- @method name="DataSetMetaData::fetchFromServer(OpcUaClientInterface \$client, NodeId|string \$metaDataVariableNodeId): self" returns="self" visibility="public" -->
Read the metadata Variable from a running server with the classic
`opcua-client` and decode it. Useful when the publisher also exposes a
client/server endpoint.

@code-block language="json" label="line1.json — fromJsonFile"
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
@endcode-block

`builtInType` values are the numeric OPC UA BuiltinType ids from the core
(`PhpOpcua\Client\Types\BuiltinType`) — e.g. `1` Boolean, `6` Int32,
`11` Double, `12` String.

## What you receive

A matched message arrives as a `DataSetMessage`:

| Property | Type |
| --- | --- |
| `dataSetWriterId` | `int` |
| `fieldEncoding` | `FieldEncoding` |
| `fields` | `list<DataSetField>` |
| `sequenceNumber` | `int` |
| `timestamp` | `?DateTimeImmutable` |
| `status` | `int` |
| `configVersionMajor` / `configVersionMinor` | `int` |

Each `DataSetField` has a `name` (`string`), a raw `value` (`mixed`), and
`getScalar(): mixed` which unwraps the value to a plain PHP scalar.
