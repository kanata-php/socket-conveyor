<?php

use Conveyor\Constants;
use Conveyor\ConveyorServer;

require __DIR__ . '/../../vendor/autoload.php';

$host = getenv('CONVEYOR_HOST') ?: '127.0.0.1';
$port = (int) (getenv('CONVEYOR_PORT') ?: 8989);
$serverToken = getenv('CONVEYOR_SERVER_TOKEN') ?: 'local-server-token';

(new ConveyorServer())
    ->host($host)
    ->port($port)
    ->serverOptions([
        'worker_num' => 1,
        'task_worker_num' => 1,
    ])
    ->conveyorOptions([
        Constants::WEBSOCKET_SERVER_TOKEN => $serverToken,
    ])
    ->start();
