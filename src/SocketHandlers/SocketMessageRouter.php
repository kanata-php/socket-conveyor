<?php

namespace Conveyor\SocketHandlers;

use Conveyor\Actions\AddListenerAction;
use Conveyor\Actions\BaseAction;
use Conveyor\Actions\ChannelConnectAction;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\Actions\Traits\HasPersistence;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\SocketHandlers\Interfaces\ExceptionHandlerInterface;
use Conveyor\SocketHandlers\Interfaces\GenericPersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use Exception;
use InvalidArgumentException;
use League\Pipeline\Pipeline;
use League\Pipeline\PipelineBuilder;

class SocketMessageRouter implements SocketHandlerInterface
{
    use HasPersistence;

    protected array $pipelineMap = [];
    protected array $handlerMap = [];
    protected null|ExceptionHandlerInterface $exceptionHandler = null;
    protected mixed $server = null;
    protected ?int $fd = null;
    protected mixed $parsedData;

    /**
     * @param null|array|GenericPersistenceInterface $persistence
     * @param array $actions
     * @throws Exception
     */
    public function __construct(
        null|array|GenericPersistenceInterface $persistence = null,
        protected array $actions = []
    ) {
        $this->preparePersistence($persistence);
        $this->startActions();
    }

    private function preparePersistence(null|array|GenericPersistenceInterface $persistence)
    {
        if (null === $persistence) {
            return;
        }

        if (!is_array($persistence)) {
            $this->setPersistence($persistence);
            return;
        }

        foreach ($persistence as $item) {
            $this->setPersistence($item);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function startActions()
    {
        $this->add(new ChannelConnectAction);
        $this->add(new AddListenerAction);
        $this->add(new BaseAction);

        foreach ($this->actions as $action) {
            if (is_string($action)) {
                $this->add(new $action);
                continue;
            } else if (is_array($action)) {
                $this->startActionWithMiddlewares($action);
                continue;
            }
            throw new Exception('Not valid action: ' . json_encode($action));
        }
    }

    /**
     * @param array $action
     * @return void
     * @throws Exception
     */
    protected function startActionWithMiddlewares(array $action)
    {
        $actionInstance = new $action[0];
        $this->add($actionInstance);
        for ($i = 1; $i < count($action); $i++) {
            $this->middleware($actionInstance->getName(), $action[$i]);
        }
    }

    /**
     * @param string $data Data to be processed.
     * @param int $fd File descriptor (connection).
     * @param mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     */
    public function __invoke(string $data, int $fd, mixed $server)
    {
        return $this->handle($data, $fd, $server);
    }

    public function cleanListeners(int $fd): void
    {
        if (null !== $this->persistence) {
            $this->persistence->stopListenersForFd($fd);
        } elseif (null !== $this->listenerPersistence) {
            $this->listenerPersistence->stopListenersForFd($fd);
        }
    }

    /**
     * This is a health check for connections to channels. Here we remove not necessary connections.
     *
     * @return void
     */
    public function closeConnections()
    {
        if (
            !isset($this->server->connections)
            || (
                null === $this->persistence
                && null === $this->channelPersistence
            )
        ) {
            return;
        }

        if (null !== $this->persistence) {
            $registeredConnections = $this->persistence->getAllConnections();
        } elseif (null !== $this->channelPersistence) {
            $registeredConnections = $this->channelPersistence->getAllConnections();
        } else {
            return;
        }

        $existingConnections = [];
        foreach ($this->server->connections as $connection) {
            if ($this->server->isEstablished($connection)) {
                $existingConnections[] = $connection;
            }
        }

        $closedConnections = array_filter(
            array_keys($registeredConnections),
            fn($item) => !in_array($item, $existingConnections)
        );

        foreach ($closedConnections as $connection) {
            if (null !== $this->persistence) {
                $this->persistence->disconnect($connection);
            } elseif (null !== $this->channelPersistence) {
                $this->channelPersistence->disconnect($connection);
            }
        }
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

    /**
     * @param string $data Data to be processed.
     * @param int $fd File descriptor (connection).
     * @param mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     *
     * @throws Exception
     */
    public function handle(string $data, int $fd, mixed $server)
    {
        $this->cleanListeners($fd);

        /** @var ActionInterface */
        $action = $this->parseData($data);

        /** @var Pipeline */
        $pipeline = $this->getPipeline($action->getName());

        $this->setFd($action, $fd);
        $this->setServer($action, $server);
        $this->registerActionPersistence($action);
        $this->closeConnections();

        // TODO: test pipeline
        try {
            /** @throws Exception */
            $pipeline->process($this);
        } catch (Exception $e) {
            $this->processException($e);
            throw $e;
        }

        return $action->execute($this->parsedData);
    }

    /**
     * @internal This method also leave the $parsedData property set to the instance.
     *
     * @param string $data
     * @return ActionInterface
     *
     * @throws InvalidArgumentException|InvalidActionException|Exception
     */
    public function parseData(string $data) : ActionInterface
    {
        $this->parsedData = json_decode($data, true);

        // @throws InvalidArgumentException|InvalidActionException
        $this->validateData($this->parsedData);

        return $this->getAction($this->parsedData['action']);
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

        foreach ($this->pipelineMap[$action] as $middleware) {
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
     * @return SocketMessageRouter
     */
    public function add(ActionInterface $actionHandler) : SocketMessageRouter
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
        $this->exceptionHandler?->handle($e, $this->parsedData, $this->fd, $this->server);
    }

    /**
     * Set $fd (File descriptor) if method "setFd" exists.
     *
     * @param int $fd File descriptor.
     *
     * @return void
     */
    public function setFd(ActionInterface $action, int $fd): void
    {
        $this->fd = $fd;
        $action->setFd($fd);
    }

    /**
     * @return int $fd File descriptor.
     */
    public function getFd(): int
    {
        return $this->fd;
    }

    /**
     * Set $server if method "setServer" exists.
     *
     * @param mixed $server Server object, e.g. Swoole\WebSocket\Server.
     *
     * @return void
     */
    public function setServer(ActionInterface $action, $server): void
    {
        $this->server = $server;
        $action->setServer($server);
    }

    private function registerActionPersistence(ActionInterface $action)
    {
        if (null !== $this->persistence) {
            $this->setActionPersistence($action, $this->persistence);
        }

        if (null !== $this->channelPersistence) {
            $this->setActionPersistence($action, $this->channelPersistence);
        }

        if (null !== $this->userAssocPersistence) {
            $this->setActionPersistence($action, $this->userAssocPersistence);
        }

        if (null !== $this->listenerPersistence) {
            $this->setActionPersistence($action, $this->listenerPersistence);
        }
    }

    /**
     * Set persistence to the action instance.
     *
     * @param ActionInterface $action
     * @param GenericPersistenceInterface $persistence
     * @return void
     */
    public function setActionPersistence(ActionInterface $action, GenericPersistenceInterface $persistence): void
    {
        if (method_exists($action, 'setPersistence')) {
            $action->setPersistence($persistence);
        }
    }

    /**
     * @return mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     */
    public function getServer(): mixed
    {
        return $this->server;
    }
}
