<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

use PhpOpcua\Client\Types\BuiltinType;

/**
 * Describes a single field inside a DataSetMetaData.
 */
final readonly class FieldMetaData
{
    /**
     * @param string $name
     * @param BuiltinType $builtInType
     * @param int $valueRank
     * @param int[] $arrayDimensions
     * @param ?string $description
     */
    public function __construct(
        public string $name,
        public BuiltinType $builtInType,
        public int $valueRank = -1,
        public array $arrayDimensions = [],
        public ?string $description = null,
    ) {}
}
