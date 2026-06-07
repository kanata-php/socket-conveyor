<?php

use Conveyor\Constants;
use Conveyor\ConveyorServer;
use Hook\Action;
use OpenSwoole\WebSocket\Server;

require __DIR__ . '/../../vendor/autoload.php';

$host = getenv('CONVEYOR_HOST') ?: '127.0.0.1';
$port = (int) (getenv('CONVEYOR_PORT') ?: 8990);

$appId = getenv('PUSHER_APP_ID') ?: 'local-app';
$appKey = getenv('PUSHER_APP_KEY') ?: 'local-key';
$appSecret = getenv('PUSHER_APP_SECRET') ?: 'local-secret';

fwrite(STDOUT, "Conveyor Pusher server listening on {$host}:{$port}\n");
fwrite(STDOUT, "App id={$appId} key={$appKey}\n");

Action::addAction(
    Constants::ACTION_PUSHER_MESSAGE_RECEIVED,
    function (int $fd, string $raw, array $frame, Server $server): void {
        fwrite(
            STDOUT,
            sprintf(
                "[%s] incoming pusher fd=%d event=%s %s\n",
                date(DATE_ATOM),
                $fd,
                is_string($frame['event'] ?? null) ? $frame['event'] : 'unknown',
                $raw,
            ),
        );
    },
);

Action::addAction(
    Constants::ACTION_PUSHER_REST_EVENT_RECEIVED,
    function (array $payload, string $name, array $channels, string $data, ?string $socketId): void {
        fwrite(
            STDOUT,
            sprintf(
                "[%s] incoming pusher rest event=%s channels=%s socket_id=%s data=%s\n",
                date(DATE_ATOM),
                $name,
                json_encode($channels, JSON_UNESCAPED_SLASHES),
                $socketId ?? 'none',
                $data,
            ),
        );
    },
);

(new ConveyorServer())
    ->host($host)
    ->port($port)
    ->serverOptions([
        'worker_num' => 1,
        'task_worker_num' => 1,
    ])
    ->conveyorOptions([
        Constants::WEBSOCKET_SUBPROTOCOL => Constants::PUSHER,
        Constants::USE_PRESENCE => true,
        Constants::APPS => [[
            'app_id' => $appId,
            'key' => $appKey,
            'secret' => $appSecret,
            'enable_client_messages' => true,
            'enabled' => true,
        ]],
    ])
    ->start();
