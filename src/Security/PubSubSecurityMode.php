<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Security;

/**
 * PubSub security mode (Part 14 §8).
 */
enum PubSubSecurityMode: int
{
    case None = 1;
    case Sign = 2;
    case SignAndEncrypt = 3;
}
