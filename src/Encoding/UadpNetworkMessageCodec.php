<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Encoding;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubDecodeException;
use PhpOpcua\Client\ExtTransportPubSub\Exception\PubSubSecurityException;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityCodec;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityMode;
use PhpOpcua\Client\ExtTransportPubSub\Security\PubSubSecurityOptions;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMessage;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use PhpOpcua\Client\ExtTransportPubSub\Types\NetworkMessage;

/**
 * UADP binary codec for a NetworkMessage (Part 14 §6.2.4, §7.2, §8).
 */
final class UadpNetworkMessageCodec implements NetworkMessageCodec
{
    private const FLAGS_VERSION_MASK = 0x0F;

    private const FLAGS_PUBLISHER_ID = 0x10;

    private const FLAGS_GROUP_HEADER = 0x20;

    private const FLAGS_PAYLOAD_HEADER = 0x40;

    private const FLAGS_EXT1 = 0x80;

    private const EXT1_PUBLISHER_ID_TYPE_MASK = 0x07;

    private const EXT1_DATASET_CLASS_ID = 0x08;

    private const EXT1_SECURITY = 0x10;

    private const EXT1_TIMESTAMP = 0x20;

    private const EXT1_PICOSECONDS = 0x40;

    private const EXT1_EXT2 = 0x80;

    private const EXT2_CHUNK = 0x01;

    private const EXT2_PROMOTED_FIELDS = 0x02;

    private const EXT2_MESSAGE_TYPE_MASK = 0x1C;

    private const PUB_ID_TYPE_BYTE = 0;

    private const PUB_ID_TYPE_UINT16 = 1;

    private const PUB_ID_TYPE_UINT32 = 2;

    private const PUB_ID_TYPE_UINT64 = 3;

    private const PUB_ID_TYPE_STRING = 4;

    private const GROUP_WRITER_ID = 0x01;

    private const GROUP_VERSION = 0x02;

    private const GROUP_NETWORK_MESSAGE_NUMBER = 0x04;

    private const GROUP_SEQUENCE_NUMBER = 0x08;

    private const SEC_FLAG_SIGNED = 0x01;

    private const SEC_FLAG_ENCRYPTED = 0x02;

    private const SEC_FLAG_FOOTER = 0x04;

    private readonly UadpDataSetMessageCodec $dataSetCodec;

    /**
     * @param ?UadpDataSetMessageCodec $dataSetCodec
     * @param ?PubSubSecurityOptions $security
     */
    public function __construct(
        ?UadpDataSetMessageCodec $dataSetCodec = null,
        private readonly ?PubSubSecurityOptions $security = null,
    ) {
        $this->dataSetCodec = $dataSetCodec ?? new UadpDataSetMessageCodec();
    }

