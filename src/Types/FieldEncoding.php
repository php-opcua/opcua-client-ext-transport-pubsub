<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Types;

/**
 * DataSetMessage field encoding (Part 14 §6.2.3.2).
 */
enum FieldEncoding: int
{
    case Variant = 0;
    case RawData = 1;
    case DataValue = 2;
}
