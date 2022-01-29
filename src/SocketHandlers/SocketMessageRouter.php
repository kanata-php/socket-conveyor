<?php

namespace Conveyor\SocketHandlers;

use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\Actions\Interfaces\PersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\ExceptionHandlerInterface;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use Exception;
use InvalidArgumentException;
use Conveyor\Exceptions\InvalidActionException;
use League\Pipeline\Pipeline;
use League\Pipeline\PipelineBuilder;

class SocketMessageRouter implements SocketHandlerInterface
{
    /**
     * @var array Format: [$fd => $channelName, ...]
     */
    protected array $channels = [];

    protected array $pipelineMap = [];
    protected array $handlerMap = [];
    protected null|ExceptionHandlerInterface $exceptionHandler = null;
    protected mixed $server = null;
    protected ?int $fd = null;
    protected mixed $parsedData;

    public function __construct(
        protected ?PersistenceInterface $persistence = null
    ) {
        $this->loadChannels();
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

    /**
     * @return void
     */
    public function loadChannels(): void
    {
        if (null === $this->persistence) {
            return;
        }

        $this->channels = $this->persistence->getAllConnections();
    }

    public function connectFdToChannel(array $data): void
    {
        if (!isset($data['channel'])) {
            throw new Exception('Channel not present!');
        }

        if (null === $this->fd) {
            throw new Exception('FD not specified!');
        }

        $channel = $data['channel'];

        $this->channels[$this->fd] = $channel;

        $this->persistence->connect($this->fd, $channel);
    }

    /**
     * Find channel for current FD.
     *
     * @param int $fd
     * @return string|null
     */
    public function matchChannel(int $fd): string|null
    {
        if (!isset($this->channels[$fd])) {
            return null;
        }

        return $this->channels[$fd];
    }

    /**
     * @param array $data
     * @return void
     */
    public function validateChannelConnectionAction(array $data): void
    {
        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel connection must specify "channel"!');
        }
    }

    public function maybeSetChannel(ActionInterface $action, int $fd): void
    {
        $channel = $this->matchChannel($fd);

        if (null === $channel) {
            return;
        }

        $action->setChannels(array_filter(
            $this->channels,
            fn($item) => $item === $channel
        ));
    }

    public function closeConnections()
    {
        if (!isset($this->server->connections)) {
            return;
        }

        $registeredConnections = $this->persistence->getAllConnections();

        $existingConnections = [];
        foreach ($this->server->connections as $connection) {
            $existingConnections[] = $connection;
        }

        $closedConnections = array_filter(array_keys(
            $registeredConnections),
            fn($item) => !in_array($item, $existingConnections)
        );

        foreach ($closedConnections as $connection) {
            $this->persistence->disconnect($connection);
        }

        $this->channels = $registeredConnections;
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
        /** @var ActionInterface */
        $action = $this->parseData($data);

        /** @var Pipeline */
        $pipeline = $this->getPipeline($action->getName());

        $this->maybeSetFd($action, $fd);
        $this->maybeSetServer($action, $server);
        $this->maybeSetChannel($action, $fd);
        $this->closeConnections();

        try {
            /** @throws Exception */
            $pipeline->process($this);
        } catch (Exception $e) {
            $this->processException($e);
            throw $e;
        }

        $this->handleChannelConnection();

        return $action->execute($this->parsedData);
    }

    private function handleChannelConnection()
    {
        if (
            $this->parsedData['action'] === 'channel-connection'
            && method_exists($this, 'validateChannelConnectionAction')
        ) {
            // @throws InvalidArgumentException
            $this->validateChannelConnectionAction($this->parsedData);
            // @throws Exception
            $this->connectFdToChannel($this->parsedData);
        }
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
    public function maybeSetFd(ActionInterface $action, int $fd): void
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
    public function maybeSetServer(ActionInterface $action, $server): void
    {
        $this->server = $server;
        $action->setServer($server);
    }

    /**
     * @return mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     */
    public function getServer(): mixed
    {
        return $this->server;
    }
}
