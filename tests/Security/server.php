<?php

use Conveyor\Constants;
use Conveyor\ConveyorServer;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\SocketPresenceChannelPersistenceTable;

require '/workspace/vendor/autoload.php';
$options = [
    Constants::WEBSOCKET_SUBPROTOCOL => Constants::PUSHER,
    Constants::USE_PRESENCE => true,
    Constants::APPS => [
        [
            'app_id' => '1', 'key' => '278d425bdf160c739803', 'secret' => '7ad3773142a6692b25b8',
            'enable_client_messages' => true, 'enabled' => true,
        ],
        [
            'app_id' => 'local-app', 'key' => 'local-key', 'secret' => 'local-secret',
            'enable_client_messages' => true, 'enabled' => true,
        ],
    ],
];
$limits = getenv('QA_LIMITS');
if ($limits !== false && $limits !== '') {
    $options['pusher_limits'] = json_decode($limits, true, 64, JSON_THROW_ON_ERROR);
}
$server = new ConveyorServer();
if (getenv('QA_PRESENCE_FAILURE') === '1') {
    $adapter = new class extends SocketPresenceChannelPersistenceTable {
        public function add(int $fd, string $channel, string $channelData): void
        {
            if (preg_match('/^[0-9a-f]{64}$/D', $channel) !== 1) {
                throw new \RuntimeException('Expected application-scoped adapter channel');
            }
            parent::add($fd, $channel, $channelData);
            if (str_contains($channelData, '"kill":true')) {
                posix_kill(getmypid(), 9);
            }
            if (str_contains($channelData, '"fail":true')) {
                throw new \RuntimeException('Disposable injected persistence failure');
            }
        }
    };
    $server->persistence([Constants::PRESENCE_CHANNELS => $adapter]);
}
$server->host('0.0.0.0')->port(8080)
    ->serverOptions(['worker_num' => 2, 'task_worker_num' => 2])
    ->conveyorOptions($options)->start();
