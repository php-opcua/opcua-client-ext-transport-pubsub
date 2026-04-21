<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Exception;

use RuntimeException;

/**
 * Thrown when a PubSub payload fails signature verification or decryption.
 */
class PubSubSecurityException extends RuntimeException {}
