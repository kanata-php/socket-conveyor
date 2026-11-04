<?php

use Conveyor\Constants;
use Conveyor\ConveyorServer;

require __DIR__ . '/../vendor/autoload.php';

$credentials = [];
foreach (['PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET'] as $name) {
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
    $credentials[$name] = $value;
}

$portValue = getenv('CONVEYOR_PORT');
$port = filter_var($portValue === false ? '8080' : $portValue, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1024, 'max_range' => 65535],
]);
if ($port === false) {
    fwrite(STDERR, "CONVEYOR_PORT must be an integer between 1024 and 65535.\n");
    exit(1);
}

(new ConveyorServer())
    ->host(getenv('CONVEYOR_HOST') ?: '0.0.0.0')
    ->port($port)
    ->serverOptions([
        'worker_num' => 1,
        'task_worker_num' => 1,
    ])
    ->conveyorOptions([
        Constants::WEBSOCKET_SUBPROTOCOL => Constants::PUSHER,
        Constants::USE_PRESENCE => true,
        Constants::APPS => [[
            'app_id' => $credentials['PUSHER_APP_ID'],
            'key' => $credentials['PUSHER_APP_KEY'],
            'secret' => $credentials['PUSHER_APP_SECRET'],
            'enable_client_messages' => true,
            'enabled' => true,
        ]],
    ])
    ->start();
