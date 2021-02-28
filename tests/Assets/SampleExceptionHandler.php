<?php

namespace Tests\Assets;

use Conveyor\SocketHandlers\Interfaces\ExceptionHandlerInterface;

class SampleExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * @param $server The socket server instance useful for
     *                sending back the expected error message.
     *
     * @throws SampleCustomException
     */
    public function handle($server = null)
    {
        throw new SampleCustomException('This is a test custom exception!');
    }
}
