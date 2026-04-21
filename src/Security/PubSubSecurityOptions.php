<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Security;

use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;

/**
 * Subscriber-side PubSub security configuration (Part 14 §8).
 */
final readonly class PubSubSecurityOptions
{
    /**
     * @param PubSubSecurityMode $mode
     * @param ?GroupKeyProviderInterface $keyProvider
     *
     * @throws PubSubSecurityException
     */
    public function __construct(
        public PubSubSecurityMode $mode,
        public ?GroupKeyProviderInterface $keyProvider = null,
    ) {
        if ($mode !== PubSubSecurityMode::None && $keyProvider === null) {
            throw new PubSubSecurityException(
                'PubSubSecurityOptions: Sign and SignAndEncrypt modes require a GroupKeyProviderInterface',
            );
        }
    }
}
