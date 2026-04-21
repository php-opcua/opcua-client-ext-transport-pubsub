<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

use PhpOpcua\Client\ExtTransportPubSub\Exception\InvalidDataSetReaderException;

/**
 * Subscriber-side configuration for consuming a single DataSet.
 */
final readonly class DataSetReaderConfig
{
    /**
     * @param int|string $publisherId
     * @param int $writerGroupId
     * @param int $dataSetWriterId
     * @param DataSetMetaData $dataSetMetaData
     * @param ?string $name
     *
     * @throws InvalidDataSetReaderException
     */
    public function __construct(
        public int|string $publisherId,
        public int $writerGroupId,
        public int $dataSetWriterId,
        public DataSetMetaData $dataSetMetaData,
        public ?string $name = null,
    ) {
        if (is_int($publisherId) && $publisherId < 0) {
            throw new InvalidDataSetReaderException('publisherId integer must be non-negative');
        }

        if ($writerGroupId < 0) {
            throw new InvalidDataSetReaderException('writerGroupId must be non-negative');
        }

        if ($dataSetWriterId <= 0) {
            throw new InvalidDataSetReaderException('dataSetWriterId must be positive');
        }
    }

    /**
     * @return string
     */
    public function demuxKey(): string
    {
        return (string) $this->publisherId . '|' . $this->writerGroupId . '|' . $this->dataSetWriterId;
    }
}
