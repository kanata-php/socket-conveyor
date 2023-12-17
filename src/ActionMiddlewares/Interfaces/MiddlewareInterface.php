<?php

namespace Conveyor\ActionMiddlewares\Interfaces;

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
