<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

use DateTimeImmutable;

/**
 * A decoded UADP or JSON NetworkMessage.
 */
final readonly class NetworkMessage
{
    /**
     * @param int|string $publisherId
     * @param int $writerGroupId
     * @param int $networkMessageNumber
     * @param int $sequenceNumber
     * @param ?DateTimeImmutable $timestamp
     * @param list<DataSetMessage> $dataSetMessages
     * @param ?string $dataSetClassId
     * @param int $uadpVersion
     */
    public function __construct(
        public int|string $publisherId,
        public int $writerGroupId,
        public int $networkMessageNumber,
        public int $sequenceNumber,
        public ?DateTimeImmutable $timestamp,
        public array $dataSetMessages,
        public ?string $dataSetClassId = null,
        public int $uadpVersion = 1,
    ) {
    }
}
