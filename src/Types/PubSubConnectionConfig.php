<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

/**
 * PubSub connection descriptor: transport endpoint, optional publisher filter, reader groups.
 */
final readonly class PubSubConnectionConfig
{
    /**
     * @param string $transportProfileUri
     * @param string $endpoint
     * @param null|int|string $publisherIdFilter
     * @param list<ReaderGroupConfig> $readerGroups
     */
    public function __construct(
        public string $transportProfileUri,
        public string $endpoint,
        public null|int|string $publisherIdFilter,
        public array $readerGroups,
    ) {
    }
}
