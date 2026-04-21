<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Exception;

use InvalidArgumentException;

/**
 * Thrown when a DataSetReaderConfig or DataSetMetaData is built with invalid values.
 */
class InvalidDataSetReaderException extends InvalidArgumentException {}
