<?php

use Conveyor\Constants;
use Conveyor\ConveyorServer;
use Conveyor\Events\RequestReceivedEvent;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

require __DIR__ . '/../vendor/autoload.php';

$host = getenv('BENCHMARK_CONVEYOR_HOST') ?: getenv('CONVEYOR_HOST') ?: '127.0.0.1';
$port = (int) (getenv('BENCHMARK_CONVEYOR_PORT') ?: getenv('CONVEYOR_PORT') ?: 8990);
$appId = getenv('BENCHMARK_CONVEYOR_APP_ID') ?: getenv('PUSHER_APP_ID') ?: 'local-app';
$appKey = getenv('BENCHMARK_CONVEYOR_APP_KEY') ?: getenv('PUSHER_APP_KEY') ?: 'local-key';
$appSecret = getenv('BENCHMARK_CONVEYOR_APP_SECRET') ?: getenv('PUSHER_APP_SECRET') ?: 'local-secret';

fwrite(STDOUT, sprintf(
    "Socket Conveyor benchmark target listening on %s:%d app_id=%s app_key=%s\n",
    $host,
    $port,
    $appId,
    $appKey,
));

(new ConveyorServer())
    ->host($host)
    ->port($port)
    ->serverOptions([
        'worker_num' => (int) (getenv('BENCHMARK_CONVEYOR_WORKERS') ?: 1),
        'task_worker_num' => (int) (getenv('BENCHMARK_CONVEYOR_TASK_WORKERS') ?: 1),
    ])
    ->conveyorOptions([
        Constants::WEBSOCKET_SUBPROTOCOL => Constants::PUSHER,
        Constants::USE_PRESENCE => false,
        Constants::APPS => [[
            'app_id' => $appId,
            'key' => $appKey,
            'secret' => $appSecret,
            'enable_client_messages' => true,
            'enabled' => true,
        ]],
    ])
    ->eventListeners([
        Constants::EVENT_REQUEST_RECEIVED => function (
            RequestReceivedEvent $event,
        ) {
            echo "####################################################\n";
            echo "Received request: {$event->request->server['request_method']} "
                . "{$event->request->server['request_uri']}\n";
        },
    ])
    ->start();
