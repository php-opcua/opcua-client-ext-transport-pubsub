<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\ExtTransportPubSub\Exception\InvalidDataSetReaderException;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ExtensionObject;

/**
 * Metadata for one DataSet (Part 14 §6.2.2 DataSetMetaDataType).
 */
final readonly class DataSetMetaData
{
    /**
     * @param string $name
     * @param list<FieldMetaData> $fields
     * @param int $majorVersion
     * @param int $minorVersion
     * @param ?string $description
     */
    public function __construct(
        public string $name,
        public array $fields,
        public int $majorVersion = 1,
        public int $minorVersion = 0,
        public ?string $description = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     *
     * @throws InvalidDataSetReaderException
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['name']) || ! is_string($data['name'])) {
            throw new InvalidDataSetReaderException('DataSetMetaData: "name" is required and must be a string');
        }

        if (! isset($data['fields']) || ! is_array($data['fields'])) {
            throw new InvalidDataSetReaderException('DataSetMetaData: "fields" is required and must be an array');
        }

        $fields = [];
        foreach ($data['fields'] as $i => $raw) {
            $fields[] = self::fieldFromArray($raw, $i);
        }

        return new self(
            name: $data['name'],
            fields: $fields,
            majorVersion: is_int($data['majorVersion'] ?? null) ? $data['majorVersion'] : 1,
            minorVersion: is_int($data['minorVersion'] ?? null) ? $data['minorVersion'] : 0,
            description: is_string($data['description'] ?? null) ? $data['description'] : null,
        );
    }

    /**
     * @param string $path
     * @return self
     *
     * @throws InvalidDataSetReaderException
     */
    public static function fromJsonFile(string $path): self
    {
        $raw = self::readFile($path);

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidDataSetReaderException("DataSetMetaData: invalid JSON in {$path}: {$e->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new InvalidDataSetReaderException("DataSetMetaData: JSON root must be an object in {$path}");
        }

        return self::fromArray($decoded);
    }

    /**
     * @param string $path
     * @return self
     *
     * @throws InvalidDataSetReaderException
     */
    public static function fromXmlFile(string $path): self
    {
        return self::fromXmlString(self::readFile($path));
    }

    /**
     * @param string $xml
     * @return self
     *
     * @throws InvalidDataSetReaderException
     */
    public static function fromXmlString(string $xml): self
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $root = @simplexml_load_string($xml);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            throw new InvalidDataSetReaderException('DataSetMetaData: malformed XML');
        }

        $fieldsNode = $root->Fields ?? null;
        if ($fieldsNode === null || count($fieldsNode->children()) === 0) {
            throw new InvalidDataSetReaderException('DataSetMetaData: XML must contain a non-empty <Fields> element');
        }

        $fields = [];
        $index = 0;
        foreach ($fieldsNode->children() as $fieldNode) {
            $fields[] = self::fieldFromXml($fieldNode, $index);
            $index++;
        }

        $name = self::xmlText($root->Name ?? null);
        if ($name === null || $name === '') {
            throw new InvalidDataSetReaderException('DataSetMetaData: XML must contain a non-empty <Name>');
        }

        $cfg = $root->ConfigurationVersion ?? null;
        $majorVersion = $cfg !== null
            ? (int) self::xmlText($cfg->MajorVersion ?? null)
            : (int) (self::xmlText($root->MajorVersion ?? null) ?? '1');
        $minorVersion = $cfg !== null
            ? (int) self::xmlText($cfg->MinorVersion ?? null)
            : (int) (self::xmlText($root->MinorVersion ?? null) ?? '0');

        return new self(
            name: $name,
            fields: $fields,
            majorVersion: $majorVersion !== 0 ? $majorVersion : 1,
            minorVersion: $minorVersion,
            description: self::xmlText($root->Description ?? null),
        );
    }

    /**
     * @param OpcUaClientInterface $client
     * @param \PhpOpcua\Client\Types\NodeId|string $metaDataVariableNodeId
     * @return self
     *
     * @throws InvalidDataSetReaderException
     */
    public static function fetchFromServer(
        OpcUaClientInterface $client,
        \PhpOpcua\Client\Types\NodeId|string $metaDataVariableNodeId,
    ): self {
        $dataValue = $client->read($metaDataVariableNodeId, AttributeId::Value);
        if ($dataValue->getType() === null) {
            throw new InvalidDataSetReaderException(
                'DataSetMetaData::fetchFromServer: server returned an empty DataValue',
            );
        }

        $value = $dataValue->getValue();

        if ($value instanceof self) {
            return $value;
        }

        if (! $value instanceof ExtensionObject) {
            throw new InvalidDataSetReaderException(
                'DataSetMetaData::fetchFromServer: expected an ExtensionObject, got ' . get_debug_type($value),
            );
        }

        if ($value->isDecoded() && $value->value instanceof self) {
            return $value->value;
        }

        if ($value->body === null) {
            throw new InvalidDataSetReaderException(
                'DataSetMetaData::fetchFromServer: ExtensionObject has no body to decode',
            );
        }

        if ($value->encoding !== 0x01) {
            throw new InvalidDataSetReaderException(
                'DataSetMetaData::fetchFromServer: only binary-encoded ExtensionObject bodies are supported (got encoding ' . $value->encoding . ')',
            );
        }

        return self::fromBinary($value->body);
    }

    /**
     * @param string $binary
     * @return self
     *
     * @throws InvalidDataSetReaderException
     */
    public static function fromBinary(string $binary): self
    {
        $decoder = new BinaryDecoder($binary);

        self::skipArray($decoder, fn () => $decoder->readString());

        self::rejectNonEmpty($decoder, 'StructureDataTypes');
        self::rejectNonEmpty($decoder, 'EnumDataTypes');
        self::rejectNonEmpty($decoder, 'SimpleDataTypes');

        $name = $decoder->readString() ?? '';
        $localizedDescription = $decoder->readLocalizedText();

        $fieldCount = $decoder->readInt32();
        $fields = [];
        for ($i = 0; $i < $fieldCount; $i++) {
            $fields[] = self::fieldFromBinary($decoder);
        }

        if ($decoder->getRemainingLength() >= 16) {
            $decoder->readGuid();
        } elseif ($decoder->getRemainingLength() > 0) {
            throw new InvalidDataSetReaderException(
                'DataSetMetaData::fromBinary: truncated payload before DataSetClassId',
            );
        }

        $majorVersion = $decoder->getRemainingLength() >= 4 ? $decoder->readUInt32() : 1;
        $minorVersion = $decoder->getRemainingLength() >= 4 ? $decoder->readUInt32() : 0;

        return new self(
            name: $name,
            fields: $fields,
            majorVersion: $majorVersion !== 0 ? $majorVersion : 1,
            minorVersion: $minorVersion,
            description: $localizedDescription->getText(),
        );
    }

    /**
     * @return array<string, FieldMetaData>
     */
    public function fieldsByName(): array
    {
        $out = [];
        foreach ($this->fields as $field) {
            $out[$field->name] = $field;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @param int $index
     * @return FieldMetaData
     *
     * @throws InvalidDataSetReaderException
     */
    private static function fieldFromArray(mixed $raw, int $index): FieldMetaData
    {
        if (! is_array($raw)) {
            throw new InvalidDataSetReaderException("DataSetMetaData: field #{$index} must be an object");
        }

        if (! isset($raw['name']) || ! is_string($raw['name'])) {
            throw new InvalidDataSetReaderException("DataSetMetaData: field #{$index} missing string \"name\"");
        }

        $typeId = $raw['builtInType'] ?? null;
        if (! is_int($typeId)) {
            throw new InvalidDataSetReaderException(
                "DataSetMetaData: field \"{$raw['name']}\" missing int \"builtInType\"",
            );
        }

        $type = BuiltinType::tryFrom($typeId);
        if ($type === null) {
            throw new InvalidDataSetReaderException(
                "DataSetMetaData: field \"{$raw['name']}\" has unknown builtInType {$typeId}",
            );
        }

        $arrayDims = $raw['arrayDimensions'] ?? [];
        if (! is_array($arrayDims)) {
            throw new InvalidDataSetReaderException(
                "DataSetMetaData: field \"{$raw['name']}\" arrayDimensions must be an array",
            );
        }

        return new FieldMetaData(
            name: $raw['name'],
            builtInType: $type,
            valueRank: is_int($raw['valueRank'] ?? null) ? $raw['valueRank'] : -1,
            arrayDimensions: array_values(array_map('intval', $arrayDims)),
            description: is_string($raw['description'] ?? null) ? $raw['description'] : null,
        );
    }

    /**
     * @param \SimpleXMLElement $node
     * @param int $index
     * @return FieldMetaData
     *
     * @throws InvalidDataSetReaderException
     */
    private static function fieldFromXml(\SimpleXMLElement $node, int $index): FieldMetaData
    {
        $name = self::xmlText($node->Name ?? null);
        if ($name === null || $name === '') {
            throw new InvalidDataSetReaderException("DataSetMetaData: XML field #{$index} missing <Name>");
        }

        $typeId = self::xmlText($node->BuiltInType ?? null);
        if ($typeId === null || $typeId === '') {
            throw new InvalidDataSetReaderException("DataSetMetaData: XML field '{$name}' missing <BuiltInType>");
        }

        $type = BuiltinType::tryFrom((int) $typeId);
        if ($type === null) {
            throw new InvalidDataSetReaderException("DataSetMetaData: XML field '{$name}' has unknown BuiltInType '{$typeId}'");
        }

        $dims = [];
        if (isset($node->ArrayDimensions)) {
            foreach ($node->ArrayDimensions->children() as $dim) {
                $dims[] = (int) self::xmlText($dim);
            }
        }

        return new FieldMetaData(
            name: $name,
            builtInType: $type,
            valueRank: (int) (self::xmlText($node->ValueRank ?? null) ?? '-1'),
            arrayDimensions: $dims,
            description: self::xmlText($node->Description ?? null),
        );
    }

    /**
     * @param BinaryDecoder $decoder
     * @return FieldMetaData
     *
     * @throws InvalidDataSetReaderException
     */
    private static function fieldFromBinary(BinaryDecoder $decoder): FieldMetaData
    {
        $name = $decoder->readString() ?? '';
        $description = $decoder->readLocalizedText();
        $decoder->readUInt16();
        $builtInByte = $decoder->readByte();
        $decoder->readNodeId();
        $valueRank = $decoder->readInt32();

        $dimCount = $decoder->readInt32();
        $dims = [];
        if ($dimCount > 0) {
            for ($i = 0; $i < $dimCount; $i++) {
                $dims[] = $decoder->readUInt32();
            }
        }

        $decoder->readUInt32();
        $decoder->readGuid();
        self::skipArray($decoder, function () use ($decoder) {
            $decoder->readQualifiedName();
            $decoder->readVariant();
        });

        $type = BuiltinType::tryFrom($builtInByte)
            ?? throw new InvalidDataSetReaderException("DataSetMetaData::fromBinary: unknown BuiltInType {$builtInByte} for field '{$name}'");

        return new FieldMetaData(
            name: $name,
            builtInType: $type,
            valueRank: $valueRank,
            arrayDimensions: $dims,
            description: $description->getText(),
        );
    }

    /**
     * @param null|\SimpleXMLElement $node
     * @return ?string
     */
    private static function xmlText(null|\SimpleXMLElement $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $text = trim((string) $node);

        return $text === '' ? null : $text;
    }

    /**
     * @param BinaryDecoder $decoder
     * @param callable $skipOne
     */
    private static function skipArray(BinaryDecoder $decoder, callable $skipOne): void
    {
        $count = $decoder->readInt32();
        if ($count <= 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $skipOne();
        }
    }

    /**
     * @param BinaryDecoder $decoder
     * @param string $sectionName
     *
     * @throws InvalidDataSetReaderException
     */
    private static function rejectNonEmpty(BinaryDecoder $decoder, string $sectionName): void
    {
        $count = $decoder->readInt32();
        if ($count > 0) {
            throw new InvalidDataSetReaderException(
                "DataSetMetaData::fromBinary: {$sectionName} section is not empty ({$count} entries); nested schema types are not supported",
            );
        }
    }

    /**
     * @param string $path
     * @return string
     *
     * @throws InvalidDataSetReaderException
     */
    private static function readFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidDataSetReaderException("DataSetMetaData: cannot read file at {$path}");
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new InvalidDataSetReaderException("DataSetMetaData: failed to read contents of {$path}");
        }

        return $raw;
    }
}
