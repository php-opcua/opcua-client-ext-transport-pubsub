<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Module;

use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;

/**
 * Holds the subscriber's reader configurations for kernel demux.
 */
final class DataSetReaderModule extends PubSubModule
{
    /** @var array<string, DataSetReaderConfig> */
    private array $readersByKey;

    /**
     * @param list<DataSetReaderConfig> $readers
     */
    public function __construct(array $readers)
    {
        $this->readersByKey = [];
        foreach ($readers as $reader) {
            $this->readersByKey[$reader->demuxKey()] = $reader;
        }
    }

    /**
     * @return array<string, DataSetReaderConfig>
     */
    public function readersByKey(): array
    {
        return $this->readersByKey;
    }

    /**
     * @return list<DataSetReaderConfig>
     */
    public function readers(): array
    {
        return array_values($this->readersByKey);
    }
}
