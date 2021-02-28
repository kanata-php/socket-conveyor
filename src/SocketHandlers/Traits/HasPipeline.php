<?php

namespace Conveyor\SocketHandlers\Traits;

use Exception;
use League\Pipeline\Pipeline;
use League\Pipeline\PipelineBuilder;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SocketHandlers\Abstractions\SocketHandler;
use Conveyor\SocketHandlers\Interfaces\ExceptionHandlerInterface;

trait HasPipeline
{
    /** @var array */
    protected $pipelineMap = [];

    /** @var array */
    protected $handlerMap = [];

    /** @var null|ExceptionHandlerInterface */
    protected $exceptionHandler = null;

    /**
     * @param string   $data   Data to be processed.
     * @param int|null $fd     File descriptor (connection).
     * @param mixed    $server Server object, e.g. Swoole\WebSocket\Frame.
     *
     * @throws Exception
     */
    public function handle(string $data, $fd = null, $server = null)
    {
        /** @var ActionInterface */
        $action = $this->parseData($data);

        /** @var Pipeline */
        $pipeline = $this->getPipeline($action->getName());

        try {
            /** @throws Exception */
            $pipeline->process($this);
        } catch (Exception $e) {
            $this->processException($e);
            throw $e;
        }

        $this->maybeSetFd($fd);
        $this->maybeSetServer($server);

        return $action->execute($this->parsedData, $fd, $server);
    }

    /**
     * @return array
     */
    public function getParsedData() : array
    {
        return $this->parsedData;
    }

    /**
     * Prepare pipeline based on the current prepared handlers.
     *
     * @param string $action
     *
     * @return Pipeline
     */
    public function getPipeline(string $action) : Pipeline
    {
        $pipelineBuilder = new PipelineBuilder;

        if (!isset($this->pipelineMap[$action])) {
            return $pipelineBuilder->build();
        }

        foreach ($this->pipelineMap[$action] as $actionName => $middleware) {
            $pipelineBuilder->add($middleware);
        }

        return $pipelineBuilder->build();
    }

    /**
     * Add an action to be handled. It returns a pipeline for
     * eventual middlewares to be added for each.
     *
     * @param ActionInterface $actionHandler
     *
     * @return SocketHandler
     */
    public function add(ActionInterface $actionHandler) : SocketHandler
    {
        $actionName = $actionHandler->getName();
        $this->handlerMap[$actionName] = $actionHandler;
        
        return $this;
    }

    /**
     * Add a step for the current's action middleware.
     *
     * @param string $action
     * @param Callable $middleware
     *
     * @return void
     */
    public function middleware(string $action, callable $middleware) : void
    {
        if (!isset($this->pipelineMap[$action])) {
            $this->pipelineMap[$action] = [];
        }

        $this->pipelineMap[$action][] = $middleware;
    }

    /**
     * Get an Action by name
     *
     * @param string $name
     *
     * @return ActionInterface|null
     */
    public function getAction(string $name)
    {
        return $this->handlerMap[$name];
    }

    /**
     * Add a Middleware Exception Handler.
     * This handler does some custom processing in case
     * of an exception.
     *
     * @param ExceptionHandlerInterface $handler
     *
     * @return void
     */
    public function addMiddlewareExceptionHandler(ExceptionHandlerInterface $handler): void
    {
        $this->exceptionHandler = $handler;
    }

    /**
     * Process a registered exception.
     *
     * @param Exception $e
     *
     * @return void
     *
     * @throws Exception
     */
    public function processException(Exception $e): void
    {
        if (null !== $this->exceptionHandler) {
            $this->exceptionHandler->handle($e, $this->parsedData, $this->server);
        }
    }
}
