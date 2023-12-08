<?php

namespace Tests\Assets;

use Conveyor\SocketHandlers\Workflow\MessageRouter;
use Exception;
use Conveyor\ActionMiddlewares\Abstractions\AbstractActionMiddleware;

class SampleMiddleware extends AbstractActionMiddleware
{
    /**
     * @param mixed $payload
     *
     * @throws Exception
     */
    public function __invoke($payload)
    {
        $this->checkToken($payload);

        return $payload;
    }

    /**
     * @param array $data
     *
     * @return void
     *
     * @throws Exception
     */
    private function checkToken(array $data) : void
    {
        if ($data['token'] !== 'valid-token') {
            throw new Exception('Invalid Token');
        }
    }
}
