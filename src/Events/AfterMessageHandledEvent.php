<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class AfterMessageHandledEvent
{
    public function __construct(
        public Server $server,
        public string $data,
        public int $fd,
    ) {
    }
}
