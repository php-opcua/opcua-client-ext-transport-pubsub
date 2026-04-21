<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

/**
 * A named group of DataSetReaderConfig entries.
 */
final readonly class ReaderGroupConfig
{
    /**
     * @param string $name
     * @param list<DataSetReaderConfig> $readers
     */
    public function __construct(
        public string $name,
        public array $readers,
    ) {}
}
