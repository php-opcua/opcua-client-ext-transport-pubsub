<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

use DateTimeImmutable;

/**
 * A decoded DataSetMessage — one payload inside a NetworkMessage.
 */
final readonly class DataSetMessage
{
    /**
     * @param int $dataSetWriterId
     * @param FieldEncoding $fieldEncoding
     * @param list<DataSetField> $fields
     * @param int $sequenceNumber
     * @param ?DateTimeImmutable $timestamp
     * @param int $status
     * @param int $configVersionMajor
     * @param int $configVersionMinor
     */
    public function __construct(
        public int $dataSetWriterId,
        public FieldEncoding $fieldEncoding,
        public array $fields,
        public int $sequenceNumber = 0,
        public ?DateTimeImmutable $timestamp = null,
        public int $status = 0,
        public int $configVersionMajor = 0,
        public int $configVersionMinor = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toMap(): array
    {
        $out = [];
        foreach ($this->fields as $field) {
            $out[$field->name] = $field->getScalar();
        }

        return $out;
    }
}
