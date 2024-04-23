<?php

namespace Conveyor\SubProtocols\Conveyor\ActionMiddlewares\Interfaces;

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
