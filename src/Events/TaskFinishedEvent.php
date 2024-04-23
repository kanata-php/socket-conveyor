<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class TaskFinishedEvent
{
    public function __construct(
        public Server $server,
        public string $data,
        public int $taskId,
    ) {
    }
}
