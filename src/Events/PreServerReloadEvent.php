<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class PreServerReloadEvent
{
    public function __construct(
        public Server $server,
    ) {
    }
}
