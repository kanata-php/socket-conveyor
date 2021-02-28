<?php

namespace Conveyor\SocketHandlers;

use Exception;
use InvalidArgumentException;
use League\Pipeline\Pipeline;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SocketHandlers\Abstractions\SocketHandler;
use Conveyor\SocketHandlers\Traits\HasPipeline;

class SocketMessageRouter extends SocketHandler
{
    use HasPipeline;

    /** @var mixed */
    protected $server = null;

    /** @var array */
    protected $parsedData;

    /**
     * @internal This method also leave the $parsedData property set to the instance.
     *
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

        return $this->getAction($this->parsedData['action']);
    }

    /**
     * @param array $data
     *
     * @return void
     *
     * @throws InvalidArgumentException|InvalidActionException
     */
    final public function validateData(array $data) : void
    {
        if (!isset($data['action'])) {
            throw new InvalidArgumentException('Missing action key in data!');
        }

        if (!isset($this->handlerMap[$data['action']])) {
            throw new InvalidActionException('Invalid Action! This action (' . $data['action'] . ') is not set.');
        }
    }
}
