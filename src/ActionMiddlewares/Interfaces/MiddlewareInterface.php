<?php

namespace Conveyor\ActionMiddlewares\Interfaces;

use Conveyor\SocketHandlers\Workflow\MessageRouter;
use Exception;

interface MiddlewareInterface
{
    /**
     * @param mixed $payload
     * @return mixed
     *
     * @throws Exception
     */
    public function __invoke(mixed $payload): mixed;
}
