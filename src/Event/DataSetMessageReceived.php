<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Event;

use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;

/**
 * Dispatched once per DataSetMessage after demux onto a matching reader.
 */
final readonly class DataSetMessageReceived
{
    /**
     * @param DataSetMessage $message
     * @param string $transportUri
     * @param int|string $publisherId
     * @param int $writerGroupId
     */
    public function __construct(
        public DataSetMessage $message,
        public string $transportUri,
        public int|string $publisherId,
        public int $writerGroupId,
    ) {}
}