    /**
     * {@inheritDoc}
     */
    public function decode(string $payload, array $readersByKey): NetworkMessage
    {
        $decoder = new BinaryDecoder($payload);

        $flags = $decoder->readByte();
        $version = $flags & self::FLAGS_VERSION_MASK;

        $ext1 = ($flags & self::FLAGS_EXT1) !== 0 ? $decoder->readByte() : 0;
        $ext2 = ($ext1 & self::EXT1_EXT2) !== 0 ? $decoder->readByte() : 0;

        $this->rejectUnsupportedFeatures($ext2);

        $publisherId = ($flags & self::FLAGS_PUBLISHER_ID) !== 0
            ? $this->readPublisherId($decoder, $ext1 & self::EXT1_PUBLISHER_ID_TYPE_MASK)
            : 0;

        $dataSetClassId = null;
        if (($ext1 & self::EXT1_DATASET_CLASS_ID) !== 0) {
            $dataSetClassId = $decoder->readGuid();
        }

        $writerGroupId = 0;
        $networkMessageNumber = 0;
        $sequenceNumber = 0;
        if (($flags & self::FLAGS_GROUP_HEADER) !== 0) {
            $groupFlags = $decoder->readByte();
            if (($groupFlags & self::GROUP_WRITER_ID) !== 0) {
                $writerGroupId = $decoder->readUInt16();
            }
            if (($groupFlags & self::GROUP_VERSION) !== 0) {
                $decoder->readUInt32();
            }
            if (($groupFlags & self::GROUP_NETWORK_MESSAGE_NUMBER) !== 0) {
                $networkMessageNumber = $decoder->readUInt16();
            }
            if (($groupFlags & self::GROUP_SEQUENCE_NUMBER) !== 0) {
                $sequenceNumber = $decoder->readUInt16();
            }
        }

        $dataSetWriterIds = [];
        if (($flags & self::FLAGS_PAYLOAD_HEADER) !== 0) {
            $messageCount = $decoder->readByte();
            for ($i = 0; $i < $messageCount; $i++) {
                $dataSetWriterIds[] = $decoder->readUInt16();
            }
        } else {
            $dataSetWriterIds[] = 0;
        }

        $timestamp = ($ext1 & self::EXT1_TIMESTAMP) !== 0 ? $decoder->readDateTime() : null;
        if (($ext1 & self::EXT1_PICOSECONDS) !== 0) {
            $decoder->readUInt16();
        }

        if (($ext1 & self::EXT1_SECURITY) !== 0) {
            $plaintextPayload = $this->unwrapSecurity($payload, $decoder);
            $payloadDecoder = new BinaryDecoder($plaintextPayload);
        } else {
            $payloadDecoder = $decoder;
        }

        $messageSizes = null;
        if (count($dataSetWriterIds) > 1) {
            $messageSizes = [];
            foreach ($dataSetWriterIds as $_) {
                $messageSizes[] = $payloadDecoder->readUInt16();
            }
        }

        $dataSetMessages = $this->decodeDataSets(
            $payloadDecoder,
            $dataSetWriterIds,
            $messageSizes,
            $publisherId,
            $writerGroupId,
            $readersByKey,
        );

        return new NetworkMessage(
            publisherId: $publisherId,
            writerGroupId: $writerGroupId,
            networkMessageNumber: $networkMessageNumber,
            sequenceNumber: $sequenceNumber,
            timestamp: $timestamp,
            dataSetMessages: $dataSetMessages,
            dataSetClassId: $dataSetClassId,
            uadpVersion: $version,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function encode(NetworkMessage $message): string
    {
        $encoder = new BinaryEncoder();

        $pubIdType = $this->classifyPublisherId($message->publisherId);
        $securityEnabled = $this->security !== null && $this->security->mode !== PubSubSecurityMode::None;

        $flags = ($message->uadpVersion & self::FLAGS_VERSION_MASK)
            | self::FLAGS_PUBLISHER_ID
            | self::FLAGS_GROUP_HEADER
            | self::FLAGS_PAYLOAD_HEADER
            | self::FLAGS_EXT1;

        $ext1 = $pubIdType;
        if ($message->timestamp !== null) {
            $ext1 |= self::EXT1_TIMESTAMP;
        }
        if ($securityEnabled) {
            $ext1 |= self::EXT1_SECURITY;
        }

        $encoder->writeByte($flags);
        $encoder->writeByte($ext1);

        $this->writePublisherId($encoder, $message->publisherId, $pubIdType);

        $groupFlags = self::GROUP_WRITER_ID
            | ($message->networkMessageNumber !== 0 ? self::GROUP_NETWORK_MESSAGE_NUMBER : 0)
            | ($message->sequenceNumber !== 0 ? self::GROUP_SEQUENCE_NUMBER : 0);
        $encoder->writeByte($groupFlags);
        $encoder->writeUInt16($message->writerGroupId);
        if (($groupFlags & self::GROUP_NETWORK_MESSAGE_NUMBER) !== 0) {
            $encoder->writeUInt16($message->networkMessageNumber);
        }
        if (($groupFlags & self::GROUP_SEQUENCE_NUMBER) !== 0) {
            $encoder->writeUInt16($message->sequenceNumber);
        }

        $count = count($message->dataSetMessages);
        $encoder->writeByte($count);
        foreach ($message->dataSetMessages as $dsm) {
            $encoder->writeUInt16($dsm->dataSetWriterId);
        }

        if ($message->timestamp !== null) {
            $encoder->writeDateTime($message->timestamp);
        }

        if ($securityEnabled) {
            return $this->writeSecuredPayload($encoder, $message);
        }

        if ($count > 1) {
            $sizes = $this->computeDataSetSizes($message->dataSetMessages);
            foreach ($sizes as $size) {
                $encoder->writeUInt16($size);
            }
        }

        foreach ($message->dataSetMessages as $dsm) {
            $this->dataSetCodec->encode($encoder, $dsm, null);
        }

        return $encoder->getBuffer();
    }

    /**
     * @param int $ext2
     *
     * @throws PubSubDecodeException
     */
    private function rejectUnsupportedFeatures(int $ext2): void
    {
        if (($ext2 & self::EXT2_CHUNK) !== 0) {
            throw new PubSubDecodeException('UADP NetworkMessage: chunked messages are not supported');
        }

        if (($ext2 & self::EXT2_PROMOTED_FIELDS) !== 0) {
            throw new PubSubDecodeException('UADP NetworkMessage: promoted fields are not supported');
        }

        $messageType = ($ext2 & self::EXT2_MESSAGE_TYPE_MASK) >> 2;
        if ($messageType !== 0) {
            throw new PubSubDecodeException(
                "UADP NetworkMessage: unsupported NetworkMessageType {$messageType} (only DataSet messages are supported)",
            );
        }
    }

    /**
     * @param string $fullBuffer
     * @param BinaryDecoder $decoder
     * @return string
     *
     * @throws PubSubDecodeException
     * @throws PubSubSecurityException
     */
    private function unwrapSecurity(string $fullBuffer, BinaryDecoder $decoder): string
    {
        if ($this->security === null || $this->security->mode === PubSubSecurityMode::None) {
            throw new PubSubSecurityException(
                'UADP NetworkMessage: security flag set but no PubSubSecurityOptions configured on the codec',
            );
        }

        $provider = $this->security->keyProvider;
        if ($provider === null) {
            throw new PubSubSecurityException('UADP NetworkMessage: security configured without a key provider');
        }

        $securityFlags = $decoder->readByte();
        $tokenId = $decoder->readUInt32();
        $nonceLength = $decoder->readByte();
        $messageNonce = $decoder->readRawBytes($nonceLength);
        $footerSize = 0;
        if (($securityFlags & self::SEC_FLAG_FOOTER) !== 0) {
            $footerSize = $decoder->readUInt16();
        }

        if ($tokenId !== $provider->tokenId()) {
            throw new PubSubSecurityException(
                "UADP NetworkMessage: SecurityTokenId {$tokenId} does not match the current provider token " . $provider->tokenId(),
            );
        }

        $payloadStart = $decoder->getOffset();
        $totalLen = strlen($fullBuffer);
        $signatureStart = $totalLen - PubSubSecurityCodec::SIGNATURE_LENGTH;
        if ($signatureStart < $payloadStart + $footerSize) {
            throw new PubSubSecurityException('UADP NetworkMessage: buffer too small to contain signature + footer');
        }

        $payloadLen = $signatureStart - $footerSize - $payloadStart;
        $payloadBytes = substr($fullBuffer, $payloadStart, $payloadLen);
        $signature = substr($fullBuffer, $signatureStart);

        $signedPortion = substr($fullBuffer, 0, $signatureStart);
        if (! PubSubSecurityCodec::verify($signedPortion, $signature, $provider->signingKey())) {
            throw new PubSubSecurityException('UADP NetworkMessage: HMAC signature verification failed');
        }

        if (($securityFlags & self::SEC_FLAG_ENCRYPTED) !== 0) {
            if ($nonceLength !== PubSubSecurityCodec::MESSAGE_NONCE_LENGTH) {
                throw new PubSubSecurityException(
                    'UADP NetworkMessage: MessageNonce length ' . $nonceLength . ' unsupported for AES-CTR (expected ' . PubSubSecurityCodec::MESSAGE_NONCE_LENGTH . ')',
                );
            }

            return PubSubSecurityCodec::decryptCtr(
                $payloadBytes,
                $provider->keyNonce(),
                $messageNonce,
                $provider->encryptingKey(),
            );
        }

        $decoder->skip($payloadLen + $footerSize + PubSubSecurityCodec::SIGNATURE_LENGTH);

        return $payloadBytes;
    }

    /**
     * @param BinaryEncoder $encoder
     * @param NetworkMessage $message
     * @return string
     *
     * @throws PubSubSecurityException
     */
    private function writeSecuredPayload(BinaryEncoder $encoder, NetworkMessage $message): string
    {
        $provider = $this->security?->keyProvider
            ?? throw new PubSubSecurityException('UADP NetworkMessage: security configured without a key provider');

        $flags = self::SEC_FLAG_SIGNED;
        if ($this->security->mode === PubSubSecurityMode::SignAndEncrypt) {
            $flags |= self::SEC_FLAG_ENCRYPTED;
        }

        $messageNonce = PubSubSecurityCodec::newMessageNonce();

        $encoder->writeByte($flags);
        $encoder->writeUInt32($provider->tokenId());
        $encoder->writeByte(PubSubSecurityCodec::MESSAGE_NONCE_LENGTH);
        $encoder->writeRawBytes($messageNonce);

        $payloadEncoder = new BinaryEncoder();
        $count = count($message->dataSetMessages);
        if ($count > 1) {
            $sizes = $this->computeDataSetSizes($message->dataSetMessages);
            foreach ($sizes as $size) {
                $payloadEncoder->writeUInt16($size);
            }
        }
        foreach ($message->dataSetMessages as $dsm) {
            $this->dataSetCodec->encode($payloadEncoder, $dsm, null);
        }

        $plaintext = $payloadEncoder->getBuffer();
        $payloadBytes = ($flags & self::SEC_FLAG_ENCRYPTED) !== 0
            ? PubSubSecurityCodec::encryptCtr(
                $plaintext,
                $provider->keyNonce(),
                $messageNonce,
                $provider->encryptingKey(),
            )
            : $plaintext;

        $encoder->writeRawBytes($payloadBytes);

        $signedPortion = $encoder->getBuffer();
        $signature = PubSubSecurityCodec::sign($signedPortion, $provider->signingKey());
        $encoder->writeRawBytes($signature);

        return $encoder->getBuffer();
    }

    /**
     * @param BinaryDecoder $decoder
     * @param int $pubIdType
     * @return int|string
     *
     * @throws PubSubDecodeException
     */
    private function readPublisherId(BinaryDecoder $decoder, int $pubIdType): int|string
    {
        return match ($pubIdType) {
            self::PUB_ID_TYPE_BYTE => $decoder->readByte(),
            self::PUB_ID_TYPE_UINT16 => $decoder->readUInt16(),
            self::PUB_ID_TYPE_UINT32 => $decoder->readUInt32(),
            self::PUB_ID_TYPE_UINT64 => $decoder->readUInt64(),
            self::PUB_ID_TYPE_STRING => $decoder->readString() ?? '',
            default => throw new PubSubDecodeException("UADP NetworkMessage: invalid PublisherId type {$pubIdType}"),
        };
    }

    /**
     * @param int|string $value
     * @return int
     */
    private function classifyPublisherId(int|string $value): int
    {
        if (is_string($value)) {
            return self::PUB_ID_TYPE_STRING;
        }

        if ($value <= 0xFF) {
            return self::PUB_ID_TYPE_BYTE;
        }

        if ($value <= 0xFFFF) {
            return self::PUB_ID_TYPE_UINT16;
        }

        if ($value <= 0xFFFFFFFF) {
            return self::PUB_ID_TYPE_UINT32;
        }

        return self::PUB_ID_TYPE_UINT64;
    }

    /**
     * @param BinaryEncoder $encoder
     * @param int|string $value
     * @param int $type
     */
    private function writePublisherId(BinaryEncoder $encoder, int|string $value, int $type): void
    {
        match ($type) {
            self::PUB_ID_TYPE_BYTE => $encoder->writeByte((int) $value),
            self::PUB_ID_TYPE_UINT16 => $encoder->writeUInt16((int) $value),
            self::PUB_ID_TYPE_UINT32 => $encoder->writeUInt32((int) $value),
            self::PUB_ID_TYPE_UINT64 => $encoder->writeUInt64((int) $value),
            self::PUB_ID_TYPE_STRING => $encoder->writeString((string) $value),
        };
    }

    /**
     * @param BinaryDecoder $decoder
     * @param list<int> $dataSetWriterIds
     * @param ?list<int> $messageSizes
     * @param int|string $publisherId
     * @param int $writerGroupId
     * @param array<string, DataSetReaderConfig> $readersByKey
     * @return list<DataSetMessage>
     *
     * @throws PubSubDecodeException
     */
    private function decodeDataSets(
        BinaryDecoder $decoder,
        array $dataSetWriterIds,
        ?array $messageSizes,
        int|string $publisherId,
        int $writerGroupId,
        array $readersByKey,
    ): array {
        $out = [];
        foreach ($dataSetWriterIds as $i => $writerId) {
            $reader = $this->lookupReader($publisherId, $writerGroupId, $writerId, $readersByKey);
            $metaData = $reader?->dataSetMetaData;

            $before = $decoder->getOffset();
            $out[] = $this->dataSetCodec->decode($decoder, $writerId, $metaData);
            $consumed = $decoder->getOffset() - $before;

            $declaredSize = $messageSizes[$i] ?? null;
            if ($declaredSize !== null && $consumed !== $declaredSize) {
                throw new PubSubDecodeException(
                    "UADP NetworkMessage: DataSetMessage #{$i} reported size {$declaredSize} but {$consumed} bytes were consumed",
                );
            }
        }

        return $out;
    }

    /**
     * @param int|string $publisherId
     * @param int $writerGroupId
     * @param int $writerId
     * @param array<string, DataSetReaderConfig> $readersByKey
     * @return ?DataSetReaderConfig
     */
    private function lookupReader(
        int|string $publisherId,
        int $writerGroupId,
        int $writerId,
        array $readersByKey,
    ): ?DataSetReaderConfig {
        $key = (string) $publisherId . '|' . $writerGroupId . '|' . $writerId;
        if (isset($readersByKey[$key])) {
            return $readersByKey[$key];
        }

        $anyGroupKey = (string) $publisherId . '|0|' . $writerId;
        if (isset($readersByKey[$anyGroupKey])) {
            return $readersByKey[$anyGroupKey];
        }

        return null;
    }

    /**
     * @param list<DataSetMessage> $messages
     * @return list<int>
     */
    private function computeDataSetSizes(array $messages): array
    {
        $sizes = [];
        foreach ($messages as $message) {
            $scratch = new BinaryEncoder();
            $this->dataSetCodec->encode($scratch, $message, null);
            $sizes[] = $scratch->getSize();
        }

        return $sizes;
    }
}
