<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Security;

/**
 * Source of group keying material for PubSub NetworkMessages (Part 14 §8).
 */
interface GroupKeyProviderInterface
{
    /**
     * @return string
     */
    public function signingKey(): string;

    /**
     * @return string
     */
    public function encryptingKey(): string;

    /**
     * @return string
     */
    public function keyNonce(): string;

    /**
     * @return int UInt32 SecurityTokenId identifying the current key material.
     */
    public function tokenId(): int;

    /**
     * @return void
     */
    public function refresh(): void;
}
