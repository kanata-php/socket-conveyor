<?php

namespace Conveyor\SocketHandlers;

use Conveyor\Actions\ActionManager;
use Conveyor\Actions\BaseAction;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\Actions\Traits\HasPersistence;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\Helpers\Arr;
use Conveyor\Models\Interfaces\GenericPersistenceInterface;
use Conveyor\Models\SocketChannelPersistenceTable;
use Conveyor\Models\SocketListenerPersistenceTable;
use Conveyor\Models\SocketUserAssocPersistenceTable;
use Conveyor\SocketHandlers\Interfaces\ExceptionHandlerInterface;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use Exception;
use InvalidArgumentException;
use League\Pipeline\Pipeline;

class SocketMessageRouter implements SocketHandlerInterface
{
    use HasPersistence;

    protected null|ExceptionHandlerInterface $exceptionHandler = null;
    protected mixed $server = null;
    protected ?int $fd = null;
    protected mixed $parsedData;
    protected ActionManager $actionManager;

    /**
     * @param null|array|GenericPersistenceInterface $persistence
     * @param array $actions
     * @throws Exception
     */
    public function __construct(
        null|array|GenericPersistenceInterface $persistence = null,
        array $actions = [],
        protected bool $fresh = false,
    ) {
        $this->preparePersistence($persistence);
        $this->actionManager = ActionManager::make($actions, $fresh);
    }

    /**
     * @param string $data
     * @param int $fd
     * @param mixed $server
     * @param array $options Constructor options: $persistence, $actions, $fresh
     * @return mixed
     * @throws Exception
     */
    public static function run(
        string $data,
        int $fd,
        mixed $server,
        array $options = [],
    ) {
        return (new self(
            persistence: $options['persistence'] ?? null,
            actions: $options['actions'] ?? [],
            fresh: $options['fresh'] ?? false,
        ))(
            data: $data,
            fd: $fd,
            server: $server,
        );
    }

    /**
     * This is used to refresh persistence.
     *
     * @throws Exception
     */
    public static function refresh(
        null|array|GenericPersistenceInterface $persistence = null,
    ) {
        new self(persistence: $persistence, fresh: true);
    }

    private function preparePersistence(null|array|GenericPersistenceInterface $persistence)
    {
        if (null === $persistence) {
            $persistence = [
                new SocketChannelPersistenceTable,
                new SocketListenerPersistenceTable,
                new SocketUserAssocPersistenceTable,
            ];
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
     * @param string $data Data to be processed.
     * @param int $fd Sender's File descriptor (connection).
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
            || null === $this->channelPersistence
        ) {
            return;
        }

        if (null !== $this->channelPersistence) {
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
            fn ($item) => !in_array($item, $existingConnections)
        );

        foreach ($closedConnections as $connection) {
            if (null !== $this->channelPersistence) {
                $this->channelPersistence->disconnect($connection);
            }
        }
    }

    /**
     * @param ?array $data
     *
     * @return void
     *
     * @throws InvalidArgumentException|InvalidActionException
     */
    final public function validateData(?array $data): void
    {
        if (null === $data) {
            return; // base action
        }

        if (!isset($data['action'])) {
            throw new InvalidArgumentException('Missing action key in data!');
        }

        if (!$this->actionManager->hasAction($data['action'])) {
            throw new InvalidActionException(
                'Invalid Action! This action (' . $data['action'] . ') is not set.'
            );
        }
    }

    /**
     * @param string $data Data to be processed.
     * @param int $fd Sender's File descriptor (connection).
     * @param mixed $server Server object, e.g. \OpenSwoole\WebSocket\Frame.
     *
     * @throws Exception
     */
    public function handle(string $data, int $fd, mixed $server)
    {
        $this->fd = $fd;
        $this->server = $server;

        /** @var ActionInterface */
        $action = $this->parseData($data);
        $action->setFd($fd);
        $action->setServer($server);

        /** @var Pipeline */
        $pipeline = $this->actionManager->getPipeline($action->getName());

        $this->registerActionPersistence($action);
        $this->closeConnections();

        try {
            /** @throws Exception */
            $pipeline->process($this);
        } catch (Exception $e) {
            $this->processException($e);
            throw $e;
        }

        return $action($this->parsedData);
    }

    /**
     * @internal This method also leave the $parsedData property set to the instance.
     *
     * @param string $data
     * @return ActionInterface
     *
     * @throws InvalidArgumentException|InvalidActionException|Exception
     */
    public function parseData(string $data): ActionInterface
    {
        $this->parsedData = json_decode($data, true);

        if (null === $this->parsedData) {
            $this->parsedData = [
                'action' => BaseAction::ACTION_NAME,
                'data' => $data,
            ];
        }

        // @throws InvalidArgumentException|InvalidActionException
        $this->validateData($this->parsedData);

        return $this->actionManager->getAction($this->parsedData['action']);
    }

    /**
     * @param ?string $path Path in array using dot notation.
     * @return mixed
     */
    public function getParsedData(?string $path = null): mixed
    {
        if (null === $path) {
            return $this->parsedData;
        }

        return Arr::get($this->parsedData, $path);
    }

    /**
     * Add a Middleware Exception Handler.
     * This handler does some custom processing in case
     * of an exception.
     *
     * @param ExceptionHandlerInterface $handler
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
     * @return void
     * @throws Exception
     */
    public function processException(Exception $e): void
    {
        $this->exceptionHandler?->handle($e, $this->parsedData, $this->fd, $this->server);
    }

    /**
     * @return int $fd File descriptor.
     */
    public function getFd(): int
    {
        return $this->fd;
    }


    private function registerActionPersistence(ActionInterface $action): void
    {
        if (null !== $this->channelPersistence) {
            $this->actionManager->setActionPersistence($action, $this->channelPersistence);
        }

        if (null !== $this->userAssocPersistence) {
            $this->actionManager->setActionPersistence($action, $this->userAssocPersistence);
        }

        if (null !== $this->listenerPersistence) {
            $this->actionManager->setActionPersistence($action, $this->listenerPersistence);
        }
    }

    /**
     * @return mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     */
    public function getServer(): mixed
    {
        return $this->server;
    }

    public function getActionManager(): ActionManager
    {
        return $this->actionManager;
    }
}
