<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class PreServerStartEvent
{
    public function __construct(
        public Server $server,
    ) {
    }
}
