<?php

namespace Tests\Assets;

use Exception;
use Conveyor\SocketHandlers\Interfaces\ExceptionHandlerInterface;

class SampleExceptionHandler implements ExceptionHandlerInterface
{
    /** @var Exception */
    public $e;

    /** @var array */
    public $parsedData;

    /** @var mixed */
    public $fd;

    /** @var mixed */
    public $server;

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
        $this->e          = $e;
        $this->parsedData = $parsedData;
        $this->fd         = $fd;
        $this->server     = $server;

        throw new SampleCustomException('This is a test custom exception!');
    }
}
