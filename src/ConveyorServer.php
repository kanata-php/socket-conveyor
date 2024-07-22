<?php

namespace Conveyor;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants as ConveyorConstants;
use Conveyor\Events\PreServerStartEvent;
use Conveyor\Interfaces\ConveyorServerInterface;
use Conveyor\SubProtocols\Conveyor\Conveyor;
use Conveyor\SubProtocols\Conveyor\ConveyorWorker;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Traits\HasHandlers;
use Exception;
use OpenSwoole\Constant;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Server as OpenSwooleBaseServer;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConveyorServer implements ConveyorServerInterface
{
    use HasHandlers;

    /**
     * Conveyor Parts
     */

    protected Server $server;
    protected EventDispatcher $eventDispatcher;

    /**
     * Reference for Server Options:
     * https://openswoole.com/docs/modules/swoole-server/configuration
     *
     * @param string $host
     * @param int $port
     * @param int $mode
     * @param int $ssl
     * @param array<array-key, mixed> $serverOptions
     * @param ConveyorOptions|array<array-key, mixed> $conveyorOptions
     * @param array<array-key, callable> $eventListeners
     * @param array<array-key, GenericPersistenceInterface> $persistence
     * @throws Exception
     */
    public function __construct(
        protected string $host = '0.0.0.0',
        protected int $port = 8989,
        protected int $mode = OpenSwooleBaseServer::POOL_MODE,
        protected int $ssl = Constant::SOCK_TCP,
        protected array $serverOptions = [],
        protected array|ConveyorOptions $conveyorOptions = [],
        protected array $eventListeners = [],
        protected array $persistence = [],
    ) {
        if (is_array($this->conveyorOptions)) {
            $this->conveyorOptions = ConveyorOptions::fromArray(array_merge(
                Constants::DEFAULT_OPTIONS,
                $this->conveyorOptions,
            ));
        }

        $this->startPersistence($persistence);

        $this->startListener();

        $this->initializeServer();

        $this->startWorker();

        $this->startServer();
    }

    /**
     * @param string $host
     * @param int $port
     * @param int $mode
     * @param int $ssl
     * @param array<array-key, mixed> $serverOptions
     * @param ConveyorOptions|array<array-key, mixed> $conveyorOptions
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
        array $serverOptions = [],
        ConveyorOptions|array $conveyorOptions = [],
        array $eventListeners = [],
        array $persistence = [],
    ): ConveyorServer {
        return new self(
            host: $host,
            port: $port,
            mode: $mode,
            ssl: $ssl,
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

    /**
     * @param array<array-key, GenericPersistenceInterface> $persistence
     */
    private function startPersistence(array $persistence): void
    {
        $this->persistence = array_merge(
            Conveyor::defaultPersistence(),
            $persistence,
        );
        Conveyor::refresh($this->persistence);
    }

    private function initializeServer(): void
    {
        $this->server = new Server($this->host, $this->port, $this->mode, $this->ssl);

        $this->server->set(array_merge([
            'worker_num' => 10,
            'task_worker_num' => 10,
            'task_ipc_mode' => 3,
        ], $this->serverOptions));
    }

    private function startWorker(): void
    {
        // @phpstan-ignore-next-line
        $selectedSubProtocol = $this->conveyorOptions->{ConveyorConstants::WEBSOCKET_SUBPROTOCOL};

        if (ConveyorConstants::SOCKET_CONVEYOR === $selectedSubProtocol) {
            new ConveyorWorker(
                server: $this->server,
                conveyorOptions: $this->conveyorOptions,
                eventDispatcher: $this->eventDispatcher,
                persistence: $this->persistence,
            );
            return;
        }

        throw new Exception('Invalid WebSocket SubProtocol: ' . $selectedSubProtocol . '!');
    }

    protected function startServer(): void
    {
        $this->server->on('start', fn(Server $server) => $this->onServerStart($server));

        // Reference: https://openswoole.com/docs/modules/swoole-websocket-server-on-handshake
        $this->server->on('handshake', function (Request $request, Response $response) {
            $this->onHandshake($request, $response);
        });

        $this->server->on('message', fn(Server $server, Frame $frame) => $this->onMessage(
            server: $server,
            frame: $frame,
        ));

        $this->server->on('request', fn(Request $request, Response $response) => $this->onRequest(
            request: $request,
            response: $response,
        ));

        $this->server->on('task', fn(Server $server, int $taskId, int $reactorId, mixed $data) => $this->onTask(
            server: $server,
            taskId: $taskId,
            reactorId: $reactorId,
            data: $data,
        ));

        $this->server->on('finish', fn(Server $server, int $taskId, mixed $data) => $this->onFinish(
            server: $server,
            taskId: $taskId,
            data: $data
        ));

        $this->server->on('close', fn(Server $server, int $fd) => $this->onClose(
            server: $server,
            fd: $fd,
        ));

        $this->eventDispatcher->dispatch(
            event: new PreServerStartEvent($this->server),
            eventName: ConveyorConstants::EVENT_PRE_SERVER_START,
        );

        $this->server->start();
    }
}
