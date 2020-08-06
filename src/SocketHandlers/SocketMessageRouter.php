<?php

namespace Conveyor\SocketHandlers;

use Exception;
use League\Pipeline\Pipeline;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SocketHandlers\Abstractions\SocketHandler;
use Conveyor\SocketHandlers\Traits\HasPipeline;

class SocketMessageRouter extends SocketHandler
{
    use HasPipeline;

    /**
     * Call this method to get singleton
     *
     * @return SocketMessageRouter
     */
    public static function getInstance(): SocketMessageRouter
    {
        static $instance = null;

        if ($instance === null) {
            $instance = new self;
        }

        return $instance;
    }

    private function __construct()
    {

    }

    /**
     * @param string $data
     * @return ActionInterface
     *
     * @throws InvalidArgumentException|InvalidActionException
     */
    public function parseData(string $data) : ActionInterface
    {
        $this->parsedData = json_decode($data, true);

        // @throws InvalidArgumentException|InvalidActionException
        $this->validateData($this->parsedData);

        return $this->handlerMap[$this->parsedData['action']];
    }
}
