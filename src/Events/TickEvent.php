<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class TickEvent
{
    public function __construct(
        public Server $server,
    ) {
    }
}
