<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class PostServerReloadEvent
{
    public function __construct(
        public Server $server,
    ) {
    }
}
