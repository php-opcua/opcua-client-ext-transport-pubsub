<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Security;

/**
 * Group key provider backed by in-memory pre-shared keys.
 */
final readonly class StaticGroupKeyProvider implements GroupKeyProviderInterface
{
    /**
     * @param string $signingKey
     * @param string $encryptingKey
     * @param string $keyNonce
     * @param int $tokenId
     */
    public function __construct(
        private string $signingKey,
        private string $encryptingKey,
        private string $keyNonce,
        private int $tokenId = 1,
    ) {}

    /**
     * @return string
     */
    public function signingKey(): string
    {
        return $this->signingKey;
    }

    /**
     * @return string
     */
    public function encryptingKey(): string
    {
        return $this->encryptingKey;
    }

    /**
     * @return string
     */
    public function keyNonce(): string
    {
        return $this->keyNonce;
    }

    /**
     * @return int
     */
    public function tokenId(): int
    {
        return $this->tokenId;
    }

    /**
     * @return void
     */
    public function refresh(): void {}
}
