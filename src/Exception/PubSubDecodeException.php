<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Exception;

use RuntimeException;

/**
 * Thrown when a NetworkMessage or DataSetMessage cannot be decoded.
 */
class PubSubDecodeException extends RuntimeException
{
}
