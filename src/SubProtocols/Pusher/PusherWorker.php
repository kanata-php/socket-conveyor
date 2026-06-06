<?php

namespace Conveyor\SubProtocols\Pusher;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;
use Conveyor\Events\ConnectionCloseEvent;
use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Events\RequestReceivedEvent;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Pusher-protocol counterpart to {@see \Conveyor\SubProtocols\Conveyor\ConveyorWorker}.
 *
 * Registers the same task/close listeners but routes frames through the Pusher
 * event router instead of the Conveyor action pipeline.
 */
class PusherWorker
{
    private PusherEventRouter $router;

    private PusherRestController $restController;

    /**
     * @param array<array-key, GenericPersistenceInterface> $persistence
     */
    public function __construct(
        protected Server $server,
        protected ConveyorOptions $conveyorOptions,
        protected EventDispatcher $eventDispatcher,
        protected array $persistence,
        protected SocketIdRepository $socketIdRepository,
    ) {
        $appManager = new AppManager($this->conveyorOptions);

        $this->router = new PusherEventRouter(
            server: $this->server,
            persistence: $this->persistence,
            socketIds: $this->socketIdRepository,
            appManager: $appManager,
        );
        $this->restController = new PusherRestController($this->router, $appManager);

        $this->addListeners();
    }

    public function getEventRouter(): PusherEventRouter
    {
        return $this->router;
    }

    private function addListeners(): void
    {
        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_MESSAGE_RECEIVED,
            listener: function (MessageReceivedEvent $event) {
                if (empty($event->data)) {
                    return;
                }
                $this->processMessage($event->data);
            }
        );

        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_REQUEST_RECEIVED,
            listener: fn(RequestReceivedEvent $event)
                => $this->restController->handle($event->request, $event->response),
        );

        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_SERVER_CLOSE,
            listener: fn(ConnectionCloseEvent $event) => $this->router->handleClose($event->fd),
        );
    }

    private function processMessage(string $data): void
    {
        $parsedFrame = json_decode($data, true);

        if (!is_array($parsedFrame) || !isset($parsedFrame['fd'], $parsedFrame['data'])) {
            return;
        }

        $this->router->handle((int) $parsedFrame['fd'], (string) $parsedFrame['data']);
    }
}
