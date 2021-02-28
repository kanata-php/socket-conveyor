<?php

namespace Tests\Assets;

use Exception;
use Conveyor\SocketHandlers\Interfaces\ExceptionHandlerInterface;

class SampleExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * @param Exception $e          Current exception being handled.
     * @param array     $parsedData Parsed data at the current message.
     * @param mixed     $fd         File descriptor (connection).
     * @param mixed     $server     The socket server instance useful for
     *                              sending back the expected error message.
     *
     * @throws SampleCustomException
     */
    public function handle(Exception $e, array $parsedData, $fd = null, $server = null)
    {
        throw new SampleCustomException('This is a test custom exception!');
    }
}
