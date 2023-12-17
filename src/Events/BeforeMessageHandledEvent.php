<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class BeforeMessageHandledEvent
{
    public function __construct(
        public Server $server,
        public string $data,
        public int $fd,
    ) {
    }
}
