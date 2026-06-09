<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Security;

use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

/**
 * Group key provider that fetches keys from a Security Key Service via a classic client.
 */
final class SksGroupKeyProvider implements GroupKeyProviderInterface
{
    private const DEFAULT_OBJECT_NODE_ID = 'i=14443';

    private const DEFAULT_METHOD_NODE_ID = 'i=15215';

    public const POLICY_AES256_CTR = 'http://opcfoundation.org/UA/SecurityPolicy#PubSub-Aes256-CTR';

    public const POLICY_AES128_CTR = 'http://opcfoundation.org/UA/SecurityPolicy#PubSub-Aes128-CTR';

    private ?string $signingKey = null;

    private ?string $encryptingKey = null;

    private ?string $keyNonce = null;

    private int $currentTokenId = 0;

    private float $tokenLifetimeMs = 0.0;

    private float $timeToNextKeyMs = 0.0;

    private float $refreshedAt = 0.0;

    /**
     * @param OpcUaClientInterface $client
     * @param string $securityGroupId
     * @param NodeId|string $objectNodeId
     * @param NodeId|string $methodNodeId
     * @param string $securityPolicyUri
     * @param int $requestedKeyCount
     */
    public function __construct(
        private readonly OpcUaClientInterface $client,
        private readonly string $securityGroupId,
        private readonly NodeId|string $objectNodeId = self::DEFAULT_OBJECT_NODE_ID,
        private readonly NodeId|string $methodNodeId = self::DEFAULT_METHOD_NODE_ID,
        private readonly string $securityPolicyUri = self::POLICY_AES256_CTR,
        private readonly int $requestedKeyCount = 1,
    ) {
    }

    public function signingKey(): string
    {
        return $this->requireKey($this->signingKey, 'signing');
    }

    public function encryptingKey(): string
    {
        return $this->requireKey($this->encryptingKey, 'encrypting');
    }

    public function keyNonce(): string
    {
        return $this->requireKey($this->keyNonce, 'keyNonce');
    }

    public function currentTokenId(): int
    {
        return $this->currentTokenId;
    }

    /**
     * @return int
     */
    public function tokenId(): int
    {
        return $this->currentTokenId;
    }

    public function lifetimeSecondsRemaining(): float
    {
        if ($this->tokenLifetimeMs <= 0.0) {
            return 0.0;
        }

        $elapsedMs = (microtime(true) - $this->refreshedAt) * 1000.0;
        $remaining = ($this->timeToNextKeyMs > 0.0 ? $this->timeToNextKeyMs : $this->tokenLifetimeMs) - $elapsedMs;

        return max(0.0, $remaining / 1000.0);
    }

    /**
     * @return void
     *
     * @throws PubSubSecurityException
     */
    public function refresh(): void
    {
        $callResult = $this->client->call(
            $this->objectNodeId,
            $this->methodNodeId,
            [
                new Variant(BuiltinType::String, $this->securityGroupId),
                new Variant(BuiltinType::UInt32, 0),
                new Variant(BuiltinType::UInt32, $this->requestedKeyCount),
            ],
        );

        if ($callResult->statusCode !== 0) {
            throw new PubSubSecurityException(
                sprintf('SksGroupKeyProvider: GetSecurityKeys returned bad status 0x%08X', $callResult->statusCode),
            );
        }

        $outputs = $callResult->outputArguments;
        if (count($outputs) < 5) {
            throw new PubSubSecurityException(
                'SksGroupKeyProvider: GetSecurityKeys returned fewer than 5 output arguments — unexpected server behaviour',
            );
        }

        $policyUri = $this->readScalar($outputs[0], 'SecurityPolicyUri');
        $firstTokenId = (int) $this->readScalar($outputs[1], 'FirstTokenId');
        $keys = $this->readArray($outputs[2], 'Keys');
        $timeToNextKey = (float) $this->readScalar($outputs[3], 'TimeToNextKey');
        $keyLifetime = (float) $this->readScalar($outputs[4], 'KeyLifetime');

        if ($keys === []) {
            throw new PubSubSecurityException('SksGroupKeyProvider: server returned an empty Keys array');
        }

        $layout = $this->keyLayoutFor(is_string($policyUri) ? $policyUri : $this->securityPolicyUri);
        [$signing, $encrypting, $nonce] = $this->splitKey($keys[0], $layout);

        $this->signingKey = $signing;
        $this->encryptingKey = $encrypting;
        $this->keyNonce = $nonce;
        $this->currentTokenId = $firstTokenId;
        $this->tokenLifetimeMs = $keyLifetime;
        $this->timeToNextKeyMs = $timeToNextKey;
        $this->refreshedAt = microtime(true);
    }

    /**
     * @throws PubSubSecurityException
     */
    private function requireKey(?string $value, string $label): string
    {
        if ($value === null) {
            throw new PubSubSecurityException(
                "SksGroupKeyProvider: {$label} key requested before refresh() succeeded — call refresh() first",
            );
        }

        return $value;
    }

    /**
     * @return array{signing: int, encrypting: int, nonce: int}
     * @throws PubSubSecurityException
     */
    private function keyLayoutFor(string $policyUri): array
    {
        return match ($policyUri) {
            self::POLICY_AES256_CTR => ['signing' => 32, 'encrypting' => 32, 'nonce' => 4],
            self::POLICY_AES128_CTR => ['signing' => 32, 'encrypting' => 16, 'nonce' => 4],
            default => throw new PubSubSecurityException(
                "SksGroupKeyProvider: unsupported SecurityPolicyUri '{$policyUri}' — supported: PubSub-Aes256-CTR, PubSub-Aes128-CTR",
            ),
        };
    }

    /**
     * @param array{signing: int, encrypting: int, nonce: int} $layout
     * @return array{0: string, 1: string, 2: string}
     * @throws PubSubSecurityException
     */
    private function splitKey(mixed $key, array $layout): array
    {
        if (! is_string($key)) {
            throw new PubSubSecurityException('SksGroupKeyProvider: key entry must be a ByteString');
        }

        $expected = $layout['signing'] + $layout['encrypting'] + $layout['nonce'];
        if (strlen($key) < $expected) {
            throw new PubSubSecurityException(
                "SksGroupKeyProvider: key has {$expected}-byte layout but server returned only " . strlen($key) . ' bytes',
            );
        }

        $offset = 0;
        $signing = substr($key, $offset, $layout['signing']);
        $offset += $layout['signing'];
        $encrypting = substr($key, $offset, $layout['encrypting']);
        $offset += $layout['encrypting'];
        $nonce = substr($key, $offset, $layout['nonce']);

        return [$signing, $encrypting, $nonce];
    }

    /**
     * @throws PubSubSecurityException
     */
    private function readScalar(Variant $variant, string $name): mixed
    {
        if (is_array($variant->value)) {
            throw new PubSubSecurityException("SksGroupKeyProvider: expected scalar for '{$name}' but got array");
        }

        return $variant->value;
    }

    /**
     * @return list<mixed>
     * @throws PubSubSecurityException
     */
    private function readArray(Variant $variant, string $name): array
    {
        if ($variant->value === null) {
            return [];
        }

        if (! is_array($variant->value)) {
            throw new PubSubSecurityException("SksGroupKeyProvider: expected array for '{$name}' but got scalar");
        }

        return array_values($variant->value);
    }
}
