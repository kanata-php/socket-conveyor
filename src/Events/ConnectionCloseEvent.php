<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class ConnectionCloseEvent
{
    public function __construct(
        public Server $server,
        public int $fd,
        public ?string $generation = null,
    ) {
    }
}
