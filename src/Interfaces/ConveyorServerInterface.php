<?php

namespace Conveyor\Interfaces;

use Conveyor\Config\ConveyorOptions;
use Conveyor\ConveyorServer;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Exception;
use OpenSwoole\Constant;
use OpenSwoole\Server as OpenSwooleBaseServer;

interface ConveyorServerInterface
{
    /**
     * @param string $host
     * @param int $port
     * @param int $mode
     * @param int $ssl
     * @param array<array-key, mixed> $serverOptions
     * @param ConveyorOptions|array<array-key, mixed> $conveyorOptions
     * @param array<array-key, callable> $eventListeners
     * @param array<array-key, GenericPersistenceInterface> $persistence
     * @return ConveyorServer
     * @throws Exception
     */
    public static function start(
        string $host = '0.0.0.0',
        int $port = 8989,
        int $mode = OpenSwooleBaseServer::POOL_MODE,
        int $ssl = Constant::SOCK_TCP,
        array $serverOptions = [],
        ConveyorOptions|array $conveyorOptions = [],
        array $eventListeners = [],
        array $persistence = [],
    ): ConveyorServer;
}
