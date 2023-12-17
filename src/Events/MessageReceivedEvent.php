<?php

namespace Conveyor\Events;

use OpenSwoole\WebSocket\Server;

class MessageReceivedEvent
{
    public function __construct(
        public Server $server,
        public string $data,
    ) {
    }
}
