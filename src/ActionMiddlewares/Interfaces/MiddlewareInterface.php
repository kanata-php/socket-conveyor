<?php

namespace Conveyor\ActionMiddlewares\Interfaces;

interface MiddlewareInterface
{
    /**
     * @param mixed $payload
     *
     * @throws Exception
     */
    public function __invoke($payload);
}
