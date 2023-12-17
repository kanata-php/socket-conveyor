<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class ServerStartedEvent
{
    public function __construct(
        public Server $server,
    ) {
    }
}
