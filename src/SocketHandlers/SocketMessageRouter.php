<?php

namespace Conveyor\SocketHandlers;

use Conveyor\Actions\ActionManager;
use Conveyor\Actions\BaseAction;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\Actions\Traits\HasPersistence;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\Helpers\Arr;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Persistence\WebSockets\AssociationsPersistence;
use Conveyor\Persistence\WebSockets\ChannelsPersistence;
use Conveyor\Persistence\WebSockets\ListenersPersistence;
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
    protected ?ActionManager $actionManager = null;

    /**
     * @param null|array|GenericPersistenceInterface $persistence
     * @param array $actions
     * @throws Exception
     */
    public function __construct(
        protected bool $fresh = false,
    ) {}

    public static function init(bool $fresh = false): static
    {
        return new self($fresh);
    }

    public function actions(array $actions = [], bool $fresh = false)
    {
        $this->actionManager = ActionManager::make($actions, $fresh);
        return $this;
    }

    /**
     * @param string $data
     * @param int $fd
     * @param mixed $server
     * @return mixed
     * @throws Exception
     */
    public function run(string $data, int $fd, mixed $server): mixed
    {
        return $this(
            data: $data,
            fd: $fd,
            server: $server,
        );
    }

    /**
     * This is used to refresh persistence.
     *
     * @param null|array|GenericPersistenceInterface $persistence
     *
     * @return static
     *
     * @throws Exception
     */
    public static function refresh(
        null|array|GenericPersistenceInterface $persistence = null,
    ): static {
        return self::init(true)
            ->persistence($persistence);
    }

    /**
     * @param array|GenericPersistenceInterface|null $persistence
     *
     * @return self
     */
    public function persistence(null|array|GenericPersistenceInterface $persistence): self
    {
        if (null === $persistence) {
            $persistence = [
                new ChannelsPersistence,
                new ListenersPersistence,
                new AssociationsPersistence,
            ];
        }

        if (!is_array($persistence)) {
            $this->setPersistence($persistence);
            return $this;
        }

        foreach ($persistence as $item) {
            $this->setPersistence($item);
        }

        return $this;
    }

    /**
     * @param string $data Data to be processed.
     * @param int $fd Sender's File descriptor (connection).
     * @param mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     * @return mixed
     * @throws Exception
     */
    public function __invoke(string $data, int $fd, mixed $server): mixed
    {
        return $this->handle($data, $fd, $server);
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

        if (!$this->getActionManager()->hasAction($data['action'])) {
            throw new InvalidActionException(
                'Invalid Action! This action (' . $data['action'] . ') is not set.'
            );
        }
    }

    /**
     * @param string $data Data to be processed.
     * @param int $fd Sender's File descriptor (connection).
     * @param mixed $server Server object, e.g. \OpenSwoole\WebSocket\Frame.
     * @return mixed
     * @throws Exception
     */
    public function handle(string $data, int $fd, mixed $server): mixed
    {
        $this->fd = $fd;
        $this->server = $server;

        /** @var ActionInterface */
        $action = $this->parseData($data);
        $action->setFd($fd);
        $action->setServer($server);
        $this->registerActionPersistence($action);

        /** @var Pipeline */
        $pipeline = $this->getActionManager()->getPipeline($action->getName());

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

        return $this->getActionManager()->getAction($this->parsedData['action']);
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
            $this->getActionManager()->setActionPersistence($action, $this->channelPersistence);
        }

        if (null !== $this->userAssocPersistence) {
            $this->getActionManager()->setActionPersistence($action, $this->userAssocPersistence);
        }

        if (null !== $this->listenerPersistence) {
            $this->getActionManager()->setActionPersistence($action, $this->listenerPersistence);
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
        if (null === $this->actionManager) {
            $this->actions();
        }

        return $this->actionManager;
    }
}
