<?php

namespace Conveyor;

use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Events\PreServerStartEvent;
use Conveyor\Events\ServerStartedEvent;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Exception;
use OpenSwoole\Constant;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;
use OpenSwoole\Server as OpenSwooleBaseServer;

class ConveyorServer
{
    /**
     * Conveyor options
     */

    public const TIMER_TICK = 'timer';

    /**
     * Conveyor Parts
     */

    protected Server $server;
    protected ConveyorWorker $conveyorWorker;
    protected ConveyorLock $conveyorLock;
    protected ?ConveyorTick $conveyorTick = null;
    protected EventDispatcher $eventDispatcher;

    // Events

    public const EVENT_PRE_SERVER_START = 'conveyor.pre_server_start';
    public const EVENT_SERVER_STARTED = 'conveyor.server_started';
    public const EVENT_PRE_SERVER_RELOAD = 'conveyor.pre_server_reload';
    public const EVENT_POST_SERVER_RELOAD = 'conveyor.post_server_reload';
    public const EVENT_MESSAGE_RECEIVED = 'conveyor.message_received';
    public const EVENT_BEFORE_MESSAGE_HANDLED = 'conveyor.before_message_handled';
    public const EVENT_AFTER_MESSAGE_HANDLED = 'conveyor.after_message_handled';

    /**
     * Reference for Server Options:
     * https://openswoole.com/docs/modules/swoole-server/configuration
     *
     * @param string $host
     * @param int $port
     * @param int $mode
     * @param int $ssl
     * @param int $workers
     * @param array<array-key, mixed> $serverOptions
     * @param array<array-key, mixed> $conveyorOptions
     * @param array<array-key, callable> $eventListeners
     * @param array<array-key, GenericPersistenceInterface> $persistence
     * @throws Exception
     */
    public function __construct(
        protected string $host = '0.0.0.0',
        protected int $port = 8989,
        protected int $mode = OpenSwooleBaseServer::POOL_MODE,
        protected int $ssl = Constant::SOCK_TCP,
        protected int $workers = 10,
        protected array $serverOptions = [],
        protected array $conveyorOptions = [],
        protected array $eventListeners = [],
        protected array $persistence = [],
    ) {
        $this->conveyorOptions = array_merge([
            self::TIMER_TICK => false,
        ], $this->conveyorOptions);

        $this->persistence = array_merge(
            $persistence,
            Conveyor::defaultPersistence(),
        );
        Conveyor::refresh($this->persistence);

        $this->startListener();

        $this->startLock();

        $this->initializeServer();

        $this->startServerTick();

        $this->startWorker();

        $this->startServer();
    }

    /**
     * @param string $host
     * @param int $port
     * @param int $mode
     * @param int $ssl
     * @param int $workers
     * @param array<array-key, mixed> $serverOptions
     * @param array<array-key, mixed> $conveyorOptions
     * @param array<array-key, callable> $eventListeners
     * @param array<array-key, GenericPersistenceInterface> $persistence
     * @return ConveyorServer
     * @throws Exception
     */
    public static function start(
        string $host = '0.0.0.0',
        int $port = 8989,
        int $mode = OpenSwooleBaseServer::POOL_MODE,
        int $ssl = Constant::SOCK_TCP,
        int $workers = 10,
        array $serverOptions = [],
        array $conveyorOptions = [],
        array $eventListeners = [],
        array $persistence = [],
    ): ConveyorServer {
        return new self(
            host: $host,
            port: $port,
            mode: $mode,
            ssl: $ssl,
            workers: $workers,
            serverOptions: $serverOptions,
            conveyorOptions: $conveyorOptions,
            eventListeners: $eventListeners,
            persistence: $persistence,
        );
    }

    private function startListener(): void
    {
        $this->eventDispatcher = new EventDispatcher();

        foreach ($this->eventListeners as $eventName => $eventListener) {
            $this->eventDispatcher->addListener($eventName, $eventListener);
        }
    }

    private function startLock(): void
    {
        $this->conveyorLock = new ConveyorLock(
            eventDispatcher: $this->eventDispatcher,
        );
    }

    private function initializeServer(): void
    {
        $this->server = new Server($this->host, $this->port, $this->mode, $this->ssl);

        $this->server->set(array_merge([
            'worker_num' => 5,
            'heartbeat_idle_time' => 10,
            'heartbeat_check_interval' => 10,
            'websocket_subprotocol' => 'socketconveyor.com',
        ], $this->serverOptions));
    }

    private function startServerTick(): void
    {
        if (!$this->conveyorOptions[self::TIMER_TICK]) {
            return;
        }

        $this->conveyorTick = new ConveyorTick(
            server: $this->server,
            conveyorLock: $this->conveyorLock,
            eventDispatcher: $this->eventDispatcher,
            interval: 1000,
        );
    }

    private function startWorker(): void
    {
        $this->conveyorWorker = new ConveyorWorker(
            server: $this->server,
            workers: $this->workers,
            conveyorOptions: $this->conveyorOptions,
            eventDispatcher: $this->eventDispatcher,
            persistence: $this->persistence,
        );
    }

    private function startServer(): void
    {
        $this->server->on('start', fn(Server $server) => $this->onServerStart($server));

        $this->server->on('message', fn(Server $server, Frame $frame) => $this->onMessage(
            server: $server,
            frame: $frame,
        ));

        $this->eventDispatcher->dispatch(
            event: new PreServerStartEvent($this->server),
            eventName: self::EVENT_PRE_SERVER_START,
        );

        $this->server->start();
    }

    private function onServerStart(Server $server): void
    {
        $this->eventDispatcher->dispatch(
            event: new ServerStartedEvent($server),
            eventName: self::EVENT_SERVER_STARTED,
        );
    }

    private function onMessage(Server $server, Frame $frame): void
    {
        $this->eventDispatcher->dispatch(
            event: new MessageReceivedEvent($this->server, json_encode([
                'data' => $frame->data,
                'fd' => $frame->fd,
            ])),
            eventName: ConveyorServer::EVENT_MESSAGE_RECEIVED,
        );
    }
}