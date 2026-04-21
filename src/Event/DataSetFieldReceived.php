<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetField;

/**
 * Dispatched once per decoded field.
 */
final readonly class DataSetFieldReceived
{
    /**
     * @param DataSetField $field
     * @param int $dataSetWriterId
     * @param int|string $publisherId
     * @param int $writerGroupId
     */
    public function __construct(
        public DataSetField $field,
        public int $dataSetWriterId,
        public int|string $publisherId,
        public int $writerGroupId,
    ) {}
}
