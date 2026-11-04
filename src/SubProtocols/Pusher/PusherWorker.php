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
        protected PusherChannelRepository $channelRepository,
        protected ?PusherAdmission $admission = null,
        protected ?PusherLimits $limits = null,
    ) {
        $appManager = new AppManager($this->conveyorOptions);
        /** @var \Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\PresenceChannelPersistenceInterface $presence */
        $presence = $this->persistence[Constants::PRESENCE_CHANNELS];
        $this->channelRepository->usePresencePersistence($presence);

        $this->router = new PusherEventRouter(
            server: $this->server,
            persistence: $this->persistence,
            socketIds: $this->socketIdRepository,
            channels: $this->channelRepository,
            appManager: $appManager,
            admission: $this->admission,
        );
        $this->restController = new PusherRestController($this->router, $appManager, $this->admission, $this->limits);

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
            listener: fn(ConnectionCloseEvent $event) => $this->router->handleClose($event->fd, $event->generation),
        );
    }

    private function processMessage(string $data): void
    {
        $parsedFrame = json_decode($data, true, 64);
        if (
            !is_array($parsedFrame)
            || !isset($parsedFrame['fd'], $parsedFrame['data'], $parsedFrame['generation'], $parsedFrame['reservation'])
            || !is_int($parsedFrame['fd']) || !is_string($parsedFrame['data'])
            || !is_string($parsedFrame['generation']) || !is_string($parsedFrame['reservation'])
        ) {
            return;
        }
        $lease = $parsedFrame['reservation'];
        try {
            if (
                $this->socketIdRepository->socketIdFor($parsedFrame['fd']) !== $parsedFrame['generation']
                || ($this->admission !== null && !$this->admission->claim($lease, $parsedFrame['generation']))
            ) {
                return;
            }
            $this->router->handle($parsedFrame['fd'], $parsedFrame['data'], $parsedFrame['generation']);
        } catch (\Throwable $error) {
            PusherEventRouter::diagnostic($error);
        } finally {
            $this->admission?->release($lease);
        }
    }
}
