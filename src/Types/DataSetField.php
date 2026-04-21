<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\Variant;

/**
 * A single decoded field inside a DataSetMessage.
 */
final readonly class DataSetField
{
    /**
     * @param string $name
     * @param mixed $value
     */
    public function __construct(
        public string $name,
        public mixed $value,
    ) {}

    /**
     * @return mixed
     */
    public function getScalar(): mixed
    {
        if ($this->value instanceof Variant) {
            return $this->value->value;
        }

        if ($this->value instanceof DataValue) {
            return $this->value->getValue();
        }

        return $this->value;
    }
}
