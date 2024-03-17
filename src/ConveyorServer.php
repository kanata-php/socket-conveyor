<?php

namespace Conveyor;

use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Events\PreServerStartEvent;
use Conveyor\Events\ServerStartedEvent;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Traits\HasHandlers;
use Exception;
use Conveyor\Constants as ConveyorConstants;
use OpenSwoole\Constant;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;
use OpenSwoole\Server as OpenSwooleBaseServer;
use OpenSwoole\Coroutine as Co;

class ConveyorServer
{
    use HasHandlers;

    /**
     * Conveyor Parts
     */

    protected Server $server;
    protected ConveyorWorker $conveyorWorker;
    protected ConveyorLock $conveyorLock;
    protected ?ConveyorTick $conveyorTick = null;
    protected EventDispatcher $eventDispatcher;

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
        $this->conveyorOptions = array_merge(Constants::DEFAULT_OPTIONS, $this->conveyorOptions);

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
            'websocket_subprotocol' => 'socketconveyor.com',
        ], $this->serverOptions));
    }

    private function startServerTick(): void
    {
        if (!$this->conveyorOptions[ConveyorConstants::TIMER_TICK]) {
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

        // Reference: https://openswoole.com/docs/modules/swoole-websocket-server-on-handshake
        $this->server->on('handshake', function (Request $request, Response $response) {
            $secWebSocketKey = $request->header['sec-websocket-key'];
            $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

            // Websocket handshake connection algorithm verification
            if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
                $response->end();
                return false;
            }

            $key = base64_encode(sha1(
                $request->header['sec-websocket-key']
                . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
                true
            ));

            $headers = [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $key,
                'Sec-WebSocket-Version' => '13',
            ];

            // Response must not include 'Sec-WebSocket-Protocol' header if not present in request: websocket
            if (isset($request->header['sec-websocket-protocol'])) {
                $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
            }

    private function startServer(): void
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

        // TODO: consider adding the close event

        $this->eventDispatcher->dispatch(
            event: new PreServerStartEvent($this->server),
            eventName: ConveyorConstants::EVENT_PRE_SERVER_START,
        );

        $this->server->start();
    }
}
