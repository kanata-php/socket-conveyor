<?php

namespace Tests\Assets;

use Exception;
use Conveyor\ActionMiddlewares\Abstractions\AbstractActionMiddleware;

class SampleMiddleware2 extends AbstractActionMiddleware
{
    /**
     * @param mixed $payload
     *
     * @throws Exception
     */
    public function __invoke(mixed $payload): mixed
    {
        $this->checkToken($payload['data']);

        return $payload;
    }

    /**
     * @param array<string, string> $data
     *
     * @return void
     *
     * @throws Exception
     */
    private function checkToken(array $data): void
    {
        if ($data['second-verification'] !== 'valid') {
            throw new Exception('Invalid Second verification');
        }
    }
}
