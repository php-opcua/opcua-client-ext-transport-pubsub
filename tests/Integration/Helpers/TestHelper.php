<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtTransportPubSub\Tests\Integration\Helpers;

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetMetaData;
use PhpOpcua\Client\ExtTransportPubSub\Types\DataSetReaderConfig;
use PhpOpcua\Client\ExtTransportPubSub\Types\FieldMetaData;
use PhpOpcua\Client\Types\BuiltinType;
use Throwable;

final class TestHelper
{
    public const ENDPOINT_NO_SECURITY = 'opc.tcp://localhost:4840/UA/TestServer';

    public const ENDPOINT_USERPASS = 'opc.tcp://localhost:4841/UA/TestServer';

    public const ENDPOINT_ALL_SECURITY = 'opc.tcp://localhost:4843/UA/TestServer';

    public const ENDPOINT_SKS = 'opc.tcp://localhost:4851/UA/TestServer';

    public const SKS_OBJECT_NODE_ID = 'ns=1;s=TestServer/SecurityKeyService';

    public const SKS_METHOD_NODE_ID = 'ns=1;s=TestServer/SecurityKeyService/GetSecurityKeys';

    public const SKS_GROUP_ID = 'test-group';

    public const SKS_EXPECTED_TOKEN_ID = 7;

    /**
     * @return string
     */
    public static function getCertsDir(): string
    {
        return getenv('OPCUA_CERTS_DIR') ?: __DIR__ . '/../../../../uanetstandard-test-suite/certs';
    }

    /**
     * @return Client
     */
    public static function connectNoSecurity(): Client
    {
        return (new ClientBuilder())->connect(self::ENDPOINT_NO_SECURITY);
    }

    /**
     * @param ?Client $client
     */
    public static function safeDisconnect(?Client $client): void
    {
        if ($client === null) {
            return;
        }

        try {
            $client->disconnect();
        } catch (Throwable) {
        }
    }

    /**
     * @return int
     */
    public static function pickFreeLoopbackPort(): int
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    /**
     * @param int|string $publisherId
     * @param int $writerGroupId
     * @param int $dataSetWriterId
     * @param list<array{0: string, 1: BuiltinType}> $fields
     * @return DataSetReaderConfig
     */
    public static function makeReader(
        int|string $publisherId,
        int $writerGroupId,
        int $dataSetWriterId,
        array $fields,
    ): DataSetReaderConfig {
        $fieldMetas = [];
        foreach ($fields as [$name, $type]) {
            $fieldMetas[] = new FieldMetaData($name, $type);
        }

        return new DataSetReaderConfig(
            publisherId: $publisherId,
            writerGroupId: $writerGroupId,
            dataSetWriterId: $dataSetWriterId,
            dataSetMetaData: new DataSetMetaData(
                name: "reader-{$dataSetWriterId}",
                fields: $fieldMetas,
            ),
        );
    }
}
