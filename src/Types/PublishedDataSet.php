<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

use PhpOpcua\Client\Types\NodeId;

/**
 * Subscriber-side projection of a PublishedDataSet defined on the publisher.
 */
final readonly class PublishedDataSet
{
    /**
     * @param string $name
     * @param DataSetMetaData $metaData
     * @param list<NodeId> $publishedVariables
     */
    public function __construct(
        public string $name,
        public DataSetMetaData $metaData,
        public array $publishedVariables = [],
    ) {}
}
