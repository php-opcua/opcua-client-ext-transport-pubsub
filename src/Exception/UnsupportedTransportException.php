<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Exception;

use RuntimeException;

/**
 * Thrown when a transport cannot be opened or used.
 */
class UnsupportedTransportException extends RuntimeException {}
