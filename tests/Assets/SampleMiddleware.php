<?php

namespace Tests\Assets;

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
        $data = $payload->getParsedData();

        $this->checkToken($data);

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