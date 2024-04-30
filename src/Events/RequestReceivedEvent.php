<?php

namespace Conveyor\Events;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

class RequestReceivedEvent
{
    public function __construct(
        public Request $request,
        public Response $response,
    ) {
    }
}
